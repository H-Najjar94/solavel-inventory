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
 *
 * Failures are handled at most once per (organization, stage) backoff window, because
 * attempt() skips a stage while it is backing off. That single warning carries a redacted,
 * length-capped message and the throw location for operators; the durable diagnostics file
 * stays message-free.
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

    /**
     * Number of the given targets with at least one recorded (still failing or backing off) stage.
     *
     * @param  list<array{client_id:int,organization_id:int}>  $targets
     */
    public function failingTargets(array $targets): int
    {
        $failing = [];
        foreach ($this->load() as $entry) {
            if (is_array($entry)) {
                $failing[(int) ($entry['client_id'] ?? 0).':'.(int) ($entry['organization_id'] ?? 0)] = true;
            }
        }
        $count = 0;
        $seen = [];
        foreach ($targets as $target) {
            $key = (int) $target['client_id'].':'.(int) $target['organization_id'];
            if (isset($failing[$key]) && ! isset($seen[$key])) {
                $count++;
            }
            $seen[$key] = true;
        }

        return $count;
    }

    /**
     * Operator-safe exception text: credentials, tokens, signatures, query strings, SQL bindings
     * and e-mail addresses are removed, control characters collapsed and the result capped.
     */
    public static function redact(string $message, int $limit = 300): string
    {
        $patterns = [
            // SQL text and bound values (may hold customer data) from QueryException messages.
            '/\bSQL:\s[^\n]*/u' => 'SQL: [redacted]',
            // scheme://user:password@host -> scheme://[redacted]@host
            '~\b([a-z][a-z0-9+.\-]*://)[^/\s:@]+(?::[^/\s@]*)?@~iu' => '$1[redacted]@',
            // Any URL query string (signatures, tokens, codes).
            '~(\b[a-z][a-z0-9+.\-]*://[^\s?#]*)\?[^\s]*~iu' => '$1?[redacted]',
            // Authorization schemes.
            '/\b(Bearer|Basic|Digest|Token)\s+[A-Za-z0-9._~+\/=\-]+/iu' => '$1 [redacted]',
            // JSON Web Tokens.
            '/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]*)?/u' => '[redacted]',
            // key=value / key: value / "key":"value" for secret-looking keys.
            '/(["\']?\b[\w.\-]*(?:password|passwd|pwd|secret|token|api[_\-]?key|apikey|access[_\-]?key|private[_\-]?key|signature|credential|authorization|cookie|session)[\w.\-]*["\']?\s*(?:=>|=|:)\s*)(["\']?)[^\s"\',;&)}\]]+\2/iu' => '$1[redacted]',
            // E-mail addresses.
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu' => '[email]',
            // Long opaque runs mixing letters and digits (hex/base64 keys, hashes, tokens).
            // '/' is excluded so file paths in messages stay readable.
            '/\b(?=[A-Za-z0-9+_\-]*[0-9])(?=[A-Za-z0-9+_\-]*[A-Za-z])[A-Za-z0-9+_\-]{32,}={0,2}/u' => '[redacted]',
        ];
        $clean = $message;
        foreach ($patterns as $pattern => $replacement) {
            $next = preg_replace($pattern, $replacement, $clean);
            $clean = is_string($next) ? $next : '[unparseable message]';
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $clean)));
        if (mb_strlen($clean) > $limit) {
            $clean = rtrim(mb_substr($clean, 0, max(1, $limit - 1))).'…';
        }

        return $clean;
    }

    /** Throw site relative to the application root, e.g. app/Services/X.php:42. */
    public static function location(Throwable $error): string
    {
        $file = str_replace('\\', '/', $error->getFile());
        $root = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        if (str_starts_with($file, $root)) {
            $file = substr($file, strlen($root));
        } elseif (preg_match('#/releases/[^/]+/(.+)$#', $file, $match) === 1) {
            $file = $match[1];
        }

        return $file.':'.$error->getLine();
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
            'error_location' => self::location($error),
            'failures' => $failures,
            'first_failed_at' => (string) ($previous['first_failed_at'] ?? $now->toIso8601String()),
            'last_failed_at' => $now->toIso8601String(),
            'next_attempt_at' => $now->copy()->addSeconds($delay)->toIso8601String(),
        ];
        $this->persist($state);
        // Logged once per backoff window (attempt() skips backed-off stages). The message is
        // redacted and capped; it never reaches the durable diagnostics file.
        Log::warning('integration.transport.target_failed', [
            'client_id' => (int) $target['client_id'],
            'organization_id' => (int) $target['organization_id'],
            'stage' => $stage,
            'error_code' => $code,
            'error_class' => get_class($error),
            'error_location' => self::location($error),
            'error_message' => self::redact($error->getMessage()),
            'previous_class' => $error->getPrevious() ? get_class($error->getPrevious()) : null,
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
