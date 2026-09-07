<?php

namespace App\Services\InventoryWorkspace;

final class WorkspaceSignature
{
    public const VERSION = 'finance-stock-workspace.v1';
    public const PATH = '/api/internal/finance-workspace';

    public static function sign(string $body, string $timestamp, string $nonce, string $secret): string
    {
        return hash_hmac('sha256', implode("\n", [self::VERSION, 'POST', self::PATH, $timestamp, $nonce, hash('sha256', $body)]), $secret);
    }

    public static function verifies(string $body, string $timestamp, string $nonce, string $signature, string $secret, int $now): bool
    {
        return strlen($secret) >= 32 && ctype_digit($timestamp) && abs($now - (int) $timestamp) <= 300
            && preg_match('/^[a-f0-9]{48}$/D', $nonce) === 1
            && hash_equals(self::sign($body, $timestamp, $nonce, $secret), $signature);
    }
}
