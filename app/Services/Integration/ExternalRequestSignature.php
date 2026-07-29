<?php

namespace App\Services\Integration;

final class ExternalRequestSignature
{
    public const VERSION = 'v1';

    public static function bodyHash(string $rawBody): string
    {
        return hash('sha256', $rawBody);
    }

    public static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $parts = explode('&', $query);
        sort($parts, SORT_STRING);

        return implode('&', $parts);
    }

    public static function canonicalString(
        string $method,
        string $path,
        string $query,
        string $contentType,
        string $timestamp,
        string $nonce,
        string $bodyHash,
        string $inventoryOrganizationId,
        string $financeOrganizationId,
        string $externalSourceKey,
        string $eventType,
        string $version = self::VERSION,
        string $contractVersion = '',
        string $centralClientId = '',
        string $centralOrganizationId = '',
        string $integrationMappingId = '',
    ): string {
        return implode("\n", [
            'version:'.$version,
            'method:'.strtoupper($method),
            'path:'.($path === '' ? '/' : $path),
            'query:'.self::canonicalQuery($query),
            'content-type:'.strtolower(trim(explode(';', $contentType, 2)[0])),
            'timestamp:'.$timestamp,
            'nonce:'.$nonce,
            'content-sha256:'.strtolower($bodyHash),
            'inventory-organization-id:'.$inventoryOrganizationId,
            'finance-organization-id:'.$financeOrganizationId,
            'external-source-key:'.$externalSourceKey,
            'event-type:'.$eventType,
            'contract-version:'.$contractVersion,
            'central-client-id:'.$centralClientId,
            'central-organization-id:'.$centralOrganizationId,
            'integration-mapping-id:'.$integrationMappingId,
        ]);
    }

    public static function sign(string $canonical, string $secret): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $secret, true)), '+/', '-_'), '=');
    }

    public static function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
