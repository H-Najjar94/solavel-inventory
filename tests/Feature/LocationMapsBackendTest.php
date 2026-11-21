<?php

namespace Tests\Feature;

use App\Http\Requests\Api\StoreWarehouseRequest;
use App\Services\Geo\GeocodingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class LocationMapsBackendTest extends TestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.default' => 'tenant', 'database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array', 'services.geocoding.provider' => 'nominatim']);

        return $app;
    }

    private function errors(array $input): array
    {
        $request = StoreWarehouseRequest::create('/x', 'POST', $input);
        $request->setContainer($this->app);
        $m = new \ReflectionMethod($request, 'prepareForValidation');
        $m->setAccessible(true);
        $m->invoke($request);
        $v = Validator::make($request->all(), $request->rules());
        $request->withValidator($v);
        $v->after(fn () => null);

        return $v->errors()->messages();
    }

    public function test_migration_adds_nullable_coordinates_and_is_idempotent(): void
    {
        Schema::connection('tenant')->create('warehouses', fn (Blueprint $t) => $t->id());
        $migration = require base_path('database/migrations/tenant/2026_11_21_100000_add_coordinates_to_warehouses.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::connection('tenant')->hasColumns('warehouses', ['latitude', 'longitude']));
    }

    public function test_coordinate_rules_accept_zero_and_reject_out_of_range(): void
    {
        $base = ['code' => 'W1', 'name' => 'W', 'type' => 'warehouse'];
        $this->assertArrayNotHasKey('latitude', $this->errors($base + ['latitude' => 0, 'longitude' => 0]));
        $this->assertArrayNotHasKey('latitude', $this->errors($base + ['latitude' => '', 'longitude' => '']));
        $this->assertArrayHasKey('latitude', $this->errors($base + ['latitude' => 91, 'longitude' => 10]));
        $this->assertArrayHasKey('longitude', $this->errors($base + ['latitude' => 10, 'longitude' => -181]));
        $this->assertArrayHasKey('latitude', $this->errors($base + ['latitude' => 'abc', 'longitude' => 10]));
    }

    public function test_geocoding_normalizes_caches_and_degrades(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response([['name' => 'Abdali Mall', 'display_name' => 'Abdali Mall, Amman, Jordan', 'lat' => '31.9660000', 'lon' => '35.9070000',
            'address' => ['suburb' => 'Abdali', 'city' => 'Amman', 'country' => 'Jordan', 'country_code' => 'jo']]])]);
        $svc = new GeocodingService;
        $r = $svc->search('  Abdali   Mall ', 'en', ['jo'], null, null);
        $this->assertSame('Abdali', $r['results'][0]['area']);
        $this->assertSame(31.966, $r['results'][0]['lat']);
        $svc->search('abdali mall', 'en', ['jo'], null, null);
        Http::assertSentCount(1); // second call served from cache

        Http::fake(['*' => Http::response('', 500)]);
        $fail = $svc->search('something else', 'en', [], null, null);
        $this->assertSame(['results' => [], 'unavailable' => true], $fail);
    }

    public function test_geocoding_disabled_is_unavailable(): void
    {
        config(['services.geocoding.provider' => 'disabled']);
        Http::fake();
        $this->assertTrue((new GeocodingService)->search('amman', 'ar', [], null, null)['unavailable']);
        Http::assertNothingSent();
    }
}
