<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaterialRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\ServiceRequest;
use App\Models\WorkOrder;
use App\Models\ReceivingDocument;
use App\Models\DeliveryInstruction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class ExportController extends Controller
{
    private function toCsv($headers, $rows)
    {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    public function materialRequests(Request $request)
    {
        $query = MaterialRequest::with('requestor', 'department', 'lineItems');
        if ($request->has('status')) $query->byStatus($request->status);
        if ($request->has('date_from')) $query->where('date', '>=', $request->date_from);
        if ($request->has('date_to')) $query->where('date', '<=', $request->date_to);
        $mrs = $query->get();

        $headers = ['Number', 'Date', 'Source Type', 'Requestor', 'Department', 'Status', 'Items Count'];
        $rows = $mrs->map(fn($mr) => [
            $mr->number, $mr->date, $mr->source_type,
            $mr->requestor?->name, $mr->department?->name, $mr->status,
            $mr->lineItems->count(),
        ])->toArray();

        $csv = $this->toCsv($headers, $rows);
        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="material_requests.csv"',
        ]);
    }

    public function purchaseOrders(Request $request)
    {
        $query = PurchaseOrder::with('vendor', 'purchaseRequisition');
        if ($request->has('status')) $query->byStatus($request->status);
        if ($request->has('date_from')) $query->where('date', '>=', $request->date_from);
        if ($request->has('date_to')) $query->where('date', '<=', $request->date_to);
        $pos = $query->get();

        $headers = ['PO Number', 'Date', 'PR Number', 'Vendor', 'Total Value', 'Status'];
        $rows = $pos->map(fn($po) => [
            $po->number, $po->date, $po->purchaseRequisition?->number,
            $po->vendor?->name, $po->total_value, $po->status,
        ])->toArray();

        $csv = $this->toCsv($headers, $rows);
        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="purchase_orders.csv"',
        ]);
    }

    public function serviceRequests(Request $request)
    {
        $query = ServiceRequest::with('requestor', 'department');
        if ($request->has('status')) $query->byStatus($request->status);
        $srs = $query->get();

        $headers = ['Number', 'Date', 'Source Type', 'Requestor', 'Department', 'Status'];
        $rows = $srs->map(fn($sr) => [
            $sr->number, $sr->date, $sr->source_type,
            $sr->requestor?->name, $sr->department?->name, $sr->status,
        ])->toArray();

        $csv = $this->toCsv($headers, $rows);
        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="service_requests.csv"',
        ]);
    }

    public function workOrders(Request $request)
    {
        $query = WorkOrder::with('pic', 'acceptanceLetter');
        if ($request->has('status')) $query->byStatus($request->status);
        $wos = $query->get();

        $headers = ['WO Number', 'Date', 'ORF Ref', 'Service Type', 'PIC', 'Status', 'AL Number'];
        $rows = $wos->map(fn($wo) => [
            $wo->number, $wo->date, $wo->orf_ref, $wo->service_type,
            $wo->pic?->name, $wo->status, $wo->acceptanceLetter?->number,
        ])->toArray();

        $csv = $this->toCsv($headers, $rows);
        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="work_orders.csv"',
        ]);
    }

    public function receivingDocuments(Request $request)
    {
        $query = ReceivingDocument::with('preReceivingDocument.purchaseOrder.vendor');
        if ($request->has('status')) $query->byStatus($request->status);
        $rds = $query->get();

        $headers = ['RD Number', 'Date', 'Pre-RD', 'PO Number', 'Vendor', 'Status'];
        $rows = $rds->map(fn($rd) => [
            $rd->number, $rd->date, $rd->preReceivingDocument?->number,
            $rd->preReceivingDocument?->purchaseOrder?->number,
            $rd->preReceivingDocument?->purchaseOrder?->vendor?->name,
            $rd->status,
        ])->toArray();

        $csv = $this->toCsv($headers, $rows);
        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="receiving_documents.csv"',
        ]);
    }
}