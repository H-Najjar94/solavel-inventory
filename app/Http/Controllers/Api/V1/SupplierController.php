<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 100);
        $query = Supplier::query()
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->query('search').'%')
                ->orWhere('code', 'like', '%'.$request->query('search').'%')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return $this->success($supplier);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateSupplier($request);

        $dupe = Supplier::query()->where('code', $data['code'])->exists();
        if ($dupe) {
            return $this->error('supplier_code_taken', 'Supplier code must be unique.', 422);
        }

        return $this->success(Supplier::create($this->pack($data))->fresh(), 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $this->validateSupplier($request, partial: true);
        $supplier->update($this->pack($data, $supplier));

        return $this->success($supplier->fresh());
    }

    private function validateSupplier(Request $request, bool $partial = false): array
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

    /** Pack flat contact fields into the JSON `contact` column. */
    private function pack(array $data, ?Supplier $existing = null): array
    {
        $contactKeys = ['email', 'phone', 'address', 'tax_number', 'currency', 'payment_terms', 'notes'];
        $contact = (array) ($existing?->contact ?? []);
        foreach ($contactKeys as $k) {
            if (array_key_exists($k, $data)) {
                $contact[$k] = $data[$k];
                unset($data[$k]);
            }
        }
        $data['contact'] = $contact;

        return $data;
    }
}
