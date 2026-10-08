<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'app' => [
                'status' => 'ok',
                'app_name' => (string) config('app.name'),
                'environment' => (string) config('app.env'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
            'database' => $this->database(),
            'cache' => $this->cache(),
            'queue' => [
                'status' => 'ok',
                'driver' => (string) config('queue.default'),
            ],
            'storage' => $this->storage(),
        ];

        $status = collect($checks)->contains(fn (array $check) => ($check['status'] ?? null) === 'critical')
            ? 'critical'
            : (collect($checks)->contains(fn (array $check) => ($check['status'] ?? null) === 'warning') ? 'warning' : 'ok');

        return response()->json([
            'app' => 'solavel-inventory',
            'status' => $status,
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ]);
    }

    private function database(): array
    {
        try {
            DB::connection()->select('SELECT 1');

            return ['status' => 'ok', 'default_ok' => true, 'driver' => DB::connection()->getDriverName()];
        } catch (Throwable) {
            return ['status' => 'critical', 'default_ok' => false];
        }
    }

    private function cache(): array
    {
        try {
            $key = 'internal-health:'.bin2hex(random_bytes(8));
            Cache::put($key, 'ok', 10);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);

            return ['status' => $ok ? 'ok' : 'warning', 'driver' => (string) config('cache.default')];
        } catch (Throwable) {
            return ['status' => 'warning', 'driver' => (string) config('cache.default')];
        }
    }

    private function storage(): array
    {
        $paths = [storage_path(), storage_path('logs'), storage_path('framework'), base_path('bootstrap/cache')];
        $writable = collect($paths)->every(fn (string $path) => is_dir($path) && is_writable($path));

        return ['status' => $writable ? 'ok' : 'critical', 'writable' => $writable];
    }
}

