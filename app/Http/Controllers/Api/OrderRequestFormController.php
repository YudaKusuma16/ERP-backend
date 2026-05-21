<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderRequestForm;
use App\Services\AuditTrailService;
use App\Services\DocumentNumberingService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderRequestFormController extends Controller
{
    public function __construct(
        private DocumentNumberingService $docNumbering,
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = OrderRequestForm::with('creator');

        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', $search)
                    ->orWhere('customer_name', 'like', $search);
            });
        }

        $orfs = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);

        return response()->json($orfs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'request_details' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $orf = OrderRequestForm::create([
                'number' => $this->docNumbering->generate('orf'),
                'date' => now()->toDateString(),
                'customer_name' => $validated['customer_name'] ?? null,
                'request_details' => $validated['request_details'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $this->auditTrail->log('orf', $orf->id, $request->user()->id, 'created', 'draft', 'Order Request Form created');

            return response()->json([
                'message' => 'Order Request Form created successfully.',
                'orf' => $orf->load('creator'),
            ], 201);
        });
    }

    public function show(OrderRequestForm $orderRequestForm): JsonResponse
    {
        return response()->json([
            'orf' => $orderRequestForm->load('creator', 'approvalLogs.actor'),
        ]);
    }

    public function update(Request $request, OrderRequestForm $orderRequestForm): JsonResponse
    {
        if (!in_array($orderRequestForm->status, ['draft', 'declined'])) {
            return response()->json(['message' => 'ORF cannot be edited in current status.'], 422);
        }

        $validated = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'request_details' => 'nullable|string',
        ]);

        $orderRequestForm->update($validated);

        return response()->json([
            'message' => 'Order Request Form updated.',
            'orf' => $orderRequestForm->fresh()->load('creator'),
        ]);
    }

    public function destroy(OrderRequestForm $orderRequestForm): JsonResponse
    {
        if ($orderRequestForm->status !== 'draft') {
            return response()->json(['message' => 'Only draft ORFs can be deleted.'], 422);
        }

        $orderRequestForm->delete();

        return response()->json(['message' => 'Order Request Form deleted.']);
    }

    public function submit(Request $request, OrderRequestForm $orderRequestForm): JsonResponse
    {
        if ($orderRequestForm->status !== 'draft') {
            return response()->json(['message' => 'ORF must be in draft status to submit.'], 422);
        }

        return DB::transaction(function () use ($orderRequestForm, $request) {
            $fromStatus = $orderRequestForm->status;
            $orderRequestForm->update(['status' => 'submitted']);
            $this->auditTrail->log('orf', $orderRequestForm->id, $request->user()->id, $fromStatus, 'submitted', 'ORF submitted for approval');

            $this->notificationService->notifyUsersWithRole(
                'dept_head',
                'orf_pending_approval',
                'ORF Pending Approval',
                "ORF {$orderRequestForm->number} is pending approval.",
                'orf',
                $orderRequestForm->id
            );

            return response()->json([
                'message' => 'Order Request Form submitted for approval.',
                'orf' => $orderRequestForm->fresh()->load('creator'),
            ]);
        });
    }

    public function approve(Request $request, OrderRequestForm $orderRequestForm): JsonResponse
    {
        if ($orderRequestForm->status !== 'submitted') {
            return response()->json(['message' => 'ORF must be submitted to approve or decline.'], 422);
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,decline',
            'reason' => 'required_if:action,decline|string|nullable',
        ]);

        return DB::transaction(function () use ($orderRequestForm, $validated, $request) {
            if ($validated['action'] === 'decline') {
                $fromStatus = $orderRequestForm->status;
                $orderRequestForm->update([
                    'status' => 'declined',
                    'decline_reason' => $validated['reason'] ?? '',
                ]);
                $this->auditTrail->log('orf', $orderRequestForm->id, $request->user()->id, $fromStatus, 'declined', $validated['reason'] ?? 'ORF declined');

                if ($orderRequestForm->created_by) {
                    $this->notificationService->notify(
                        $orderRequestForm->created_by,
                        'orf_declined',
                        'ORF Declined',
                        "ORF {$orderRequestForm->number} has been declined.",
                        'orf',
                        $orderRequestForm->id
                    );
                }

                return response()->json([
                    'message' => 'Order Request Form declined.',
                    'orf' => $orderRequestForm->fresh()->load('creator'),
                ]);
            }

            $fromStatus = $orderRequestForm->status;
            $orderRequestForm->update(['status' => 'approved', 'decline_reason' => null]);
            $this->auditTrail->log('orf', $orderRequestForm->id, $request->user()->id, $fromStatus, 'approved', 'ORF approved');

            if ($orderRequestForm->created_by) {
                $this->notificationService->notify(
                    $orderRequestForm->created_by,
                    'orf_approved',
                    'ORF Approved',
                    "ORF {$orderRequestForm->number} has been approved.",
                    'orf',
                    $orderRequestForm->id
                );
            }

            return response()->json([
                'message' => 'Order Request Form approved.',
                'orf' => $orderRequestForm->fresh()->load('creator'),
            ]);
        });
    }
}
