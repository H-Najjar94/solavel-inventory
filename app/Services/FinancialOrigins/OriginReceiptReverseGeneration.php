<?php
namespace App\Services\FinancialOrigins;

use App\Services\Integration\SolaStockJournalContract;
use Ramsey\Uuid\Uuid;

/** A locked canonical Finance generation, never a caller-selected hold rearm. */
final readonly class OriginReceiptReverseGeneration
{
    private function __construct(private object $row, private array $snapshot, private ?array $quote) {}

    public static function lock($db, object $match, object $request, array $identity, array $proof, string $action): self
    {
        $number=(int)$match->reversal_generation;
        abort_unless($number>0 && (int)($identity['reversal_generation']??0)===$number,409);
        $row=$db->table('finance_document_reverse_generations')->where('organization_id',$match->organization_id)
            ->where('operation_uuid',$match->operation_uuid)->where('generation',$number)->lockForUpdate()->first();
        abort_unless($row && $row->request_uuid===$request->request_uuid && $row->source_revision===$request->source_revision
            && (int)$row->source_journal_id===(int)$request->source_journal_id
            && (int)$row->original_match_journal_id===(int)$match->journal_entry_id
            && $row->reversal_operation_uuid===($identity['reversal_operation_uuid']??null)
            && $row->reversal_operation_uuid===($proof['reversal_operation_uuid']??null)
            && $number===($proof['reversal_generation']??null)
            && $row->state===($proof['reverse_generation_state']??null),409);
        $snapshot=json_decode($row->snapshot,true,512,JSON_THROW_ON_ERROR);
        abort_unless(is_array($snapshot) && hash_equals(SolaStockJournalContract::payloadHash($snapshot),(string)($proof['reverse_snapshot_hash']??''))
            && is_array($proof['reverse_generation_snapshot']??null)
            && SolaStockJournalContract::canonicalJson($snapshot)===SolaStockJournalContract::canonicalJson($proof['reverse_generation_snapshot']),409);
        foreach(['phase'=>'match_inverse_before_expense_unpost','original_source_journal_id'=>(int)$request->source_journal_id,
            'original_match_journal_id'=>(int)$match->journal_entry_id,'request_uuid'=>$request->request_uuid,'source_revision'=>$request->source_revision,
            'reverse_actor_id'=>(int)$row->actor_id,'reverse_central_actor_id'=>(int)$row->central_actor_id,'closure_permission'=>'unpost',
            'cancel_request_uuid'=>$request->request_uuid,'cancel_source_revision'=>$request->source_revision,'cancel_state'=>'cancelled','cancel_command'=>'cancel',
            'reversal_generation'=>$number,'reversal_operation_uuid'=>$row->reversal_operation_uuid]as$key=>$value){abort_unless(($snapshot[$key]??null)===$value,409);}
        abort_unless(array_key_exists('cancel_expected_revision',$snapshot)
            && $snapshot['cancel_expected_revision']===($request->command_expected_revision??null)
            && $row->closure_permission==='unpost' && (int)$row->actor_id>0 && (int)$row->central_actor_id>0
            && (int)($match->reversal_journal_id??0)===(int)($row->inverse_journal_id??0),409);
        $states=match($action){'prepare'=>['prepared','quote_held'],'reverse'=>['valuation_pending','committed'],'release'=>['release_pending'],default=>['prepared','quote_held','valuation_pending','committed','release_pending','released']};
        abort_unless(in_array($row->state,$states,true),409);
        $quote=$row->reverse_quote===null?null:json_decode($row->reverse_quote,true,512,JSON_THROW_ON_ERROR);
        abort_unless(($quote===null && ($proof['reverse_quote_hash']??null)===null)
            || (is_array($quote) && hash_equals(SolaStockJournalContract::payloadHash($quote),(string)($proof['reverse_quote_hash']??''))
                && ($quote['reversal_generation']??null)===$number && ($quote['reversal_operation_uuid']??null)===$row->reversal_operation_uuid
                && ($quote['plan_fingerprint']??null)===$row->plan_fingerprint),409);
        if($action==='release'){
            $release=json_decode($row->release_snapshot??'null',true,512,JSON_THROW_ON_ERROR);
            abort_unless(!$row->inverse_journal_id && !$match->reversal_journal_id && is_array($release)
                && ($release['reversal_generation']??null)===$number && ($release['reversal_operation_uuid']??null)===$row->reversal_operation_uuid
                && ($release['release_operation_uuid']??null)===$row->release_operation_uuid
                && ($release['request_uuid']??null)===$request->request_uuid && ($release['position_uuid']??null)===$match->position_uuid
                && (int)($release['original_match_journal_id']??0)===(int)$match->journal_entry_id
                && array_key_exists('unpost_audit_id',$release) && $release['unpost_audit_id']===null,409);
        }
        return new self($row,$snapshot,$quote);
    }
    /** Every earlier generation must have a durable ACK and its original native hold released. */
    public function assertPredecessorsReleased($db, object $match, object $request, int $stockOrg, string $mappingUuid, int $item, int $warehouse, int $receipt): void
    {
        if ($this->number() === 1) return;
        $rows = $db->table('finance_document_reverse_generations')->where('organization_id', $match->organization_id)
            ->where('operation_uuid', $match->operation_uuid)->where('generation', '<', $this->number())
            ->orderBy('generation')->lockForUpdate()->get();
        abort_unless($rows->count() === $this->number() - 1, 409);
        foreach ($rows as $offset => $prior) {
            abort_unless((int) $prior->generation === $offset + 1 && $prior->state === 'released' && $prior->released_at
                && !$prior->inverse_journal_id && $prior->request_uuid === $request->request_uuid
                && $prior->source_revision === $request->source_revision && (int) $prior->source_journal_id === (int) $request->source_journal_id
                && (int) $prior->original_match_journal_id === (int) $match->journal_entry_id, 409);
            $release = json_decode($prior->release_snapshot ?? 'null', true, 512, JSON_THROW_ON_ERROR);
            $quote = json_decode($prior->reverse_quote ?? 'null', true, 512, JSON_THROW_ON_ERROR);
            abort_unless(is_array($release) && is_array($quote) && $prior->plan_fingerprint
                && ($release['purpose'] ?? null) === 'abandon_match' && ($release['direction'] ?? null) === 'reverse'
                && ($release['release_operation_uuid'] ?? null) === $prior->release_operation_uuid
                && ($release['reverse_hold_fingerprint'] ?? null) === $prior->plan_fingerprint
                && ($quote['plan_fingerprint'] ?? null) === $prior->plan_fingerprint
                && ($release['quote']['abandoned'] ?? null) === true, 409);
            $abandoned = $release['quote']; unset($abandoned['abandoned']);
            abort_unless(SolaStockJournalContract::canonicalJson($abandoned) === SolaStockJournalContract::canonicalJson($quote), 409);
            $ack = $release['acknowledgement'] ?? null;
            foreach (['state' => 'abandoned', 'operation_uuid' => $match->operation_uuid,
                'reversal_generation' => (int) $prior->generation, 'reversal_operation_uuid' => $prior->reversal_operation_uuid,
                'plan_fingerprint' => $prior->plan_fingerprint] as $key => $value) abort_unless(is_array($ack) && ($ack[$key] ?? null) === $value, 409);
            $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, 'financial-origin|'.$mappingUuid.'|'.$prior->reversal_operation_uuid.'|'.$item.'|'.$warehouse.'|reverse')->toString();
            // Native released holds are terminal/immutable. Read without acquiring a hold-before-item
            // lock; the Held service retains the existing item -> current hold lock order.
            $hold = $db->table('purchase_valuation_holds')->where('organization_id', $stockOrg)->where('settlement_uuid', $uuid)
                ->where('purpose', 'origin_reverse')->where('plan_revision', 1)->first();
            abort_unless($hold && $hold->state === 'released' && $hold->plan_fingerprint === $prior->plan_fingerprint
                && (int) $hold->item_id === $item && (int) $hold->warehouse_id === $warehouse && (int) $hold->receipt_id === $receipt
                && $hold->source_document_type === 'expense' && (int) $hold->source_document_id === (int) $request->source_document_id
                && (int) $hold->source_journal_id === (int) $request->source_journal_id && $hold->source_bill_id === null, 409);
        }
    }
    public function number():int{return (int)$this->row->generation;}
    public function operationUuid():string{return $this->row->reversal_operation_uuid;}
    public function quote():?array{return $this->quote;}
    /** Read-only compatibility projection into the existing native lifecycle validator. */
    public function lifecycleMatch(object $match):object
    {
        $copy=clone $match;
        $copy->reverse_actor_id=$this->row->actor_id;$copy->reverse_central_actor_id=$this->row->central_actor_id;
        $copy->closure_permission=$this->row->closure_permission;$copy->reversal_operation_uuid=$this->row->reversal_operation_uuid;
        $copy->reversal_journal_id=$this->row->inverse_journal_id;
        $copy->reversal_snapshot=json_encode($this->snapshot+['match_inverse_journal_id'=>$this->row->inverse_journal_id,'hold_fingerprint'=>$this->row->plan_fingerprint],JSON_THROW_ON_ERROR);
        $copy->reverse_state=$this->row->state==='release_pending'?'abandoned':$this->row->state;
        $copy->release_state=$this->row->state==='release_pending'?'pending':null;
        $copy->release_operation_uuid=$this->row->release_operation_uuid;$copy->release_snapshot=$this->row->release_snapshot;
        return $copy;
    }
}
