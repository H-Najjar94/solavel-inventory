<?php

// Copy to tests/Feature/GeoSearchTest.php (route /geo/search must exist and be reachable by the test user).

namespace Tests\Feature;

use App\Services\Geo\GeocodingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\TestCase;

class GeoSearchTest extends TestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.default' => 'tenant', 'database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'cache.default' => 'array']);

        return $app;
    }

    private string $lockDir;

    private array $row = [
        'lat' => '31.9539', 'lon' => '35.9106', 'name' => 'Abdali', 'display_name' => 'Abdali, Amman, Jordan',
        'address' => ['neighbourhood' => 'Abdali', 'city' => 'Amman', 'country' => 'Jordan', 'country_code' => 'jo'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->lockDir = sys_get_temp_dir().'/geo-lock-'.uniqid();
        config(['services.geocoding' => ['provider' => 'nominatim', 'user_agent' => 'Solavel/1.0 (+https://solavel.com)', 'lock_dir' => $this->lockDir]]);
    }

    public function test_nominatim_results_are_normalized_and_cached(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([$this->row])]);
        $svc = app(GeocodingService::class);

        $a = $svc->search('Abdali', 'en', ['jo', 'ae']);
        $b = $svc->search('abdali', 'en', ['ae', 'jo']);

        $this->assertFalse($a['unavailable']);
        $this->assertSame(['label' => 'Abdali, Amman, Jordan', 'name' => 'Abdali', 'area' => 'Abdali', 'city' => 'Amman', 'country' => 'Jordan', 'country_code' => 'jo', 'lat' => 31.9539, 'lng' => 35.9106], $a['results'][0]);
        $this->assertSame($a, $b);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'countrycodes=ae%2Cjo') && $r->hasHeader('User-Agent', 'Solavel/1.0 (+https://solavel.com)') && str_contains($r->url(), 'accept-language=en'));
    }

    public function test_locationiq_uses_key_and_treats_404_as_empty(): void
    {
        config(['services.geocoding' => ['provider' => 'locationiq', 'key' => 'k123']]);
        Http::fake(['us1.locationiq.com/*' => Http::response(['error' => 'Unable to geocode'], 404)]);

        $out = app(GeocodingService::class)->search('zzzz nowhere', 'en');

        $this->assertSame(['results' => [], 'unavailable' => false], $out);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'key=k123'));
    }

    public function test_upstream_failure_is_graceful_and_not_cached(): void
    {
        // Stubs are matched first-registered-first, so use one sequence (500, then success).
        Http::fake(['*' => Http::sequence()->push('boom', 500)->push([$this->row])]);
        $out = app(GeocodingService::class)->search('Amman', 'ar');
        $this->assertSame(['results' => [], 'unavailable' => true], $out);

        $this->assertCount(1, app(GeocodingService::class)->search('Amman', 'ar')['results']);
    }

    public function test_disabled_provider_is_unavailable_without_http(): void
    {
        config(['services.geocoding.provider' => 'disabled']);
        Http::fake();
        $this->assertTrue(app(GeocodingService::class)->search('Amman')['unavailable']);
        Http::assertNothingSent();
    }

    public function test_invalid_coordinates_are_dropped(): void
    {
        $bad = $this->row; $bad['lat'] = '95';
        Http::fake(['*' => Http::response([$bad, $this->row])]);
        $this->assertCount(1, app(GeocodingService::class)->search('x1')['results']);
    }

    public function test_endpoint_validates_and_returns_json(): void
    {
        Http::fake(['*' => Http::response([$this->row])]);
        $ctl = app(\App\Http\Controllers\Api\V1\GeoSearchController::class);
        $req = fn (array $q) => \Illuminate\Http\Request::create('/geo/search', 'GET', $q);
        try {
            $ctl->search($req([]), app(GeocodingService::class));
            $this->fail('expected validation failure');
        } catch (\Illuminate\Validation\ValidationException) {
        }
        $out = $ctl->search($req(['q' => 'Abdali', 'lang' => 'en', 'countries' => 'jo,ae', 'lat' => 31.9, 'lng' => 35.9, 'viewbox' => '35.4,32.4,36.4,31.4']), app(GeocodingService::class))->getData(true);
        $this->assertSame('Amman', $out['results'][0]['city']);
        $this->assertFalse($out['unavailable']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'viewbox=') && str_contains($r->url(), 'accept-language=en') && str_contains($r->url(), 'countrycodes='));
    }

    public function test_lock_dir_and_files_are_world_usable_for_other_users(): void
    {
        Http::fake(['*' => Http::response([$this->row])]);
        $dir = $this->lockDir.'-perm';
        config(['services.geocoding.lock_dir' => $dir]);
        app(GeocodingService::class)->search('perm check');
        $this->assertSame('1777', substr(sprintf('%o', fileperms($dir)), -4));
        $this->assertSame('0666', substr(sprintf('%04o', fileperms($dir.'/upstream.lock')), -4));
        $this->assertSame('0666', substr(sprintf('%04o', fileperms($dir.'/last-request')), -4));
    }

    public function test_upstream_calls_are_throttled_across_processes(): void
    {
        $svc = <<<'PHP'
        <?php
        require $argv[1];
        $s = new class extends App\Services\Geo\GeocodingService {
            public function run(string $dir) { return $this->throttled(fn () => microtime(true), $dir); }
        };
        echo sprintf('%.6F', $s->run($argv[2]));
        PHP;
        $script = $this->lockDir.'-run.php';
        @mkdir($this->lockDir);
        file_put_contents($script, $svc);
        $autoload = base_path('vendor/autoload.php');
        $procs = [];
        foreach ([1, 2] as $i) {
            $procs[$i] = proc_open([PHP_BINARY, $script, $autoload, $this->lockDir], [1 => ['pipe', 'w']], $pipes[$i]);
        }
        $times = [];
        foreach ($procs as $i => $p) {
            $times[] = (float) stream_get_contents($pipes[$i][1]);
            proc_close($p);
        }
        sort($times);
        $this->assertGreaterThanOrEqual(1.0, $times[1] - $times[0]);
    }

    public function test_lock_timeout_returns_unavailable(): void
    {
        @mkdir($this->lockDir, 0777, true);
        $fh = fopen($this->lockDir.'/upstream.lock', 'c');
        flock($fh, LOCK_EX);
        Http::fake();
        $t = microtime(true);
        $out = app(GeocodingService::class)->search('Amman timeout');
        flock($fh, LOCK_UN);
        $this->assertTrue($out['unavailable']);
        $this->assertGreaterThanOrEqual(2.9, microtime(true) - $t);
        Http::assertNothingSent();
    }
}
