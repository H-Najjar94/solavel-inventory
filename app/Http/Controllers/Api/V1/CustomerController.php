<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 100);
        $query = Customer::query()
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->query('search').'%')
                ->orWhere('code', 'like', '%'.$request->query('search').'%')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(Customer $customer): JsonResponse
    {
        return $this->success($customer);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateCustomer($request);
        if (Customer::query()->where('code', $data['code'])->exists()) {
            return $this->error('customer_code_taken', __('inventory.validation.customer_code_unique'), 422);
        }

        return $this->success(Customer::create($this->pack($data))->fresh(), 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $this->validateCustomer($request, partial: true);
        $customer->update($this->pack($data, $customer));

        return $this->success($customer->fresh());
    }

    private function validateCustomer(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$req, 'string', 'max:50'],
            'name' => [$req, 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'max:10'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);
    }

    private function pack(array $data, ?Customer $existing = null): array
    {
        $contactKeys = ['email', 'phone', 'address', 'tax_number', 'currency', 'payment_terms', 'notes'];
        $contact = (array) ($existing?->contact ?? []);
        foreach ($contactKeys as $key) {
            if (array_key_exists($key, $data)) {
                $contact[$key] = $data[$key];
                unset($data[$key]);
            }
        }
        $data['contact'] = $contact;

        return $data;
    }
}
