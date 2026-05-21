<?php

namespace App\Services;

use App\Models\DeliveryInstruction;
use App\Models\PurchaseRequisition;

class DeliveryInstructionFromPrService
{
    public function __construct(
        private DocumentNumberingService $docNumbering,
        private AuditTrailService $auditTrail,
        private NotificationService $notificationService,
    ) {}

    public function ensureForForwardedMrBackedDeliveryPr(PurchaseRequisition $purchaseRequisition, int $actorUserId, bool $notifyLogistics): void
    {
        if ($purchaseRequisition->status !== 'forwarded_to_p3') {
            return;
        }
        if ($purchaseRequisition->source_type !== 'mr') {
            return;
        }

        $mr = $purchaseRequisition->relationLoaded('sourceMr')
            ? $purchaseRequisition->sourceMr
            : $purchaseRequisition->sourceMr()->first();

        if (!$mr || !$mr->createsDeliveryInstructionAfterPrApproval()) {
            return;
        }

        $existingDi = DeliveryInstruction::where(function ($q) use ($mr, $purchaseRequisition) {
            $q->where('mr_id', $mr->id)->orWhere('pr_id', $purchaseRequisition->id);
        })->orderBy('id', 'desc')->first();

        if (!$existingDi) {
            $di = DeliveryInstruction::create([
                'number' => $this->docNumbering->generate('di'),
                'date' => now()->toDateString(),
                'mr_id' => $mr->id,
                'pr_id' => $purchaseRequisition->id,
                'warehouse_id' => null,
                'status' => 'draft',
                'created_by' => $actorUserId,
            ]);

            $label = $mr->source_type === 'so' ? 'MR Sales Order' : 'outbound MR';
            $note = $notifyLogistics
                ? "DI created from PR {$purchaseRequisition->number} ({$label})"
                : "DI auto-synced when opening PR {$purchaseRequisition->number} ({$label})";

            $this->auditTrail->log('di', $di->id, $actorUserId, 'created', 'draft', $note);

            if ($notifyLogistics) {
                $this->notificationService->notifyUsersWithRole(
                    'log',
                    'di_created',
                    'Delivery Instruction Created',
                    "DI {$di->number} created from PR {$purchaseRequisition->number}.",
                    'di',
                    $di->id
                );
            }
            return;
        }

        if ($existingDi->pr_id === null) {
            $existingDi->update(['pr_id' => $purchaseRequisition->id]);
        }
    }

    public function ensureForForwardedSrPr(PurchaseRequisition $purchaseRequisition, int $actorUserId, bool $notifyLogistics): void
    {
        if ($purchaseRequisition->status !== 'forwarded_to_p3' || $purchaseRequisition->source_type !== 'sr') {
            return;
        }

        $existingDi = DeliveryInstruction::where('pr_id', $purchaseRequisition->id)->orderBy('id', 'desc')->first();
        if ($existingDi) {
            return;
        }

        $di = DeliveryInstruction::create([
            'number' => $this->docNumbering->generate('di'),
            'date' => now()->toDateString(),
            'mr_id' => null,
            'pr_id' => $purchaseRequisition->id,
            'warehouse_id' => null,
            'status' => 'draft',
            'created_by' => $actorUserId,
        ]);

        $note = $notifyLogistics
            ? 'DI created from SR PR ' . $purchaseRequisition->number
            : 'DI auto-synced when opening SR PR ' . $purchaseRequisition->number;

        $this->auditTrail->log('di', $di->id, $actorUserId, 'created', 'draft', $note);

        if ($notifyLogistics) {
            $this->notificationService->notifyUsersWithRole(
                'log',
                'di_created',
                'Delivery Instruction Created',
                "DI {$di->number} created from PR {$purchaseRequisition->number} (Service Request).",
                'di',
                $di->id
            );
        }
    }

    /** Idempotent hooks for forwarded_to_p3 PRs (approve path + GET show lazy repair). */
    public function ensureForForwardedPurchaseRequisition(PurchaseRequisition $purchaseRequisition, int $actorUserId, bool $notifyLogistics): void
    {
        if ($purchaseRequisition->status !== 'forwarded_to_p3') {
            return;
        }
        $purchaseRequisition->loadMissing('sourceMr');
        $this->ensureForForwardedMrBackedDeliveryPr($purchaseRequisition, $actorUserId, $notifyLogistics);
        $this->ensureForForwardedSrPr($purchaseRequisition, $actorUserId, $notifyLogistics);
    }
}
