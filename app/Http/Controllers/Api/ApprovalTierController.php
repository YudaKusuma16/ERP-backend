<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalTierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ApprovalTier::query();

        if ($request->has('document_type')) {
            $query->forDocumentType($request->document_type);
        }

        return response()->json([
            'tiers' => $query->orderBy('min_value')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => 'required|in:pr,po',
            'min_value' => 'required|integer|min:0',
            'max_value' => 'nullable|integer|gt:min_value',
            'tier_count' => 'required|integer|min:1',
        ]);

        $tier = ApprovalTier::create($validated);

        return response()->json([
            'message' => 'Approval tier created successfully.',
            'tier' => $tier,
        ], 201);
    }

    public function update(Request $request, ApprovalTier $approvalTier): JsonResponse
    {
        $validated = $request->validate([
            'min_value' => 'sometimes|integer|min:0',
            'max_value' => 'nullable|integer|gt:min_value',
            'tier_count' => 'sometimes|integer|min:1',
            'is_active' => 'sometimes|boolean',
        ]);

        $approvalTier->update($validated);

        return response()->json([
            'message' => 'Approval tier updated successfully.',
            'tier' => $approvalTier->fresh(),
        ]);
    }

    public function getTierForValue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => 'required|in:pr,po',
            'value' => 'required|integer|min:0',
        ]);

        $tierCount = ApprovalTier::getTierCountForValue(
            $validated['document_type'],
            $validated['value']
        );

        return response()->json([
            'document_type' => $validated['document_type'],
            'value' => $validated['value'],
            'required_tiers' => $tierCount,
        ]);
    }
}