<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterVendor;
use App\Services\AuditTrailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterVendorController extends Controller
{
    public function __construct(
        private AuditTrailService $auditTrail,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = MasterVendor::with('createdBy');

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $vendors = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);

        return response()->json($vendors);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:supplier,contractor,service_provider',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'tax_id' => 'nullable|string|max:100',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:100',
            'bank_account_holder' => 'nullable|string|max:255',
            'payment_terms' => 'nullable|string|max:255',
        ]);

        $vendor = MasterVendor::create([
            ...$validated,
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        $this->auditTrail->log('master_vendor', $vendor->id, $request->user()->id, 'draft', 'active', 'Vendor created and activated');

        return response()->json([
            'message' => 'Vendor created successfully.',
            'vendor' => $vendor->load('createdBy'),
        ], 201);
    }

    public function show(MasterVendor $masterVendor): JsonResponse
    {
        return response()->json([
            'vendor' => $masterVendor->load('createdBy'),
        ]);
    }

    public function update(Request $request, MasterVendor $masterVendor): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:supplier,contractor,service_provider',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'tax_id' => 'nullable|string|max:100',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:100',
            'bank_account_holder' => 'nullable|string|max:255',
            'payment_terms' => 'nullable|string|max:255',
        ]);

        $fromStatus = $masterVendor->status;
        $masterVendor->update($validated);

        $this->auditTrail->log('master_vendor', $masterVendor->id, $request->user()->id, $fromStatus, $masterVendor->status, 'Vendor updated');

        return response()->json([
            'message' => 'Vendor updated successfully.',
            'vendor' => $masterVendor->fresh()->load('createdBy'),
        ]);
    }

    public function changeStatus(Request $request, MasterVendor $masterVendor): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:active,inactive',
        ]);

        $fromStatus = $masterVendor->status;
        $masterVendor->update(['status' => $validated['status']]);

        $this->auditTrail->log('master_vendor', $masterVendor->id, $request->user()->id, $fromStatus, $validated['status'], 'Vendor status changed');

        return response()->json([
            'message' => 'Vendor status updated.',
            'vendor' => $masterVendor->fresh()->load('createdBy'),
        ]);
    }
}