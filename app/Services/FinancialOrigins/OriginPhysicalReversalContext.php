<?php
namespace App\Services\FinancialOrigins;

use App\Models\Tenant\{FinancialOriginCommand,FinancialOriginOutbox,FinancialOriginRequest,IntegrationOrganizationMapping};
use App\Services\Integration\SolaStockJournalContract;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;

/** Accounting proof is captured before SQL locks; every persisted fact is fenced again under locks. */
final readonly class OriginPhysicalReversalContext
{
    private function __construct(private array $facts,private array $proof,private int $organizationId,private int $commandId) {}

    public static function fromProof(array $facts,array $proof,int $organizationId,int $commandId):self
    {
        abort_unless(DB::connection('tenant')->transactionLevel()===0 && $organizationId===app(OrganizationContext::class)->idOrFail(),409);
        abort_unless(($proof['allowed']??false)===true && ($proof['actor_id']??null)===0
            && ($proof['authority_kind']??null)==='posted_financial_origin_physical_reversal',403);
        foreach($facts as$key=>$value)abort_unless(array_key_exists($key,$proof) && (string)$proof[$key]===(string)$value,403);
        // Other source policies require their own native financial inverse qualification.
        abort_unless($facts['source_document_type']==='expense' && $facts['physical_document_type']==='goods_receipt',409);
        return new self($facts,$proof,$organizationId,$commandId);
    }

    public function lockAndValidate():FinancialOriginRequest
    {
        $db=DB::connection('tenant');$f=$this->facts;$p=$this->proof;
        abort_unless($db->transactionLevel()>0 && $this->organizationId===app(OrganizationContext::class)->idOrFail(),409);
        $financeOrg=(int)($p['finance_organization_id']??0);
        $expense=$db->table('expenses')->where('organization_id',$financeOrg)->where('id',$f['source_document_id'])->lockForUpdate()->first();
        abort_unless($expense && $expense->status==='draft',409);
        $intent=$db->table('finance_document_requests')->where('organization_id',$financeOrg)->where('request_uuid',$f['request_uuid'])
            ->where('source_document_type','expense')->where('source_document_id',$expense->id)->where('source_journal_id',$f['source_journal_id'])->lockForUpdate()->first();
        abort_unless($intent && $intent->side==='purchase' && $intent->command==='cancel' && $intent->state==='cancelled'
            && $intent->source_revision===$f['source_revision'] && $intent->organization_mapping_uuid===($p['organization_mapping_uuid']??null),409);
        $positions=$db->table('finance_document_positions')->where('organization_id',$financeOrg)->where('request_uuid',$intent->request_uuid)
            ->orderBy('position_uuid')->lockForUpdate()->get();
        foreach($positions as$position)abort_unless($position->side==='purchase' && $position->source_document_type==='expense'
            && (int)$position->source_document_id===(int)$expense->id && (int)$position->source_journal_id===(int)$f['source_journal_id'],409);
        $matches=$db->table('finance_document_matches')->where('organization_id',$financeOrg)->where('request_uuid',$intent->request_uuid)
            ->orderBy('operation_uuid')->lockForUpdate()->get();
        $presented=(array)($p['matches']??[]);$actual=[];
        foreach($matches as$match){
            if($match->state==='abandoned' && !$match->journal_entry_id && !$match->reversal_journal_id)continue;
            abort_unless($match->state==='reversed' && $match->reverse_state==='committed' && $match->closure_state==='completed'
                && $positions->contains(fn($position)=>$position->position_uuid===$match->position_uuid
                    && (int)$position->source_document_line_id===(int)$match->source_document_line_id),409);
            $snapshot=json_decode($match->closure_snapshot??'null',true,512,JSON_THROW_ON_ERROR);
            abort_unless(is_array($snapshot) && ($snapshot['phase']??null)==='expense_unposted_after_value_ack'
                && ($snapshot['request_uuid']??null)===$intent->request_uuid && ($snapshot['source_revision']??null)===$intent->source_revision
                && ($snapshot['reversal_operation_uuid']??null)===$match->reversal_operation_uuid
                && (int)($snapshot['original_source_journal_id']??0)===(int)$f['source_journal_id']
                && (int)($snapshot['original_match_journal_id']??0)===(int)$match->journal_entry_id
                && (int)($snapshot['match_inverse_journal_id']??0)===(int)$match->reversal_journal_id
                && ($snapshot['source_document_type']??null)==='App\\Models\\Expense'
                && (int)($snapshot['source_document_id']??0)===(int)$expense->id && ($snapshot['source_status']??null)==='draft'
                && ($snapshot['unpost_audit_controller']??null)==='ReversalEngine' && ($snapshot['unpost_audit_method']??null)==='unpostExpense',409);
            $source=$db->table('journal_entries')->where('organization_id',$financeOrg)->where('id',$f['source_journal_id'])
                ->where('source','AP-EXPENSE')->where('source_type','App\\Models\\Expense')->where('source_id',$expense->id)->lockForUpdate()->first();
            abort_unless($source && $source->status==='voided' && !empty($source->voided_at)
                && (int)$source->voided_by===(int)$snapshot['unpost_actor_id']
                && (int)($snapshot['source_voided_by']??0)===(int)$source->voided_by
                && (string)($snapshot['source_voided_at']??'')===(string)$source->voided_at,409);
            $audit=$db->table('action_logs')->where('id',$snapshot['unpost_audit_id']??0)->where('controller','ReversalEngine')
                ->where('method','unpostExpense')->where('user_id',$snapshot['unpost_actor_id'])->lockForUpdate()->first();
            $data=$audit?json_decode($audit->data,true,512,JSON_THROW_ON_ERROR):null;
            abort_unless($audit && ($data['document_type']??null)==='App\\Models\\Expense'
                && (int)($data['document_id']??0)===(int)$expense->id
                && (int)($data['voided_journal_entry_id']??0)===(int)$source->id,409);
            abort_unless($db->table('users')->where('id',$snapshot['unpost_actor_id'])->where('central_user_id',$snapshot['unpost_central_actor_id']??0)->exists(),409);
            $original=$db->table('journal_entries')->where('organization_id',$financeOrg)->where('id',$match->journal_entry_id)
                ->where('source','FINANCIAL-ORIGIN')->where('source_type','App\\Models\\Expense')->where('source_id',$expense->id)
                ->where('source_key','financial-origin-match:'.$match->operation_uuid)->lockForUpdate()->first();
            abort_unless($original && $original->status==='posted' && !empty($original->posted_at) && empty($original->voided_at) && empty($original->deleted_at),409);
            $inverse=$db->table('journal_entries')->where('organization_id',$financeOrg)->where('id',$match->reversal_journal_id)
                ->where('source','FINANCIAL-ORIGIN')->where('source_type','App\\Models\\Expense')->where('source_id',$expense->id)
                ->where('source_key','financial-origin-match-reversal:'.$match->operation_uuid)->where('reverses_entry_id',$match->journal_entry_id)->lockForUpdate()->first();
            abort_unless($inverse && $inverse->status==='posted' && !empty($inverse->posted_at) && empty($inverse->voided_at) && empty($inverse->deleted_at),409);
            $actual[]=['operation_uuid'=>$match->operation_uuid,'position_uuid'=>$match->position_uuid,'closure_state'=>'completed',
                'closure_snapshot_hash'=>SolaStockJournalContract::payloadHash($snapshot)];
        }
        abort_unless($actual!==[] && SolaStockJournalContract::canonicalJson($actual)===SolaStockJournalContract::canonicalJson($presented),409);
        $mapping=IntegrationOrganizationMapping::query()->where('mapping_uuid',$intent->organization_mapping_uuid)->where('solastock_organization_id',$this->organizationId)
            ->where('finance_organization_id',$financeOrg)->lockForUpdate()->firstOrFail();
        abort_unless($mapping->status==='verified' && $mapping->activation_state==='active' && $mapping->tenant_database_identity===$db->getDatabaseName()
            && (int)$mapping->central_organization_id===$this->organizationId && (int)($p['central_organization_id']??0)===$this->organizationId,409);
        \App\Models\Tenant\IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)
            ->where('mapping_uuid',$f['physical_mapping_uuid'])->where('source_document_type','goods_receipt')
            ->where('source_document_id',(string)$f['physical_document_id'])->firstOrFail();
        $request=FinancialOriginRequest::query()->where('organization_id',$this->organizationId)->where('request_uuid',$f['request_uuid'])
            ->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('source_document_type','expense')->where('source_document_id',$expense->id)
            ->where('source_journal_id',$f['source_journal_id'])->lockForUpdate()->firstOrFail();
        abort_unless($request->source_revision===$f['source_revision'] && $request->status==='cancelled',409);
        $command=FinancialOriginCommand::query()->where('organization_id',$this->organizationId)->whereKey($this->commandId)
            ->where('request_uuid',$request->request_uuid)->where('goods_receipt_id',$f['physical_document_id'])->lockForUpdate()->firstOrFail();
        abort_unless($command->status==='completed' && $command->source_document_type==='expense'
            && (int)$command->source_document_id===(int)$expense->id && (int)$command->source_journal_id===(int)$f['source_journal_id'],409);
        $outbox=FinancialOriginOutbox::query()->where('organization_id',$this->organizationId)->where('operation_uuid',$command->operation_uuid)
            ->where('event_type','financial-origin.receipt.confirmed')->where('physical_document_id',$f['physical_document_id'])->firstOrFail();
        abort_unless($outbox->payload_hash===SolaStockJournalContract::payloadHash($outbox->payload),409);
        foreach(['mapping_uuid'=>'physical_mapping_uuid','journal_key'=>'physical_journal_key','journal_event_uuid'=>'physical_journal_event_uuid','journal_payload_hash'=>'physical_journal_payload_hash']as$native=>$field)
            abort_unless(data_get($outbox->payload,'physical.'.$native)===$f[$field],409);
        $native=\App\Models\Tenant\IntegrationOutboxEvent::query()->where('organization_id',$this->organizationId)
            ->where('event_type','grn.posted')->where('aggregate_id',$f['physical_document_id'])
            ->where('idempotency_key',$f['physical_journal_key'])->where('event_uuid',$f['physical_journal_event_uuid'])->firstOrFail();
        abort_unless(SolaStockJournalContract::payloadHash($native->payload)===$f['physical_journal_payload_hash'],409);
        return $request;
    }
}
