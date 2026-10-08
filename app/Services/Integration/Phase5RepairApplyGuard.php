<?php

namespace App\Services\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Phase5RepairApplyGuard
{
    /** @param array<string,mixed> $input */
    public function rejectAndAudit(array $input): string
    {
        $code = $this->validate($input);
        DB::connection('tenant')->table('integration_historical_repair_attempt_audits')->insert([
            'attempt_uuid' => (string) Str::uuid(),
            'application' => 'solastock',
            'tenant_database_identity' => (string) DB::connection('tenant')->getDatabaseName(),
            'organization_id' => (int) $input['organization'],
            'batch_identifier' => $this->safe((string) ($input['batch'] ?: 'missing')),
            'manifest_sha256' => preg_match('/^[a-f0-9]{64}$/D', (string) $input['manifest_sha256'])
                ? (string) $input['manifest_sha256'] : str_repeat('0', 64),
            'approval_identifier' => $this->safe((string) ($input['approval'] ?: 'missing')),
            'snapshot_reference' => $this->safe((string) ($input['snapshot'] ?: 'missing')),
            'outcome' => 'aborted',
            'safe_error_code' => $code,
        ]);

        return $code;
    }

    /** @param array<string,mixed> $input */
    private function validate(array $input): string
    {
        foreach (['batch', 'approval', 'snapshot'] as $required) {
            if (trim((string) ($input[$required] ?? '')) === '') {
                return 'missing_'.$required;
            }
        }
        $path = (string) ($input['manifest'] ?? '');
        $sha = strtolower((string) ($input['manifest_sha256'] ?? ''));
        if (! preg_match('/^[a-f0-9]{64}$/D', $sha)) {
            return 'invalid_manifest_sha256';
        }
        if (! is_file($path) || ! hash_equals($sha, (string) hash_file('sha256', $path))) {
            return 'manifest_sha256_mismatch';
        }
        $first = $this->firstCandidate($path);
        if (($first['tenant_client_identity']['tenant_database'] ?? null) !== DB::connection('tenant')->getDatabaseName()) {
            return 'wrong_tenant';
        }
        if ((int) ($first['organization_identities']['solastock_organization_id'] ?? 0) !== (int) $input['organization']) {
            return 'wrong_organization';
        }
        if (! config('integration_safety.historical_repair_enabled', false)) {
            return 'historical_repair_feature_disabled';
        }
        if (config('integration_safety.solabooks_delivery_enabled', true)
            || config('integration_transport.worker_enabled', true)
            || config('integration_safety.pending_event_replay_enabled', true)) {
            return 'required_safety_hold_not_active';
        }

        return 'apply_blocked_phase5a';
    }

    /** @return array<string,mixed> */
    private function firstCandidate(string $path): array
    {
        $handle = fopen($path, 'rb');
        $line = $handle ? fgets($handle) : false;
        if ($handle) {
            fclose($handle);
        }
        $decoded = $line ? json_decode($line, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function safe(string $value): string
    {
        return substr(preg_replace('/[^A-Za-z0-9_.:@-]/', '_', $value) ?: 'invalid', 0, 191);
    }
}
