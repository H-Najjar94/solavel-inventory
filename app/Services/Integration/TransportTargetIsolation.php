<?php

namespace App\Services\Integration;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Per-organization, per-stage failure isolation for the SolaBooks transport supervisor.
 *
 * One organization's failure (for example receipt delivery not enabled) is recorded as a
 * durable diagnostic (client/organization ids, stage, error code and class; never messages,
 * payloads or secrets), only that organization's stage backs off exponentially, and the
 * supervisor continues with every other stage and organization. Success clears the entry.
 */
final class TransportTargetIsolation
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $state = null;

    /**
     * @template T
     * @param  array{client_id:int,organization_id:int}  $target
     * @param  callable(): T  $work
     * @return T|mixed  the work result, or $fallback when backing off or after a failure
     */
    public function attempt(array $target, string $stage, callable $work, mixed $fallback = 0): mixed
    {
        if ($this->backingOff($target, $stage)) {
            return $fallback;
        }
        try {
            $result = $work();
        } catch (Throwable $error) {
            $this->failed($target, $stage, $error);

            return $fallback;
        }
        $this->succeeded($target, $stage);

        return $result;
    }

    public function backingOff(array $target, string $stage): bool
    {
        $entry = $this->load()[$this->key($target, $stage)] ?? null;
        if (! is_array($entry) || empty($entry['next_attempt_at'])) {
            return false;
        }
        try {
            return now()->lt(\Illuminate\Support\Carbon::parse((string) $entry['next_attempt_at']));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function diagnostics(): array
    {
        return $this->load();
    }

    public function path(): string
    {
        $configured = (string) config('integration_transport.supervisor.diagnostics_path', '');
        if ($configured !== '') {
            return $configured;
        }

        return dirname((string) config('integration_transport.supervisor.heartbeat_path')).'/target-diagnostics.json';
    }

    /** Stable, secret-free error code for operators. */
    public static function code(Throwable $error): string
    {
        $message = $error->getMessage();
        foreach (['en', 'ar'] as $locale) {
            if ($message !== '' && $message === trans('inventory.purchasing.connection_review_required', [], $locale)) {
                return 'connection_review_required';
            }
        }
        if ($error instanceof \App\Exceptions\FinanceSetupRequired) {
            return 'finance_setup_required';
        }
        try {
            if ($message !== '' && $message === app(IntegrationSafetyHold::class)->message()) {
                return 'delivery_safety_hold';
            }
        } catch (Throwable) {
            // Classification must never fail the supervisor.
        }
        if ($error instanceof \Illuminate\Http\Client\ConnectionException) {
            return 'finance_unreachable';
        }
        if ($error instanceof \Illuminate\Database\QueryException) {
            return 'database_error';
        }
        if ($error instanceof \Illuminate\Validation\ValidationException) {
            return 'validation_failed';
        }
        if ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return 'http_'.$error->getStatusCode();
        }

        return 'unexpected_'.Str::snake(class_basename($error));
    }

    private function failed(array $target, string $stage, Throwable $error): void
    {
        $state = $this->load();
        $key = $this->key($target, $stage);
        $previous = is_array($state[$key] ?? null) ? $state[$key] : [];
        $failures = (int) ($previous['failures'] ?? 0) + 1;
        $base = max(1, (int) config('integration_transport.base_backoff_seconds', 30));
        $max = max($base, (int) config('integration_transport.max_backoff_seconds', 3600));
        $delay = (int) min($max, $base * (2 ** min(16, $failures - 1)));
        $code = self::code($error);
        $now = now('UTC');
        $state[$key] = [
            'client_id' => (int) $target['client_id'],
            'organization_id' => (int) $target['organization_id'],
            'stage' => $stage,
            'error_code' => $code,
            'error_class' => class_basename($error),
            'failures' => $failures,
            'first_failed_at' => (string) ($previous['first_failed_at'] ?? $now->toIso8601String()),
            'last_failed_at' => $now->toIso8601String(),
            'next_attempt_at' => $now->copy()->addSeconds($delay)->toIso8601String(),
        ];
        $this->persist($state);
        Log::warning('integration.transport.target_failed', [
            'client_id' => (int) $target['client_id'],
            'organization_id' => (int) $target['organization_id'],
            'stage' => $stage,
            'error_code' => $code,
            'error_class' => class_basename($error),
            'failures' => $failures,
            'retry_in_seconds' => $delay,
        ]);
    }

    private function succeeded(array $target, string $stage): void
    {
        $state = $this->load();
        $key = $this->key($target, $stage);
        if (! array_key_exists($key, $state)) {
            return;
        }
        unset($state[$key]);
        $this->persist($state);
        Log::info('integration.transport.target_recovered', [
            'client_id' => (int) $target['client_id'],
            'organization_id' => (int) $target['organization_id'],
            'stage' => $stage,
        ]);
    }

    private function key(array $target, string $stage): string
    {
        return (int) $target['client_id'].':'.(int) $target['organization_id'].':'.$stage;
    }

    /** @return array<string, array<string, mixed>> */
    private function load(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }
        $path = $this->path();
        $decoded = is_readable($path) ? json_decode((string) @file_get_contents($path), true) : null;

        return $this->state = is_array($decoded['targets'] ?? null) ? $decoded['targets'] : [];
    }

    private function persist(array $state): void
    {
        $this->state = $state;
        $path = $this->path();
        try {
            $directory = dirname($path);
            if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
                throw new \RuntimeException('diagnostics directory unavailable');
            }
            $payload = json_encode(['contract_version' => 'solastock-finance-worker-diagnostics.v1', 'updated_at' => now('UTC')->toIso8601String(),
                'targets' => $state], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
            $temporary = $path.'.tmp.'.getmypid();
            if (file_put_contents($temporary, $payload, LOCK_EX) === false || ! chmod($temporary, 0640) || ! rename($temporary, $path)) {
                @unlink($temporary);
                throw new \RuntimeException('diagnostics write failed');
            }
        } catch (Throwable $error) {
            // The in-memory backoff still isolates the target; never stop the supervisor.
            Log::warning('integration.transport.diagnostics_unwritable', ['error_class' => class_basename($error)]);
        }
    }
}
