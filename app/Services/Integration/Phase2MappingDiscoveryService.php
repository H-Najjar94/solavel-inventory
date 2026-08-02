<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Stock\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class Phase2MappingDiscoveryService
{
    private const VERSION = 'phase2.v1';

    /** @return array<string,mixed> */
    public function discover(string $organizationMappingUuid): array
    {
        $mapping = $this->validatedOrganizationMapping($organizationMappingUuid);
        $existing = $this->existingMappings($mapping);
        $results = collect();

        foreach ($this->entityDefinitions() as $entityType => $definition) {
            $results->push(...$this->discoverEntity($mapping, $existing, $entityType, $definition));
        }
        $results->push(...$this->discoverUnitConversions($mapping));
        $results->push(...$this->discoverAccountRoles($mapping, $existing));
        $results->push(...$this->discoverTaxes($mapping, $existing));
        $duplicateFinanceTargets = $results
            ->filter(fn (array $row) => $row['classification'] === 'exact_match'
                && count($row['solabooks_record_ids']) === 1)
            ->groupBy(fn (array $row) => $row['entity_type'].'|'.$row['solabooks_record_ids'][0])
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->keys()
            ->flip();
        $results = $results->map(function (array $row) use ($duplicateFinanceTargets): array {
            $targetKey = count($row['solabooks_record_ids']) === 1
                ? $row['entity_type'].'|'.$row['solabooks_record_ids'][0]
                : null;
            if ($row['classification'] === 'exact_match'
                && $targetKey !== null
                && $duplicateFinanceTargets->has($targetKey)) {
                $row['classification'] = 'conflicting_candidates';
                $row['safe_details']['reason'] = 'multiple_solastock_identities_target_one_finance_record';
            }

            return $row;
        });

        $results = $results
            ->sortBy(fn (array $row) => implode('|', [
                $row['entity_type'],
                $row['classification'],
                implode(',', $row['solastock_record_ids']),
                implode(',', $row['solabooks_record_ids']),
            ]))
            ->values()
            ->map(function (array $row): array {
                $row['fingerprint'] = $this->fingerprint($row);

                return $row;
            });

        $beforeImage = $this->beforeImage($mapping, $existing, $results);
        $counts = $results->countBy('classification')->sortKeys()->all();

        return [
            'schema_version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'read_only' => true,
            'organization_mapping_uuid' => $mapping->mapping_uuid,
            'tenant_database_identity' => $mapping->tenant_database_identity,
            'central_client_id' => (int) $mapping->central_client_id,
            'central_organization_id' => (int) $mapping->central_organization_id,
            'finance_organization_id' => (int) $mapping->finance_organization_id,
            'solastock_organization_id' => (int) $mapping->solastock_organization_id,
            'counts' => $counts,
            'before_image_hash' => hash('sha256', $this->canonicalJson($beforeImage)),
            'manifest_hash' => hash('sha256', $this->canonicalJson($results->all())),
            'results' => $results->all(),
        ];
    }

    /** @return array<string,mixed> */
    public function applyDeterministic(
        string $organizationMappingUuid,
        string $approvedManifestHash,
        ?int $actorUserId = null,
    ): array {
        if (! preg_match('/^[a-f0-9]{64}$/', $approvedManifestHash)) {
            $this->fail('approved_manifest_hash_required');
        }
        $this->assertSafetyHolds();

        return DB::connection('tenant')->transaction(function () use ($organizationMappingUuid, $approvedManifestHash, $actorUserId): array {
            $mapping = $this->validatedOrganizationMapping($organizationMappingUuid, true);
            $report = $this->discover($organizationMappingUuid);
            if (! hash_equals($report['manifest_hash'], $approvedManifestHash)) {
                $this->fail('before_image_or_manifest_changed');
            }

            $runUuid = (string) Str::uuid();
            DB::connection('tenant')->table('integration_mapping_discovery_runs')->insert([
                'run_uuid' => $runUuid,
                'organization_mapping_uuid' => $mapping->mapping_uuid,
                'tenant_database_identity' => $mapping->tenant_database_identity,
                'mode' => 'apply',
                'before_image_hash' => $report['before_image_hash'],
                'approved_manifest_hash' => $approvedManifestHash,
                'counts' => json_encode($report['counts']),
                'created_by_user_id' => $actorUserId,
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created = 0;
            $unchanged = 0;
            foreach ($report['results'] as $result) {
                $mappingUuid = null;
                $resolution = 'unresolved';
                if ($result['classification'] === 'exact_match') {
                    [$master, $wasCreated] = $this->upsertExactMapping($mapping, $result, $actorUserId);
                    $mappingUuid = $master->mapping_uuid;
                    $resolution = $wasCreated ? 'mapped' : 'already_mapped';
                    $wasCreated ? $created++ : $unchanged++;
                }

                DB::connection('tenant')->table('integration_mapping_discovery_results')->insert([
                    'run_uuid' => $runUuid,
                    'entity_type' => $result['entity_type'],
                    'classification' => $result['classification'],
                    'solastock_record_ids' => json_encode($result['solastock_record_ids']),
                    'solabooks_record_ids' => json_encode($result['solabooks_record_ids']),
                    'fingerprint' => $result['fingerprint'],
                    'safe_details' => json_encode($result['safe_details']),
                    'resolution_status' => $resolution,
                    'mapping_uuid' => $mappingUuid,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::connection('tenant')->table('integration_mapping_discovery_runs')
                ->where('run_uuid', $runUuid)
                ->update(['completed_at' => now(), 'updated_at' => now()]);

            return [
                'run_uuid' => $runUuid,
                'manifest_hash' => $approvedManifestHash,
                'created_mappings' => $created,
                'unchanged_mappings' => $unchanged,
                'unresolved' => collect($report['results'])->where('classification', '!=', 'exact_match')->count(),
                'counts' => $report['counts'],
            ];
        }, 3);
    }

    private function validatedOrganizationMapping(string $uuid, bool $lock = false): IntegrationOrganizationMapping
    {
        $query = IntegrationOrganizationMapping::query()
            ->where('mapping_uuid', $uuid)
            ->where('tenant_database_identity', (string) DB::connection('tenant')->getDatabaseName())
            ->where('contract_version', 'solastock-journal.v2');
        if ($lock) {
            $query->where('status', 'verified_hold')
                ->where('activation_state', 'maintenance_hold')
                ->lockForUpdate();
        } else {
            $query->whereIn('status', ['verified_hold', 'verified'])
                ->whereIn('activation_state', ['maintenance_hold', 'active']);
        }
        $mapping = $query->first();
        if (! $mapping) {
            $this->fail('validated_organization_mapping_required');
        }

        $financeOrg = DB::connection('tenant')->table('organizations')
            ->where('id', $mapping->finance_organization_id)
            ->where('central_org_id', $mapping->central_organization_id)
            ->first();
        if (! $financeOrg || (int) $mapping->solastock_organization_id !== (int) $mapping->central_organization_id) {
            $this->fail('organization_scope_mismatch');
        }
        $setting = DB::connection('tenant')->table('integration_settings')
            ->where('organization_id', $mapping->solastock_organization_id)
            ->where('solabooks_organization_id', $mapping->finance_organization_id)
            ->where('integration', 'solabooks')
            ->whereIn('mode', ['connected_readonly', 'connected_pending_mapping', 'active', 'paused'])
            ->first();
        if (! $setting) {
            $this->fail('connection_scope_mismatch');
        }

        return $mapping;
    }

    /** @return array<string,array<string,mixed>> */
    private function entityDefinitions(): array
    {
        return [
            'item' => [
                'stock_table' => 'items',
                'books_table' => 'inventory_items',
                'keys' => [['sku', 'sku'], ['barcode', 'barcode'], ['name', 'name']],
                'strong_keys' => [['sku', 'sku'], ['barcode', 'barcode']],
            ],
            'customer' => [
                'stock_table' => 'inventory_customers',
                'books_table' => 'customers',
                'keys' => [['code', 'customer_number'], ['code', 'code'], ['name', 'name']],
                'strong_keys' => [['code', 'customer_number'], ['code', 'code']],
            ],
            'supplier' => [
                'stock_table' => 'inventory_suppliers',
                'books_table' => 'suppliers',
                'keys' => [['code', 'supplier_number'], ['code', 'code'], ['name', 'name']],
                'strong_keys' => [['code', 'supplier_number'], ['code', 'code']],
            ],
            'category' => [
                'stock_table' => 'item_categories',
                'books_table' => 'inventory_categories',
                'keys' => [['name', 'name']],
                'strong_keys' => [],
            ],
            'unit' => [
                'stock_table' => 'units',
                'books_table' => 'inventory_units',
                'keys' => [['symbol', 'symbol'], ['name', 'name']],
                'strong_keys' => [['symbol', 'symbol']],
            ],
            'warehouse' => [
                'stock_table' => 'warehouses',
                'books_table' => 'inventory_locations',
                'keys' => [['name', 'location_name']],
                'strong_keys' => [],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function discoverEntity(
        IntegrationOrganizationMapping $mapping,
        Collection $existing,
        string $entityType,
        array $definition,
    ): array {
        if (! Schema::connection('tenant')->hasTable($definition['stock_table'])) {
            return [$this->result($entityType, 'missing_solastock_table', [], [], ['table' => $definition['stock_table']])];
        }
        if (! Schema::connection('tenant')->hasTable($definition['books_table'])) {
            return [$this->result($entityType, 'missing_finance_table', [], [], ['table' => $definition['books_table']])];
        }

        $keys = collect($definition['keys'])->filter(fn ($pair) => Schema::connection('tenant')->hasColumn($definition['stock_table'], $pair[0])
            && Schema::connection('tenant')->hasColumn($definition['books_table'], $pair[1])
        )->values()->all();
        $strongKeys = collect($definition['strong_keys'] ?? [])->filter(fn ($pair) => in_array($pair, $keys, true)
        )->values()->all();
        if ($keys === []) {
            return [$this->result($entityType, 'incompatible_schema', [], [], [])];
        }

        $stock = $this->records($definition['stock_table'], (int) $mapping->solastock_organization_id, collect($keys)->pluck(0)->all());
        $books = $this->records($definition['books_table'], (int) $mapping->finance_organization_id, collect($keys)->pluck(1)->all());
        $results = [];
        $matchedBookIds = [];

        foreach ($stock as $stockRow) {
            $strongCandidates = $books->filter(
                fn (array $bookRow): bool => $this->matchesAny($stockRow, $bookRow, $strongKeys)
            )->values();
            $candidates = ($strongCandidates->isNotEmpty()
                ? $strongCandidates
                : $books->filter(fn (array $bookRow): bool => $this->matchesAny($stockRow, $bookRow, $keys))
            )->values();
            $candidateBasis = $strongCandidates->isNotEmpty() ? 'stable_candidate_key' : 'descriptive_candidate_only';

            $existingForStock = $existing->first(fn ($row) => $row->entity_type === $entityType
                && (string) $row->solastock_record_id === (string) $stockRow['id']
            );
            if ($existingForStock && ! $candidates->pluck('id')->map(fn ($id) => (string) $id)->contains((string) $existingForStock->solabooks_record_id)) {
                $results[] = $this->result($entityType, 'conflicting_mapping', [$stockRow['id']], [$existingForStock->solabooks_record_id], [
                    'reason' => 'existing_mapping_differs_from_discovery',
                ]);

                continue;
            }
            if ($candidates->isEmpty()) {
                $results[] = $this->result($entityType, 'missing_finance_record', [$stockRow['id']], [], [
                    'solastock_archived' => $stockRow['archived'],
                ]);

                continue;
            }
            if ($candidates->count() !== 1) {
                $results[] = $this->result($entityType, 'ambiguous_match', [$stockRow['id']], $candidates->pluck('id')->all(), [
                    'candidate_count' => $candidates->count(),
                ]);

                continue;
            }

            $book = $candidates->first();
            $reverseKeys = $candidateBasis === 'stable_candidate_key' ? $strongKeys : $keys;
            $reverseCount = $stock->filter(
                fn (array $other): bool => $this->matchesAny($other, $book, $reverseKeys)
            )->count();
            if ($reverseCount !== 1) {
                $results[] = $this->result($entityType, 'ambiguous_match', [$stockRow['id']], [$book['id']], [
                    'reverse_candidate_count' => $reverseCount,
                ]);

                continue;
            }

            $matchedBookIds[] = (string) $book['id'];
            $strongConflict = $this->hasConflictingPopulatedKeys($stockRow, $book, $strongKeys);
            $classification = match (true) {
                $stockRow['archived'] || $book['archived'] => 'archived_match',
                $candidateBasis !== 'stable_candidate_key' => 'review_required',
                $strongConflict => 'conflicting_candidates',
                default => 'exact_match',
            };
            $results[] = $this->result($entityType, $classification, [$stockRow['id']], [$book['id']], [
                'solastock_archived' => $stockRow['archived'],
                'solabooks_archived' => $book['archived'],
                'candidate_basis' => $candidateBasis,
                'strong_identifier_conflict' => $strongConflict,
            ]);
        }

        foreach ($books->whereNotIn('id', $matchedBookIds) as $bookRow) {
            $alreadyCandidate = collect($results)->contains(fn ($result) => in_array((string) $bookRow['id'], array_map('strval', $result['solabooks_record_ids']), true)
            );
            if (! $alreadyCandidate) {
                $results[] = $this->result($entityType, 'missing_solastock_record', [], [$bookRow['id']], [
                    'solabooks_archived' => $bookRow['archived'],
                ]);
            }
        }

        return $results;
    }

    /** @return array<int,array<string,mixed>> */
    private function discoverAccountRoles(IntegrationOrganizationMapping $mapping, Collection $existing): array
    {
        if (! Schema::connection('tenant')->hasTable('integration_account_mappings')) {
            return [$this->result('account_role', 'missing_solastock_table', [], [], ['table' => 'integration_account_mappings'])];
        }

        return DB::connection('tenant')->table('integration_account_mappings')
            ->where('organization_id', $mapping->solastock_organization_id)
            ->where('integration', 'solabooks')
            ->orderBy('mapping_type')
            ->get()
            ->map(function ($row) use ($mapping, $existing): array {
                $stockMappingId = (string) $row->id;
                $accountId = $row->solabooks_account_id ? (string) $row->solabooks_account_id : null;
                if (! $accountId) {
                    return $this->result('account_role', 'missing_finance_record', [$stockMappingId], [], [
                        'accounting_role' => (string) $row->mapping_type,
                    ]);
                }
                $account = DB::connection('tenant')->table('accounts')
                    ->where('id', $accountId)
                    ->where('organization_id', $mapping->finance_organization_id)
                    ->first();
                if (! $account) {
                    return $this->result('account_role', 'cross_organization_risk', [$stockMappingId], [$accountId], [
                        'accounting_role' => (string) $row->mapping_type,
                    ]);
                }
                if (! (bool) ($account->is_active ?? true) || ! (bool) ($account->is_postable ?? true)) {
                    return $this->result('account_role', 'account_incompatible', [$stockMappingId], [$accountId], [
                        'accounting_role' => (string) $row->mapping_type,
                        'active' => (bool) ($account->is_active ?? true),
                        'postable' => (bool) ($account->is_postable ?? true),
                    ]);
                }
                $conflict = $existing->first(fn ($current) => $current->entity_type === 'account_role'
                    && (string) $current->solastock_record_id === $stockMappingId
                    && (string) $current->solabooks_record_id !== $accountId
                );

                return $this->result(
                    'account_role',
                    $conflict ? 'conflicting_mapping' : 'exact_match',
                    [$stockMappingId],
                    [$accountId],
                    [
                        'accounting_role' => (string) $row->mapping_type,
                        'active' => (bool) ($account->is_active ?? true),
                        'postable' => (bool) ($account->is_postable ?? true),
                    ]
                );
            })->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function discoverTaxes(IntegrationOrganizationMapping $mapping, Collection $existing): array
    {
        if (! Schema::connection('tenant')->hasTable('integration_tax_mappings')) {
            return [$this->result('tax', 'missing_solastock_table', [], [], ['table' => 'integration_tax_mappings'])];
        }

        return DB::connection('tenant')->table('integration_tax_mappings')
            ->where('organization_id', $mapping->solastock_organization_id)
            ->where('integration', 'solabooks')
            ->orderBy('tax_code')
            ->get()
            ->map(function ($row) use ($mapping, $existing): array {
                $stockMappingId = (string) $row->id;
                $taxId = $row->solabooks_tax_id ? (string) $row->solabooks_tax_id : null;
                if (! $taxId) {
                    return $this->result('tax', 'missing_finance_record', [$stockMappingId], [], [
                        'tax_code' => (string) $row->tax_code,
                    ]);
                }
                $tax = DB::connection('tenant')->table('taxes')
                    ->where('id', $taxId)
                    ->where('organization_id', $mapping->finance_organization_id)
                    ->first();
                if (! $tax) {
                    return $this->result('tax', 'cross_organization_risk', [$stockMappingId], [$taxId], [
                        'tax_code' => (string) $row->tax_code,
                    ]);
                }
                if (! (bool) ($tax->is_active ?? true)) {
                    return $this->result('tax', 'tax_incompatible', [$stockMappingId], [$taxId], [
                        'tax_code' => (string) $row->tax_code,
                        'active' => false,
                    ]);
                }
                $conflict = $existing->first(fn ($current) => $current->entity_type === 'tax'
                    && (string) $current->solastock_record_id === $stockMappingId
                    && (string) $current->solabooks_record_id !== $taxId
                );

                return $this->result('tax', $conflict ? 'conflicting_mapping' : 'exact_match', [$stockMappingId], [$taxId], [
                    'tax_code' => (string) $row->tax_code,
                    'active' => (bool) ($tax->is_active ?? true),
                ]);
            })->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function discoverUnitConversions(IntegrationOrganizationMapping $mapping): array
    {
        if (! Schema::connection('tenant')->hasTable('unit_conversions')) {
            return [$this->result('unit_conversion', 'missing_solastock_table', [], [], ['table' => 'unit_conversions'])];
        }

        return DB::connection('tenant')->table('unit_conversions')
            ->where('organization_id', $mapping->solastock_organization_id)
            ->orderBy('id')
            ->get(['id', 'item_id', 'from_unit_id', 'to_unit_id', 'factor'])
            ->map(function ($row) use ($mapping): array {
                $units = DB::connection('tenant')->table('units')->whereIn('id', [$row->from_unit_id, $row->to_unit_id])
                    ->where('organization_id', $mapping->solastock_organization_id)
                    ->where('is_active', true)->whereNull('deleted_at')->get()->keyBy('id');
                $mappedUnits = IntegrationMasterDataMapping::query()
                    ->where('organization_mapping_uuid', $mapping->mapping_uuid)
                    ->where('entity_type', 'unit')
                    ->whereIn('solastock_record_id', [(string) $row->from_unit_id, (string) $row->to_unit_id])
                    ->whereIn('status', ['mapped', 'verified'])->get()->keyBy('solastock_record_id');
                $item = $row->item_id === null ? null : DB::connection('tenant')->table('items')
                    ->where('id', $row->item_id)->where('organization_id', $mapping->solastock_organization_id)
                    ->where('is_active', true)->whereNull('deleted_at')->first();
                $itemMapped = $row->item_id === null || IntegrationMasterDataMapping::query()
                    ->where('organization_mapping_uuid', $mapping->mapping_uuid)
                    ->where('entity_type', 'item')->where('solastock_record_id', (string) $row->item_id)
                    ->whereIn('status', ['mapped', 'verified'])->exists();
                $factor = (string) $row->factor;
                $validFactor = preg_match('/^\d+(?:\.\d+)?$/', $factor) === 1 && Decimal::cmp($factor, '0') > 0;
                $compatible = $row->item_id !== null && $units->count() === 2 && $mappedUnits->count() === 2 && $itemMapped
                    && $item && (int) $item->base_unit_id === (int) $row->to_unit_id
                    && $validFactor;
                $snapshot = [
                    'organization_id' => (int) $mapping->solastock_organization_id,
                    'item_id' => $row->item_id === null ? null : (int) $row->item_id,
                    'source_unit_id' => (int) $row->from_unit_id,
                    'base_unit_id' => (int) $row->to_unit_id,
                    'conversion_id' => (int) $row->id,
                    'factor' => $validFactor ? Decimal::round($factor, 8) : $factor,
                    'version' => UnitConversionResolver::CONTRACT_VERSION,
                    'precision' => UnitConversionResolver::PRECISION,
                    'rounding_mode' => UnitConversionResolver::ROUNDING_MODE,
                ];

                return $this->result(
                    'unit_conversion',
                    $compatible ? 'solastock_authoritative_conversion' : 'unit_conversion_incompatible',
                    [(string) $row->id],
                    [],
                    [
                        'authority' => 'solastock',
                        'finance_applies_conversion' => false,
                        'item_scoped' => $row->item_id !== null,
                        'item_id' => $row->item_id === null ? null : (string) $row->item_id,
                        'from_unit_id' => (string) $row->from_unit_id,
                        'to_unit_id' => (string) $row->to_unit_id,
                        'factor' => $snapshot['factor'],
                        'conversion_version' => UnitConversionResolver::CONTRACT_VERSION,
                        'conversion_hash' => hash('sha256', $this->canonicalJson($snapshot)),
                        'precision' => UnitConversionResolver::PRECISION,
                        'rounding_mode' => UnitConversionResolver::ROUNDING_MODE,
                        'source_unit_mapping_uuid' => $mappedUnits->get((string) $row->from_unit_id)?->mapping_uuid,
                        'base_unit_mapping_uuid' => $mappedUnits->get((string) $row->to_unit_id)?->mapping_uuid,
                        'reason' => $compatible ? null : 'conversion_scope_factor_or_immutable_mapping_invalid',
                    ]
                );
            })->all();
    }

    private function upsertExactMapping(
        IntegrationOrganizationMapping $organization,
        array $result,
        ?int $actorUserId,
    ): array {
        $stockId = (string) $result['solastock_record_ids'][0];
        $booksId = (string) $result['solabooks_record_ids'][0];
        $existing = IntegrationMasterDataMapping::query()
            ->where('organization_mapping_uuid', $organization->mapping_uuid)
            ->where('entity_type', $result['entity_type'])
            ->where(function ($query) use ($stockId, $booksId): void {
                $query->where('solastock_record_id', $stockId)
                    ->orWhere('solabooks_record_id', $booksId);
            })
            ->lockForUpdate()
            ->get();
        if ($existing->isNotEmpty()) {
            $matching = $existing->first(fn ($row) => (string) $row->solastock_record_id === $stockId
                && (string) $row->solabooks_record_id === $booksId
            );
            if (! $matching || $existing->count() !== 1) {
                $this->fail('master_mapping_conflict');
            }

            return [$matching, false];
        }

        try {
            $mapping = IntegrationMasterDataMapping::query()->create([
                'mapping_uuid' => (string) Str::uuid(),
                'organization_mapping_uuid' => $organization->mapping_uuid,
                'central_client_id' => $organization->central_client_id,
                'central_organization_id' => $organization->central_organization_id,
                'finance_organization_id' => $organization->finance_organization_id,
                'solastock_organization_id' => $organization->solastock_organization_id,
                'entity_type' => $result['entity_type'],
                'solastock_record_id' => $stockId,
                'solabooks_record_id' => $booksId,
                'status' => 'mapped',
                'contract_source_version' => self::VERSION,
                'discovery_method' => 'deterministic_one_to_one',
                'last_verified_at' => now(),
                'solastock_archived' => false,
                'solabooks_archived' => false,
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->fail('concurrent_master_mapping_conflict');
        }
        DB::connection('tenant')->table('integration_mapping_audits')->insert([
            'organization_mapping_uuid' => $organization->mapping_uuid,
            'mapping_uuid' => $mapping->mapping_uuid,
            'entity_type' => $mapping->entity_type,
            'action' => 'deterministic_mapping_created',
            'before_hash' => null,
            'after_hash' => hash('sha256', $this->canonicalJson($mapping->getAttributes())),
            'safe_metadata' => json_encode(['discovery_method' => 'deterministic_one_to_one']),
            'actor_user_id' => $actorUserId,
            'created_at' => now(),
        ]);

        return [$mapping, true];
    }

    private function records(string $table, int $organizationId, array $keys): Collection
    {
        $columns = array_values(array_unique(array_merge(['id'], $keys)));
        $hasDeleted = Schema::connection('tenant')->hasColumn($table, 'deleted_at');
        if ($hasDeleted) {
            $columns[] = 'deleted_at';
        }

        return DB::connection('tenant')->table($table)
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->get($columns)
            ->map(function ($row) use ($columns, $hasDeleted): array {
                $data = [];
                foreach ($columns as $column) {
                    $data[$column] = $row->{$column} ?? null;
                }
                $data['id'] = (string) $data['id'];
                $data['archived'] = $hasDeleted && $data['deleted_at'] !== null;

                return $data;
            });
    }

    private function existingMappings(IntegrationOrganizationMapping $mapping): Collection
    {
        return IntegrationMasterDataMapping::query()
            ->where('organization_mapping_uuid', $mapping->mapping_uuid)
            ->orderBy('entity_type')
            ->orderBy('id')
            ->get();
    }

    private function result(string $entity, string $classification, array $stockIds, array $booksIds, array $details): array
    {
        return [
            'entity_type' => $entity,
            'classification' => $classification,
            'solastock_record_ids' => array_values(array_map('strval', $stockIds)),
            'solabooks_record_ids' => array_values(array_map('strval', $booksIds)),
            'safe_details' => $details,
        ];
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)));

        return $normalized === '' ? null : $normalized;
    }

    private function matchesAny(array $stock, array $books, array $keys): bool
    {
        foreach ($keys as [$stockKey, $booksKey]) {
            $left = $this->normalize($stock[$stockKey] ?? null);
            $right = $this->normalize($books[$booksKey] ?? null);
            if ($left !== null && $right !== null && hash_equals($left, $right)) {
                return true;
            }
        }

        return false;
    }

    private function hasConflictingPopulatedKeys(array $stock, array $books, array $keys): bool
    {
        foreach ($keys as [$stockKey, $booksKey]) {
            $left = $this->normalize($stock[$stockKey] ?? null);
            $right = $this->normalize($books[$booksKey] ?? null);
            if ($left !== null && $right !== null && ! hash_equals($left, $right)) {
                return true;
            }
        }

        return false;
    }

    private function fingerprint(array $row): string
    {
        unset($row['fingerprint']);

        return hash('sha256', $this->canonicalJson($row));
    }

    private function beforeImage(IntegrationOrganizationMapping $mapping, Collection $existing, Collection $results): array
    {
        return [
            'organization_mapping' => $mapping->only([
                'mapping_uuid', 'central_client_id', 'central_organization_id',
                'tenant_database_identity', 'finance_organization_id',
                'solastock_organization_id', 'contract_version', 'status',
                'activation_state', 'base_currency_code',
            ]),
            'existing_mappings' => $existing->map(fn ($row) => $row->only([
                'mapping_uuid', 'organization_mapping_uuid', 'entity_type',
                'solastock_record_id', 'solabooks_record_id', 'status',
                'contract_source_version', 'solastock_archived', 'solabooks_archived',
            ]))->all(),
            'discovery' => $results->all(),
        ];
    }

    private function canonicalJson(mixed $value): string
    {
        $sort = function (mixed $node) use (&$sort): mixed {
            if (! is_array($node)) {
                return $node;
            }
            if (array_is_list($node)) {
                return array_map($sort, $node);
            }
            ksort($node, SORT_STRING);

            return array_map($sort, $node);
        };

        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function fail(string $code): never
    {
        throw ValidationException::withMessages(['mapping' => $code]);
    }

    private function assertSafetyHolds(): void
    {
        if (config('integration_safety.solabooks_delivery_enabled', true)
            || ! config('integration_safety.legacy_finance_inventory_writes_blocked', false)
            || config('integration_safety.legacy_journal_contract_enabled', true)
            || config('integration_safety.historical_repair_enabled', true)
            || config('integration_safety.pending_event_replay_enabled', true)) {
            $this->fail('phase0_safety_holds_required');
        }
    }
}
