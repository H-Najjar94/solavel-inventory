<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Requests\Api\StoreItemRequest;
use App\Models\Tenant\ItemCategory;
use App\Models\Tenant\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class StandardCostingContractTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function item_creation_rejects_a_costing_method_the_ledger_engine_cannot_execute(): void
    {
        $this->useTenantA();
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => 'Audit standard costing'],
            ['level' => 0, 'is_active' => true]
        );
        $unit = Unit::query()->firstOrCreate(
            ['code' => 'AUD-EA'],
            ['name' => 'Audit each', 'symbol' => 'ea', 'is_active' => true]
        );
        $request = StoreItemRequest::create('/api/v1/items', 'POST', [
            'sku' => 'AUD-STANDARD',
            'name' => 'Unsupported standard-cost item',
            'item_type' => 'inventory',
            'tracking_type' => 'none',
            'category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'costing_method' => 'standard',
            'is_active' => true,
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));

        try {
            $request->validateResolved();
            $this->fail('Validation admitted standard costing even though CostingEngine rejects it.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('costing_method', $exception->errors());
        }
    }

    #[Test]
    public function organization_settings_reject_an_unsupported_default_costing_method(): void
    {
        $this->useTenantA();
        $request = Request::create('/api/v1/settings', 'PUT', [
            'default_costing_method' => 'standard',
        ]);

        $this->expectException(ValidationException::class);
        app(SettingsController::class)->updateSettings($request);
    }
}

