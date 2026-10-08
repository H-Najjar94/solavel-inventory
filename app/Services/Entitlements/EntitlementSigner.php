<?php

namespace App\Services\Entitlements;

/**
 * Signs and verifies the entitlement payload itself.
 *
 * The transport is already HMAC-signed, but that only proves "central sent this
 * over the wire". It says nothing once the payload is at rest in the tenant's
 * `tenant_entitlements_snapshots` row — and that row is what actually grants a
 * customer paid access for weeks at a time now that access is bounded by
 * `access_until` rather than by snapshot age.
 *
 * So the entitlement carries its own signature, over its own canonical bytes,
 * bound to the tenant it was issued for. A tenant will not honour an entitlement
 * whose signature does not verify, and — critically — will not let an unverifiable
 * entitlement REPLACE the last one that did verify.
 *
 * Key rotation: `key_id` travels with the signature, so a tenant can be given the
 * new key before central starts signing with it.
 */
class EntitlementSigner
{
    /** Fields that are NOT part of the signed body (they wrap it). */
    private const UNSIGNED = ['signature', 'key_id'];

    public function sign(array $payload): array
    {
        $keyId = (string) config('entitlements.signing.key_id', 'v1');
        $key = $this->key($keyId);

        if ($key === '') {
            // Unsigned rollout: better to deliver an unsigned entitlement (which
            // tenants accept but flag) than to stop delivering entitlements because
            // a key is missing. Never fail a paid customer's delivery over key
            // hygiene — surface it instead.
            \Illuminate\Support\Facades\Log::warning('Entitlement signing key is not configured; pushing UNSIGNED entitlement', [
                'key_id' => $keyId,
                'alert' => 'entitlement_signing_key_missing',
            ]);

            return $payload;
        }

        $payload['key_id'] = $keyId;
        $payload['signature'] = 'sha256:' . hash_hmac('sha256', $this->canonical($payload), $key);

        return $payload;
    }

    public function verify(array $payload): bool
    {
        $signature = (string) ($payload['signature'] ?? '');
        $keyId = (string) ($payload['key_id'] ?? '');

        if ($signature === '' || $keyId === '') {
            return false;
        }

        $key = $this->key($keyId);

        if ($key === '') {
            return false;
        }

        $expected = 'sha256:' . hash_hmac('sha256', $this->canonical($payload), $key);

        return hash_equals($expected, $signature);
    }

    /**
     * Whether an incoming payload even claims to be signed. Used during rollout so
     * a pre-signing snapshot is not mistaken for a FORGED one.
     */
    public function isSigned(array $payload): bool
    {
        return ($payload['signature'] ?? '') !== '' && ($payload['key_id'] ?? '') !== '';
    }

    /**
     * Whether we hold the key this payload was signed with.
     *
     * "I cannot check this" is NOT the same as "this is forged", and conflating
     * them is a fleet-wide outage: the moment central starts signing, any app whose
     * key has not been distributed yet would reject EVERY entitlement, freeze its
     * snapshots, and eventually expire real customers. A missing key means we
     * cannot verify — so we accept and shout, rather than deny and break.
     *
     * Set ENTITLEMENT_REQUIRE_SIGNATURE=true once every app holds the key.
     */
    public function canVerify(array $payload): bool
    {
        return $this->key((string) ($payload['key_id'] ?? '')) !== '';
    }

    /**
     * Canonical bytes: recursively key-sorted JSON, signature fields removed.
     *
     * Must be byte-identical on both sides, so it cannot depend on PHP's array
     * ordering or on how json_encode happens to escape slashes/unicode.
     */
    private function canonical(array $payload): string
    {
        foreach (self::UNSIGNED as $field) {
            unset($payload[$field]);
        }

        $this->ksortRecursive($payload);

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function ksortRecursive(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }

    private function key(string $keyId): string
    {
        $keys = (array) config('entitlements.signing.keys', []);

        return (string) ($keys[$keyId] ?? '');
    }
}
