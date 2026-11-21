<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\SupplierTerms\SupplierLocations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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

        $this->applyListSort($query, $request, [
            'code' => 'code',
            'name' => 'name',
            'status' => 'is_active',
        ]);

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $supplier->setAttribute('metrics', $this->metrics($supplier));

        return $this->success($supplier);
    }

    /** Linked suppliers: SolaCount's pins (read-only). Standalone: the supplier's own location. */
    public function locations(Supplier $supplier): JsonResponse
    {
        return $this->success(app(SupplierLocations::class)->for($supplier));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateSupplier($request);

        $dupe = Supplier::query()->where('code', $data['code'])->exists();
        if ($dupe) {
            return $this->error('supplier_code_taken', __('inventory.validation.supplier_code_unique'), 422);
        }

        return $this->success(Supplier::create($this->pack($data))->fresh(), 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $this->validateSupplier($request, partial: true, existing: $supplier);
        $this->assertLocationAllowed($data, $supplier);
        $supplier->update($this->pack($data, $supplier));

        return $this->success($supplier->fresh());
    }

    private function validateSupplier(Request $request, bool $partial = false, ?Supplier $existing = null): array
    {
        $req = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'code' => [$req, 'string', 'max:50', \Illuminate\Validation\Rule::unique('inventory_suppliers', 'code')->where('organization_id', app(\App\Tenancy\OrganizationContext::class)->idOrFail())->ignore($existing?->id)],
            'name' => [$req, 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'max:10'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'location' => ['nullable', 'array'],
            'location.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'location.address' => ['nullable', 'string', 'max:500'],
        ], ['code.unique' => __('inventory.validation.supplier_code_unique')]);

        // Pair rule: both or neither (0 is valid). A pair-less object clears the location.
        $loc = $data['location'] ?? null;
        if (is_array($loc)) {
            $lat = $loc['lat'] ?? null;
            $lng = $loc['lng'] ?? null;
            if (($lat === null) !== ($lng === null)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $lat === null ? 'location.lat' : 'location.lng' => __('inventory.validation.coordinates_pair'),
                ]);
            }
        }

        return $data;
    }

    /** Linked suppliers keep their pins in SolaCount; Stock never stores a copy. */
    private function assertLocationAllowed(array $data, Supplier $supplier): void
    {
        if (isset($data['location']['lat']) && app(SupplierLocations::class)->financeId($supplier) !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['location' => __('inventory.validation.location_linked_supplier')]);
        }
    }

    /** Pack flat contact fields into the JSON `contact` column. */
    private function pack(array $data, ?Supplier $existing = null): array
    {
        $contactKeys = ['email', 'phone', 'address', 'tax_number', 'currency', 'payment_terms', 'notes'];
        $contact = (array) ($existing?->contact ?? []);
        if (array_key_exists('location', $data)) {
            $loc = $data['location'];
            if (is_array($loc) && isset($loc['lat'], $loc['lng'])) {
                $contact['location'] = array_filter([
                    'lat' => round((float) $loc['lat'], 7),
                    'lng' => round((float) $loc['lng'], 7),
                    'address' => isset($loc['address']) && $loc['address'] !== '' ? (string) $loc['address'] : null,
                ], fn ($v) => $v !== null);
            } else {
                unset($contact['location']);
            }
            unset($data['location']);
        }
        foreach ($contactKeys as $k) {
            if (array_key_exists($k, $data)) {
                $contact[$k] = $data[$k];
                unset($data[$k]);
            }
        }
        $data['contact'] = $contact;

        return $data;
    }

    private function metrics(Supplier $supplier): array
    {
        $conn = config('tenancy.tenant_connection', 'tenant');
        $po = DB::connection($conn)->table('inventory_purchase_orders')
            ->where('organization_id', $supplier->organization_id)
            ->where('supplier_id', $supplier->id);
        $grns = DB::connection($conn)->table('goods_receipts as g')
            ->leftJoin('inventory_purchase_orders as p', 'p.id', '=', 'g.purchase_order_id')
            ->where('g.organization_id', $supplier->organization_id)
            ->where('g.supplier_id', $supplier->id)
            ->where('g.status', 'posted')
            ->select('g.receipt_date', 'p.order_date', 'p.expected_date')
            ->get();

        $leadDays = $grns
            ->filter(fn ($r) => $r->order_date && $r->receipt_date)
            ->map(fn ($r) => CarbonImmutable::parse($r->order_date)->diffInDays(CarbonImmutable::parse($r->receipt_date), false));
        $expected = $grns->filter(fn ($r) => $r->expected_date && $r->receipt_date);
        $onTime = $expected->filter(fn ($r) => (string) $r->receipt_date <= (string) $r->expected_date)->count();

        return [
            'purchase_orders' => (clone $po)->count(),
            'open_purchase_orders' => (clone $po)->whereIn('status', ['draft', 'approved', 'partially_received'])->count(),
            'received_purchase_orders' => (clone $po)->where('status', 'received')->count(),
            'posted_receipts' => $grns->count(),
            'average_lead_days' => $leadDays->isEmpty() ? null : round($leadDays->avg(), 1),
            'on_time_rate' => $expected->isEmpty() ? null : round(($onTime / max(1, $expected->count())) * 100, 1),
        ];
    }
}
