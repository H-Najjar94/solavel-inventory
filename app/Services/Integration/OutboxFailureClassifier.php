<?php

namespace App\Services\Integration;

use Illuminate\Http\Client\ConnectionException;
use Throwable;

final class OutboxFailureClassifier
{
    private const PERMANENT_CODES = [
        'identity', 'mapping', 'currency', 'account', 'tax', 'accounting_period',
        'workflow', 'idempotency_content_conflict', 'contract',
    ];

    public function classify(?int $httpStatus, ?string $code, Throwable|string|null $error = null): array
    {
        $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;
        $safe = mb_substr(trim(preg_replace('/\\s+/', ' ', $message)), 0, 500);
        if ($error instanceof ConnectionException || $httpStatus === null) {
            return ['retryable' => true, 'category' => 'transport', 'code' => 'connection_failure', 'safe_error' => $safe];
        }
        if ($httpStatus === 429) {
            return ['retryable' => true, 'category' => 'rate_limit', 'code' => $code ?: 'http_429', 'safe_error' => $safe];
        }
        if ($httpStatus >= 500) {
            return ['retryable' => true, 'category' => 'remote_temporary', 'code' => $code ?: 'http_'.$httpStatus, 'safe_error' => $safe];
        }
        $normalized = strtolower((string) $code);
        $permanent = $httpStatus >= 400 && $httpStatus < 500;
        foreach (self::PERMANENT_CODES as $needle) {
            if (str_contains($normalized, $needle)) {
                $permanent = true;
                break;
            }
        }

        return [
            'retryable' => ! $permanent,
            'category' => $permanent ? 'business_permanent' : 'transport',
            'code' => $code ?: ($httpStatus ? 'http_'.$httpStatus : 'unknown_failure'),
            'safe_error' => $safe,
        ];
    }
}
