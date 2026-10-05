<?php
// Native exact-release heartbeat gate. Reads operational metadata only.
declare(strict_types=1);
$release = $argv[1] ?? '';
$sha = $argv[2] ?? '';
if (!preg_match('/^[a-f0-9]{40}$/', $sha) || trim((string) @file_get_contents($release.'/RELEASE_SHA')) !== $sha) {
    fwrite(STDERR, "Worker release identity mismatch\n"); exit(1);
}
require $release.'/vendor/autoload.php';
$app = require $release.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$path = (string) config('integration_transport.supervisor.heartbeat_path');
$heartbeat = json_decode((string) @file_get_contents($path), true);
if (!is_array($heartbeat) || !$app->make(App\Services\Integration\TransportWorkerHeartbeat::class)->isCurrent($heartbeat, $sha)) {
    fwrite(STDERR, "Transport worker native heartbeat has not converged\n"); exit(1);
}
echo "NATIVE_WORKER_HEARTBEAT=PASS\n";
