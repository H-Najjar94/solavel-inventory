<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\Supplier;
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

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $supplier->setAttribute('metrics', $this->metrics($supplier));

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
