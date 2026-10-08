<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Standard JSON envelope, mirroring solavel-finance's ExternalApiController shape
 * so the Solavel suite stays consistent:
 *   success: { "success": true, "data": ..., "meta": {...}? }
 *   error:   { "success": false, "error": { "code", "message", ...extra } }
 */
trait ApiResponses
{
    protected function success(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        $payload = ['success' => true, 'data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    protected function paginated(LengthAwarePaginator $paginator): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    protected function error(string $code, string $message, int $status = 422, array $extra = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => array_merge(['code' => $code, 'message' => $message], $extra),
        ], $status);
    }
}
