<?php

namespace Tests\Feature\Warehouse;

use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Requests\Api\StoreWarehouseRequest;
use App\Http\Requests\Api\UpdateWarehouseRequest;
use App\Models\Tenant\Warehouse;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Warehouse create/edit. The form sends `address` as a structured object
 * ({line1, city, country}); the column is longtext and the model now casts it
 * to array. Before the cast, Eloquent wrote the PHP array to the string column
 * → "Array to string conversion" (the live production error). These tests drive
 * the real request → validation → store/update path with the UI payload.
 */
class WarehouseCreateTest extends TestCase
{
    use TenantAware;

    private function createViaRequest(array $payload): array
    {
        $req = StoreWarehouseRequest::create('/api/v1/warehouses', 'POST', $payload);
        $req->setContainer(app())->setRedirector(app('redirect'));
        $req->validateResolved();

        return app(WarehouseController::class)->store($req)->getData(true);
    }

    private function updateViaRequest(Warehouse $wh, array $payload): array
    {
        $req = UpdateWarehouseRequest::create("/api/v1/warehouses/{$wh->id}", 'PUT', $payload);
        $req->setContainer(app())->setRedirector(app('redirect'));
        $req->setRouteResolver(fn () => tap(new \Illuminate\Routing\Route('PUT', '/warehouses/{warehouse}', []),
            fn ($r) => $r->bind($req)->setParameter('warehouse', $wh)));
        $req->validateResolved();

        return app(WarehouseController::class)->update($req, $wh)->getData(true);
    }

    private function formPayload(array $over = []): array
    {
        // Mirrors WarehouseFormPage EMPTY: structured address + blank capacity.
        return array_merge([
            'code' => 'WH-1', 'name' => 'Main', 'type' => 'warehouse', 'is_active' => true,
            'address' => ['line1' => '1 Test St', 'city' => 'Riyadh', 'country' => 'SA'],
            'max_capacity_units' => '',
        ], $over);
    }

    #[Test]
    public function creating_a_warehouse_with_a_structured_address_succeeds(): void
    {
        $this->useTenantA();

        $resp = $this->createViaRequest($this->formPayload());

        $this->assertSame('WH-1', $resp['data']['code']);
        $wh = Warehouse::query()->where('code', 'WH-1')->firstOrFail();
        // address round-trips as an array (the regression was a 500 here).
        $this->assertSame('Riyadh', $wh->address['city']);
        $this->assertNull($wh->max_capacity_units);
    }

    #[Test]
    public function created_warehouse_appears_in_list_and_opens_in_detail(): void
    {
        $this->useTenantA();
        $this->createViaRequest($this->formPayload(['code' => 'WH-LIST', 'name' => 'Listed WH']));

        $list = app(WarehouseController::class)->index(Request::create('/', 'GET'))->getData(true);
        $this->assertContains('WH-LIST', array_column($list['data'], 'code'));
        // The list payload also exposes the (null) primary image without crashing.
        $row = collect($list['data'])->firstWhere('code', 'WH-LIST');
        $this->assertArrayHasKey('primary_image_url', $row);
        $this->assertNull($row['primary_image_url']);

        $wh = Warehouse::query()->where('code', 'WH-LIST')->firstOrFail();
        $detail = app(WarehouseController::class)->show($wh)->getData(true)['data'];
        $this->assertSame('Listed WH', $detail['warehouse']['name']);
        // Detail must not break when there is no image.
        $this->assertNull($detail['primary_image_url']);
        $this->assertSame([], $detail['images']);
    }

    #[Test]
    public function editing_a_warehouse_address_succeeds(): void
    {
        $this->useTenantA();
        $this->createViaRequest($this->formPayload(['code' => 'WH-EDIT']));
        $wh = Warehouse::query()->where('code', 'WH-EDIT')->firstOrFail();

        $this->updateViaRequest($wh, [
            'code' => 'WH-EDIT', 'name' => 'Renamed', 'type' => 'retail', 'is_active' => false,
            'address' => ['line1' => '2 New Rd', 'city' => 'Jeddah', 'country' => 'SA'],
        ]);

        $fresh = $wh->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame('Jeddah', $fresh->address['city']);
        $this->assertFalse($fresh->is_active);
    }

    #[Test]
    public function a_warehouse_with_no_address_or_capacity_still_saves(): void
    {
        $this->useTenantA();
        $resp = $this->createViaRequest([
            'code' => 'WH-MIN', 'name' => 'Minimal', 'type' => 'warehouse',
        ]);
        $this->assertSame('WH-MIN', $resp['data']['code']);
        $this->assertNotNull(Warehouse::query()->where('code', 'WH-MIN')->first());
    }

    #[Test]
    public function duplicate_code_in_the_same_org_is_rejected_with_a_clear_error(): void
    {
        $this->useTenantA();
        $this->createViaRequest($this->formPayload(['code' => 'DUP', 'name' => 'First']));

        try {
            $this->createViaRequest($this->formPayload(['code' => 'DUP', 'name' => 'Second']));
            $this->fail('expected a validation error for duplicate warehouse code');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
            $this->assertStringContainsString('unique', strtolower($e->errors()['code'][0]));
        }
    }

    #[Test]
    public function the_same_code_is_allowed_in_a_different_org(): void
    {
        // ORG_A uses the code…
        $this->useTenantA();
        $this->createViaRequest($this->formPayload(['code' => 'SHARED', 'name' => 'A warehouse']));

        // …ORG_B (different reserved tenant DB) may use the same code.
        $this->useTenantB();
        $resp = $this->createViaRequest($this->formPayload(['code' => 'SHARED', 'name' => 'B warehouse']));
        $this->assertSame('SHARED', $resp['data']['code']);
    }

    #[Test]
    public function updating_a_warehouse_keeping_its_own_code_succeeds(): void
    {
        $this->useTenantA();
        $this->createViaRequest($this->formPayload(['code' => 'KEEP', 'name' => 'Keep me']));
        $wh = Warehouse::query()->where('code', 'KEEP')->firstOrFail();

        // Same code, changed name — must NOT trip the uniqueness rule on itself.
        $this->updateViaRequest($wh, ['code' => 'KEEP', 'name' => 'Renamed', 'type' => 'warehouse']);
        $this->assertSame('Renamed', $wh->fresh()->name);
    }

    #[Test]
    public function updating_to_another_warehouses_code_in_the_same_org_is_rejected(): void
    {
        $this->useTenantA();
        $this->createViaRequest($this->formPayload(['code' => 'AAA', 'name' => 'A']));
        $this->createViaRequest($this->formPayload(['code' => 'BBB', 'name' => 'B']));
        $whB = Warehouse::query()->where('code', 'BBB')->firstOrFail();

        try {
            $this->updateViaRequest($whB, ['code' => 'AAA', 'name' => 'B', 'type' => 'warehouse']);
            $this->fail('expected a validation error when colliding with another warehouse code');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }
    }
}
