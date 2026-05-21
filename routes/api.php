<?php

use App\Http\Controllers\Api\AcceptanceLetterController;
use App\Http\Controllers\Api\ApprovalLogController;
use App\Http\Controllers\Api\ApprovalTierController;
use App\Http\Controllers\Api\ApprovalTokenController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DeliveryInstructionController;
use App\Http\Controllers\Api\DeliveryNoteController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\MasterItemController;
use App\Http\Controllers\Api\MaterialRequestController;
use App\Http\Controllers\Api\MasterVendorController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderRequestFormController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseRequisitionController;
use App\Http\Controllers\Api\PreReceivingDocumentController;
use App\Http\Controllers\Api\ReceivingDocumentController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RrvController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ServiceRequestController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
});

Route::get('approval/verify', [ApprovalTokenController::class, 'verify']);

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);

    Route::apiResource('departments', DepartmentController::class);
    Route::apiResource('roles', RoleController::class);

    Route::apiResource('users', UserController::class);
    Route::post('users/{user}/assign-roles', [UserController::class, 'assignRoles']);

    Route::apiResource('master-items', MasterItemController::class);
    Route::post('master-items/{masterItem}/validate', [MasterItemController::class, 'validateByAccounting']);
    Route::post('master-items/{masterItem}/resubmit', [MasterItemController::class, 'resubmit']);

    Route::get('master-vendors', [MasterVendorController::class, 'index'])->middleware('role:purchasing,accounting');
    Route::get('master-vendors/{masterVendor}', [MasterVendorController::class, 'show'])->middleware('role:purchasing,accounting');
    Route::post('master-vendors', [MasterVendorController::class, 'store'])->middleware('role:purchasing,accounting');
    Route::put('master-vendors/{masterVendor}', [MasterVendorController::class, 'update'])->middleware('role:purchasing,accounting');
    Route::patch('master-vendors/{masterVendor}', [MasterVendorController::class, 'update'])->middleware('role:purchasing,accounting');
    Route::delete('master-vendors/{masterVendor}', [MasterVendorController::class, 'destroy'])->middleware('role:purchasing,accounting');
    Route::post('master-vendors/{masterVendor}/status', [MasterVendorController::class, 'changeStatus'])->middleware('role:purchasing,accounting');

    Route::apiResource('material-requests', MaterialRequestController::class);
    Route::post('material-requests/{materialRequest}/pre-receiving-documents', [PreReceivingDocumentController::class, 'storeFromMaterialRequest']);
    Route::post('material-requests/{materialRequest}/approve-dept-head', [MaterialRequestController::class, 'approveByDeptHead']);
    Route::post('material-requests/{materialRequest}/flag-items', [MaterialRequestController::class, 'flagItems']);
    Route::post('material-requests/{materialRequest}/approve-pihak2', [MaterialRequestController::class, 'approveByPihak2']);

    Route::apiResource('service-requests', ServiceRequestController::class);
    Route::post('service-requests/{serviceRequest}/pre-receiving-documents', [PreReceivingDocumentController::class, 'storeFromServiceRequest']);
    Route::post('service-requests/{serviceRequest}/approve-dept-head', [ServiceRequestController::class, 'approveByDeptHead']);
    Route::post('service-requests/{serviceRequest}/flag-items', [ServiceRequestController::class, 'flagItems']);
    Route::post('service-requests/{serviceRequest}/approve-pihak2', [ServiceRequestController::class, 'approveByPihak2']);

    Route::apiResource('approval-tiers', ApprovalTierController::class);
    Route::post('approval-tiers/get-tier', [ApprovalTierController::class, 'getTierForValue']);

    Route::apiResource('purchase-requisitions', PurchaseRequisitionController::class);
    Route::post('purchase-requisitions/{purchaseRequisition}/input-pricing', [PurchaseRequisitionController::class, 'inputPricing']);
    Route::post('purchase-requisitions/{purchaseRequisition}/approve-pihak2', [PurchaseRequisitionController::class, 'approveByPihak2']);

    Route::apiResource('purchase-orders', PurchaseOrderController::class);
    Route::post('purchase-orders/{purchaseOrder}/approve-pihak2', [PurchaseOrderController::class, 'approveByPihak2']);

    Route::get('pre-receiving-documents/available-purchase-orders', [PreReceivingDocumentController::class, 'availablePurchaseOrders']);
    Route::apiResource('pre-receiving-documents', PreReceivingDocumentController::class);
    Route::post('pre-receiving-documents/{preReceivingDocument}/confirm', [PreReceivingDocumentController::class, 'confirm']);

    Route::apiResource('receiving-documents', ReceivingDocumentController::class);
    Route::post('receiving-documents/{receivingDocument}/input-serial-numbers', [ReceivingDocumentController::class, 'inputSerialNumbers']);
    Route::post('receiving-documents/{receivingDocument}/approve', [ReceivingDocumentController::class, 'approve']);

    Route::apiResource('order-request-forms', OrderRequestFormController::class);
    Route::post('order-request-forms/{orderRequestForm}/submit', [OrderRequestFormController::class, 'submit']);
    Route::post('order-request-forms/{orderRequestForm}/approve', [OrderRequestFormController::class, 'approve']);

    Route::apiResource('sales-orders', SalesOrderController::class);
    Route::post('sales-orders/{salesOrder}/submit', [SalesOrderController::class, 'submit']);
    Route::post('sales-orders/{salesOrder}/approve', [SalesOrderController::class, 'approve']);
    Route::post('sales-orders/{salesOrder}/material-requests', [SalesOrderController::class, 'storeMaterialRequest']);

    Route::apiResource('work-orders', WorkOrderController::class);
    Route::post('work-orders/{workOrder}/material-requests', [WorkOrderController::class, 'storeMaterialRequest']);
    Route::post('work-orders/{workOrder}/submit', [WorkOrderController::class, 'submitForApproval']);
    Route::post('work-orders/{workOrder}/approve', [WorkOrderController::class, 'approve']);

    Route::apiResource('acceptance-letters', AcceptanceLetterController::class);
    Route::post('acceptance-letters/{acceptanceLetter}/add-line-items', [AcceptanceLetterController::class, 'addLineItems']);
    Route::post('acceptance-letters/{acceptanceLetter}/update-line-items', [AcceptanceLetterController::class, 'updateLineItems']);
    Route::post('acceptance-letters/{acceptanceLetter}/approve', [AcceptanceLetterController::class, 'approve']);

    Route::apiResource('delivery-instructions', DeliveryInstructionController::class);
    Route::post('delivery-instructions/{deliveryInstruction}/issue', [DeliveryInstructionController::class, 'issue']);

    Route::apiResource('delivery-notes', DeliveryNoteController::class);
    Route::post('delivery-notes/{deliveryNote}/dispatch', [DeliveryNoteController::class, 'dispatch']);

    Route::apiResource('rrvs', RrvController::class);
    Route::post('rrvs/{rrv}/confirm', [RrvController::class, 'confirm']);

    Route::get('attachments', [AttachmentController::class, 'index']);
    Route::post('attachments', [AttachmentController::class, 'store']);
    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download']);
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy']);

    Route::get('reports/dashboard', [ReportController::class, 'dashboard']);
    Route::get('reports/approval-turnaround', [ReportController::class, 'approvalTurnaround']);

    Route::get('export/material-requests', [ExportController::class, 'materialRequests']);
    Route::get('export/purchase-orders', [ExportController::class, 'purchaseOrders']);
    Route::get('export/service-requests', [ExportController::class, 'serviceRequests']);
    Route::get('export/work-orders', [ExportController::class, 'workOrders']);
    Route::get('export/receiving-documents', [ExportController::class, 'receivingDocuments']);

    Route::get('approval-logs', [ApprovalLogController::class, 'index']);

    Route::post('approval/send-email', [ApprovalTokenController::class, 'send']);
    Route::post('approval/send-email-role', [ApprovalTokenController::class, 'sendToRole']);
});