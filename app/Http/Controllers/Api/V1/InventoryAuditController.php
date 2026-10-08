<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\InventoryAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryAuditController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = InventoryAuditLog::query()
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', '%'.$request->query('action').'%'))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->query('entity_type')))
            ->when($request->filled('entity_id'), fn ($q) => $q->where('entity_id', (int) $request->query('entity_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->query('to')))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->through(fn (InventoryAuditLog $row) => [
            'id' => $row->id,
            'created_at' => optional($row->created_at)->toDateTimeString(),
            'actor_user_id' => $row->actor_user_id,
            'action' => $row->action,
            'entity_type' => $row->entity_type,
            'entity_id' => $row->entity_id,
            'document_ref' => $row->document_ref,
            'ip' => $row->ip,
            'before' => $row->before,
            'after' => $row->after,
        ]));
    }
}
