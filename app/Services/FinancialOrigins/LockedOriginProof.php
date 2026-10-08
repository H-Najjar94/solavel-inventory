<?php
namespace App\Services\FinancialOrigins;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Integration\SolaStockJournalContract;
use App\Tenancy\OrganizationContext;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** Publication rechecks independently persisted Finance facts after remote authorization and before Stock writes. */
final readonly class LockedOriginProof
{
    private function __construct(public OriginRequestPayload $request, public IntegrationOrganizationMapping $mapping, public int $actorId) {}

    public static function verify(OriginRequestPayload $request, IntegrationOrganizationMapping $mapping, array $authority, int $actor, string $command='upsert'): self
    {
        $db=DB::connection('tenant'); abort_unless($db->transactionLevel()>0 && $actor>0,409);
        $o=$request->origin; $p=$request->payload;
        abort_unless(in_array($o->type,['sales_receipt','expense'],true),422); // Existing Invoice requests keep their original service/table.
        abort_unless(($authority['allowed']??false)===true && (int)($authority['actor_id']??0)===$actor
            && ($authority['source_document_type']??null)===$o->type && (int)($authority['source_document_id']??0)===$o->documentId
            && (int)($authority['source_journal_id']??0)===$o->journalId && ($authority['request_uuid']??null)===$p['request_uuid']
            && ($authority['request_revision']??null)===$p['source_revision'],403);
        abort_unless(SolaStockJournalContract::canonicalJson((array)($authority['canonical_payload']??[]))===$request->canonicalJson(),403);
        return self::lockNative($request,$mapping,$actor,$command);
    }

    public static function lockAccepted(\App\Models\Tenant\FinancialOriginRequest $accepted,int $actor): self
    {
        $dto=OriginRequestPayload::fromArray($accepted->source_payload);
        $mapping=IntegrationOrganizationMapping::query()->where('mapping_uuid',$accepted->organization_mapping_uuid)->where('solastock_organization_id',$accepted->organization_id)->firstOrFail();
        abort_unless($actor>0,403);return self::lockNative($dto,$mapping,$actor,'upsert');
    }

    private static function lockNative(OriginRequestPayload $request,IntegrationOrganizationMapping $mapping,int $actor,string $command):self
    {
        $db=DB::connection('tenant');abort_unless($db->transactionLevel()>0,409);$o=$request->origin;$p=$request->payload;
        $doc=$db->table($o->documentTable())->where('organization_id',$mapping->finance_organization_id)->where('id',$o->documentId)->lockForUpdate()->first(); abort_unless($doc,404);
        $intent=$db->table('finance_document_requests')->where('organization_id',$mapping->finance_organization_id)->where('organization_mapping_uuid',$mapping->mapping_uuid)
            ->where('source_document_type',$o->type)->where('source_document_id',$o->documentId)->where('request_uuid',$p['request_uuid'])->lockForUpdate()->first();
        abort_unless($intent && $intent->command===$command && $intent->source_revision===$p['source_revision'] && (int)$intent->source_journal_id===$o->journalId
            && $intent->side===($o->domain()==='sales'?'sales':'purchase') && SolaStockJournalContract::canonicalJson(json_decode($intent->payload,true,512,JSON_THROW_ON_ERROR))===$request->canonicalJson(),409);
        $mapping=$mapping->newQuery()->whereKey($mapping->id)->where('mapping_uuid',$mapping->mapping_uuid)->where('central_client_id',$mapping->central_client_id)->where('central_organization_id',$mapping->central_organization_id)->where('finance_organization_id',$mapping->finance_organization_id)->where('solastock_organization_id',$mapping->solastock_organization_id)->lockForUpdate()->firstOrFail();
        abort_unless($mapping->status==='verified' && $mapping->activation_state==='active' && (int)$mapping->solastock_organization_id===app(OrganizationContext::class)->idOrFail(),409);
        $journal=$db->table('journal_entries')->where('organization_id',$mapping->finance_organization_id)->where('id',$o->journalId)
            ->where('source',$o->journalSource())->where('source_type',$o->modelClass())->where('source_id',$o->documentId)->lockForUpdate()->first();
        abort_unless($journal && $journal->status==='posted' && empty($journal->voided_at) && empty($journal->deleted_at),409);
        $party=$o->type==='expense'?($doc->vendor_id??null):($doc->customer_id??null);
        $provided=$p[$o->type==='expense'?'supplier_external_id':'customer_external_id']??null;
        abort_unless(($party===null && $provided===null && $o->type==='sales_receipt') || ($party!==null && (int)$party===(int)$provided),403);
        foreach($p['lines'] as $line){
            $native=$db->table($o->lineTable())->where($o->lineParent(),$o->documentId)->where('id',$line['source_document_line_id'])->lockForUpdate()->first();
            abort_unless($native && (int)$native->inventory_item_id===(int)$line['item_external_id'],403);
            abort_unless(Decimal::cmp((string)$native->qty,(string)$line['quantity'])===0,409);
            if($o->type==='expense') abort_unless(($native->item_usage??null)==='inventory',403);
        }
        return new self($request,$mapping,$actor);
    }
}
