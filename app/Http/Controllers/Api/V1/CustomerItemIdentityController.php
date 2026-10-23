<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\Customer;
use App\Services\Sales\CustomerItems\CustomerItemIdentityReader;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only SolaCount customer-item identity for a SolaStock customer. */
class CustomerItemIdentityController extends ApiController
{
    public function index(Request $request, Customer $customer, CustomerItemIdentityReader $reader): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'max:200'],
            'item_ids.*' => ['integer', 'min:1'],
        ]);
        $org = app(OrganizationContext::class)->idOrFail();

        return $this->success([
            'customer_id' => (int) $customer->id,
            'identities' => (object) $reader->forCustomerItems($org, (int) $customer->id, $data['item_ids']),
        ]);
    }
}
