<?php
namespace App\Services\Sales;

use App\Models\Tenant\SalesReturn;
use Illuminate\Support\Facades\{DB,Schema};

/** Financial ownership comes from immutable typed source identity, not a shared sales order. */
final class ReturnSourceOwnership
{
    public function cash(SalesReturn $return): bool
    {
        $commands='stock_financial_origin_commands';$requests='stock_financial_origin_requests';
        if (! Schema::connection('tenant')->hasTable($commands) || ! Schema::connection('tenant')->hasTable($requests)
            || ! Schema::connection('tenant')->hasColumn($commands,'source_document_type')
            || ! Schema::connection('tenant')->hasColumn($requests,'source_document_type')) return false;
        $db=DB::connection('tenant');$org=(int)$return->organization_id;$shipment=(int)$return->shipment_id;
        if($org<1||$shipment<1)return false;
        $scope=$db->table($commands.' as c')->join($requests.' as r',function($join){
            $join->on('r.id','=','c.request_id')->on('r.organization_id','=','c.organization_id');
        })->where('c.organization_id',$org)->where('r.organization_id',$org)->where('c.shipment_id',$shipment)
            ->where('c.source_document_type','sales_receipt')->where('r.source_document_type','sales_receipt');
        $command=(clone $scope)->where('c.status','completed')->select('c.*')->first();
        if($command){$request=$db->table($requests)->where('organization_id',$org)->where('id',$command->request_id)->first();
            if($request && self::completedCashCommand((array)$command,(array)$request,$org,$shipment))return true;
        }
        // A typed return keeps its original ownership when the cash operation is
        // subsequently voided. Never turn that native registry into an invoice credit.
        if(!Schema::connection('tenant')->hasTable('stock_cash_partial_returns')||(int)$return->id<1)return false;
        return (clone $scope)->join('stock_cash_partial_returns as cr',function($join){
            $join->on('cr.request_id','=','r.id')->on('cr.organization_id','=','r.organization_id')->on('cr.shipment_id','=','c.shipment_id');
        })->where('cr.organization_id',$org)->where('cr.sales_return_id',(int)$return->id)->where('cr.shipment_id',$shipment)->exists();
    }

    public static function completedCashCommand(array $command,array $request,int $org,int $shipment): bool
    {
        return $org>0 && $shipment>0 && (int)($command['organization_id']??0)===$org
            && (int)($request['organization_id']??0)===$org
            && (int)($command['shipment_id']??0)===$shipment && ($command['status']??null)==='completed'
            && (int)($request['id']??0)>0 && (int)($command['request_id']??0)===(int)$request['id']
            && ($command['source_document_type']??null)==='sales_receipt'
            && ($request['source_document_type']??null)==='sales_receipt';
    }
}
