<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\HistoricalFifoPlan;
use App\Services\Stock\Historical\HistoricalFifoReviewService;
use App\Services\Stock\StockLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

final class HistoricalFifoController extends ApiController
{
    public function review(Request $request, HistoricalFifoReviewService $reviews)
    {
        $this->authorized($request, 'historical-fifo.review');
        $data = $request->validate(['correction_uuid' => 'required|uuid', 'batch_id' => 'required|uuid', 'events' => 'required|array|min:1|max:5000',
            'openings' => 'required|array', 'reviewed' => 'required|accepted']);
        $plan = $reviews->review($data['correction_uuid'], $data['batch_id'], $data['events'], $data['openings'], (int) $request->user()->id);
        return $this->success(['plan_id' => $plan->id, 'plan_sha256' => $plan->plan_sha256, 'status' => $plan->status, 'blocked_items' => $plan->plan['blocked_items']]);
    }
    public function apply(Request $request, int $plan, StockLedgerService $stock)
    {
        $this->authorized($request, 'historical-fifo.apply');
        $data = $request->validate(['plan_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'reviewed' => 'required|accepted']);
        $review = HistoricalFifoPlan::query()->findOrFail($plan);
        abort_unless((int) $review->reviewed_by_central_id === (int) $request->user()->id && hash_equals($review->plan_sha256, $data['plan_sha256']), 403, 'historical_fifo_review_identity_mismatch');
        return $this->success($stock->applyHistoricalFifo($review));
    }
    private function authorized(Request $request, string $action): void
    {
        abort_unless($request->attributes->get('verified_workspace_action') === $action, 403, 'signed_migration_workspace_required');
        abort_unless(Schema::connection('tenant')->hasTable('historical_fifo_plans') && Schema::connection('tenant')->hasTable('historical_fifo_corrections'), 409, 'historical_fifo_schema_not_ready');
    }
}
