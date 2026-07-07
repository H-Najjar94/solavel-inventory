<?php

namespace App\Http\Controllers\Api\Tenancy;

use App\Http\Controllers\Api\ApiController;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SyncEventsController extends ApiController
{
    public function __construct(
        private TenantManager $tenants,
        private EntitlementsCache $entitlements,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $envelope = $this->normalizeEnvelope($request->all());

        $validated = Validator::make($envelope, [
            'event_id' => ['required', 'string', 'max:128'],
            'event_action' => ['required', 'string', 'max:64'],
            'resource_type' => ['required', 'string', 'max:64'],
            'resource_id' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'integer', 'min:1'],
            'payload' => ['required', 'array'],
            'checksum' => ['sometimes', 'nullable', 'string', 'max:128'],
            'tenant_db' => ['sometimes', 'nullable', 'string', 'max:128'],
            'entity_updated_at' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();

        if ($validated['resource_type'] !== 'entitlements_snapshot') {
            return $this->success(['status' => 'ignored', 'reason' => 'unsupported_resource_type']);
        }

        $clientId = (int) $validated['client_id'];
        $database = (string) ($validated['tenant_db'] ?? $this->tenants->resolveDatabaseName($clientId));
        $this->tenants->switchToDatabase($database);

        $payload = (array) $validated['payload'];
        $checksum = (string) ($validated['checksum'] ?? '');
        if (str_starts_with($checksum, 'sha256:') && ! hash_equals($checksum, $this->payloadChecksum($payload))) {
            return response()->json([
                'message' => 'Invalid sync payload checksum.',
                'code' => 'sync_payload_checksum_invalid',
                'status' => 'rejected',
            ], 422);
        }

        $projects = (array) ($payload['projects'] ?? []);
        if ($projects === []) {
            return $this->success(['status' => 'ignored', 'reason' => 'empty_snapshot']);
        }

        $version = (string) ($payload['version'] ?? '');
        if ($version === '') {
            $version = substr(sha1(json_encode($projects, JSON_UNESCAPED_SLASHES) ?: ''), 0, 16);
        }

        $syncedAt = now('UTC');
        if (! empty($payload['computed_at'])) {
            try {
                $syncedAt = Carbon::parse((string) $payload['computed_at'])->utc();
            } catch (\Throwable) {
                $syncedAt = now('UTC');
            }
        }

        $metadata = array_intersect_key($payload, array_flip([
            'evaluated_at',
            'pushed_at',
            'valid_until',
            'schema_version',
            'source_version',
            'state_hash',
        ]));

        $stored = 0;
        foreach ($projects as $projectSlug => $projectPayload) {
            if (! is_string($projectSlug) || ! is_array($projectPayload)) {
                continue;
            }

            $this->entitlements->storeProjectSnapshot($clientId, $projectSlug, $projectPayload, $version, $syncedAt, $metadata);
            $stored++;
        }

        return $this->success([
            'status' => 'applied',
            'client_id' => $clientId,
            'database' => $database,
            'snapshots_stored' => $stored,
        ]);
    }

    private function normalizeEnvelope(array $input): array
    {
        return array_merge($input, [
            'event_action' => $input['event_action'] ?? $input['action'] ?? null,
            'resource_type' => $input['resource_type'] ?? $input['entity_type'] ?? null,
            'resource_id' => $input['resource_id'] ?? $input['entity_id'] ?? null,
            'client_id' => $input['client_id'] ?? $input['tenant_id'] ?? null,
        ]);
    }

    private function payloadChecksum(array $payload): string
    {
        ksort($payload);

        return 'sha256:' . hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
        );
    }
}
