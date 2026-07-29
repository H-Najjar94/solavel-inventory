<?php

namespace App\Http\Controllers\Api\Tenancy;

use App\Http\Controllers\Api\ApiController;
use App\Services\Entitlements\EntitlementClock;
use App\Services\Entitlements\EntitlementSigner;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SyncEventsController extends ApiController
{
    private EntitlementSigner $signer;

    public function __construct(
        private TenantManager $tenants,
        private EntitlementsCache $entitlements,
        ?EntitlementSigner $signer = null,
    ) {
        $this->signer = $signer ?? new EntitlementSigner;
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
                'message' => __('inventory.sync.checksum_invalid'),
                'code' => 'sync_payload_checksum_invalid',
                'status' => 'rejected',
            ], 422);
        }

        // SIGNATURE + TENANT IDENTITY.
        //
        // The transport HMAC (VerifySolavelSyncSignature) only proves "central sent
        // this over the wire". It says nothing once the payload is at rest in
        // `tenant_entitlements_snapshots` — and that row now grants paid access for
        // WEEKS without a refresh, which makes it worth forging.
        //
        // So the entitlement carries its own signature, over its own canonical
        // bytes, bound to the tenant it was issued for. A failure REJECTS the
        // incoming entitlement and leaves the last valid one exactly where it is:
        // we never overwrite good state with unverifiable state.
        if (($rejection = $this->verifyEntitlement($payload, $clientId)) !== null) {
            return $rejection;
        }

        $projects = (array) ($payload['projects'] ?? []);
        if ($projects === []) {
            return $this->success(['status' => 'ignored', 'reason' => 'empty_snapshot']);
        }

        $version = (string) ($payload['version'] ?? '');
        if ($version === '') {
            $version = substr(sha1(json_encode($projects, JSON_UNESCAPED_SLASHES) ?: ''), 0, 16);
        }

        // Absolute UTC, always. The previous code coerced central's UTC instants
        // into Asia/Amman wall clock before writing them to a UTC column, which put
        // every live SolaStock row three hours in the FUTURE. See EntitlementClock.
        $syncedAt = EntitlementClock::parse($payload['computed_at'] ?? null) ?? EntitlementClock::now();

        $metadata = array_intersect_key($payload, array_flip([
            'revision',
            'evaluated_at',
            'pushed_at',
            'valid_until',
            'schema_version',
            'source_version',
            'state_hash',
        ]));

        // Normalise the ordering timestamps to absolute UTC before they are compared
        // against anything already stored.
        foreach (['evaluated_at', 'pushed_at', 'valid_until'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $metadata[$key] = EntitlementClock::format(EntitlementClock::parse($metadata[$key]));
            }
        }

        // PRIMARY ordering key. Central is expected to send a monotonic `revision`,
        // but the pushers have not all shipped it yet — and SolaStock cannot wait,
        // because its stored rows are future-dated: a corrected UTC snapshot looks
        // "older" than them and would be rejected FOREVER, freezing the tenant.
        //
        // Deriving the revision from the push instant (epoch millis) is monotonic by
        // construction and carries no new information, so ordering between two
        // revisioned snapshots is unchanged. What it buys is the CUTOVER: a snapshot
        // WITH a revision always beats a stored row WITHOUT one, which is exactly how
        // today's future-dated legacy rows get replaced instead of freezing.
        if (! isset($metadata['revision']) || ! is_numeric($metadata['revision'])) {
            $orderingInstant = EntitlementClock::parse($metadata['pushed_at'] ?? null)
                ?? EntitlementClock::parse($metadata['evaluated_at'] ?? null)
                ?? $syncedAt;

            $metadata['revision'] = $orderingInstant->getTimestampMs();
        }

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

    /**
     * Verify the entitlement's own signature and the tenant it was issued for.
     *
     * Returns null when the payload may be applied, or the 422 rejection response
     * when it may not. Rejecting means the PREVIOUS, valid entitlement stays in
     * place untouched — an unverifiable entitlement must never be able to downgrade
     * (or upgrade) a customer.
     */
    private function verifyEntitlement(array $payload, int $clientId): ?JsonResponse
    {
        $signed = $this->signer->isSigned($payload);
        $requireSignature = (bool) config('entitlements.signing.require_signature', false);

        // Signed with a key we do not hold. We cannot check it — which is NOT the
        // same as it being forged. Rejecting here would freeze every snapshot the
        // moment central rotated or introduced a key, so accept and shout instead.
        if ($signed && ! $this->signer->canVerify($payload)) {
            Log::error('Entitlement signed with an unknown key_id — accepted UNVERIFIED', [
                'client_id' => $clientId,
                'key_id' => $payload['key_id'] ?? null,
                'alert' => 'entitlement_signing_key_unknown',
            ]);

            return $requireSignature
                ? $this->rejectEntitlement('entitlement_signature_invalid', __('inventory.sync.signing_key_unknown'))
                : null;
        }

        if ($signed) {
            if (! $this->signer->verify($payload)) {
                Log::critical('Entitlement signature INVALID — rejected, previous entitlement retained', [
                    'client_id' => $clientId,
                    'key_id' => $payload['key_id'] ?? null,
                    'revision' => $payload['revision'] ?? null,
                    'alert' => 'entitlement_signature_invalid',
                ]);

                return $this->rejectEntitlement('entitlement_signature_invalid', __('inventory.sync.entitlement_invalid'));
            }

            // Bind the entitlement to THIS tenant: one issued for another client must
            // not be replayable into this one, however valid its signature is.
            $signedFor = (int) ($payload['client_id'] ?? 0);

            if ($signedFor !== $clientId) {
                Log::critical('Entitlement issued for a DIFFERENT client — rejected, previous entitlement retained', [
                    'received_for' => $clientId,
                    'signed_for' => $signedFor,
                    'alert' => 'entitlement_tenant_mismatch',
                ]);

                return $this->rejectEntitlement('entitlement_tenant_mismatch', 'Entitlement client_id mismatch.');
            }

            return null;
        }

        // Unsigned. During rollout this is a pre-signing snapshot, not a forgery —
        // accept it and flag it. Once every app is signing, flip
        // ENTITLEMENT_REQUIRE_SIGNATURE on and this becomes a hard rejection.
        if ($requireSignature) {
            Log::critical('Unsigned entitlement rejected (signature required)', [
                'client_id' => $clientId,
                'alert' => 'entitlement_signature_missing',
            ]);

            return $this->rejectEntitlement('entitlement_signature_missing', __('inventory.sync.entitlement_unsigned'));
        }

        return null;
    }

    private function rejectEntitlement(string $code, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
            'status' => 'rejected',
        ], 422);
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
