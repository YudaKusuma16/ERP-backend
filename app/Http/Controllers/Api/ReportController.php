<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaterialRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\ServiceRequest;
use App\Models\WorkOrder;
use App\Models\DeliveryInstruction;
use App\Models\DeliveryNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class ReportController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $mrByStatus = MaterialRequest::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status');

        $srByStatus = ServiceRequest::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status');

        $prByStatus = PurchaseRequisition::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status');

        $poByStatus = PurchaseOrder::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status');

        $woByStatus = WorkOrder::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status');

        $departmentSpend = PurchaseOrder::select('departments.name', DB::raw('SUM(purchase_orders.total_value) as total'))
            ->join('purchase_requisitions', 'purchase_orders.pr_id', '=', 'purchase_requisitions.id')
            ->leftJoin('material_requests', 'purchase_requisitions.source_id', '=', 'material_requests.id')
            ->leftJoin('departments', 'material_requests.department_id', '=', 'departments.id')
            ->where('purchase_orders.status', '!=', 'declined')
            ->groupBy('departments.name')
            ->orderByDesc('total')
            ->get();

        $vendorSpend = PurchaseOrder::select('master_vendors.name', DB::raw('SUM(purchase_orders.total_value) as total'))
            ->join('master_vendors', 'purchase_orders.vendor_id', '=', 'master_vendors.id')
            ->where('purchase_orders.status', '!=', 'declined')
            ->groupBy('master_vendors.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $monthlyVolume = PurchaseOrder::select(
            DB::raw("TO_CHAR(purchase_orders.date, 'YYYY-MM') as month"),
            DB::raw('SUM(purchase_orders.total_value) as total'),
            DB::raw('count(*) as count')
        )
            ->where('purchase_orders.status', '!=', 'declined')
            ->groupBy('month')
            ->orderBy('month')
            ->limit(12)
            ->get();

        return response()->json([
            'mr_by_status' => $mrByStatus,
            'sr_by_status' => $srByStatus,
            'pr_by_status' => $prByStatus,
            'po_by_status' => $poByStatus,
            'wo_by_status' => $woByStatus,
            'department_spend' => $departmentSpend,
            'vendor_spend' => $vendorSpend,
            'monthly_volume' => $monthlyVolume,
        ]);
    }

    public function approvalTurnaround(): JsonResponse
    {
        $turnaround = DB::select("
            SELECT doc_type,
                   AVG(EXTRACT(EPOCH FROM (updated_at - created_at)) / 3600) as avg_hours,
                   COUNT(*) as total_docs
            FROM (
                SELECT 'MR' as doc_type, created_at, updated_at FROM material_requests WHERE status IN ('approved', 'pr_created')
                UNION ALL
                SELECT 'SR' as doc_type, created_at, updated_at FROM service_requests WHERE status IN ('approved', 'pr_created')
                UNION ALL
                SELECT 'PR' as doc_type, created_at, updated_at FROM purchase_requisitions WHERE status IN ('forwarded_to_p3')
                UNION ALL
                SELECT 'PO' as doc_type, created_at, updated_at FROM purchase_orders WHERE status IN ('approved', 'open', 'partially_closed', 'closed')
            ) combined
            GROUP BY doc_type
        ");

        return response()->json(['turnaround' => $turnaround]);
    }
}