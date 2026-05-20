<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApprovalRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function envelope(): Envelope
    {
        $typeLabel = match ($this->data['document_type'] ?? '') {
            'mr' => 'Material Request',
            'sr' => 'Service Request',
            'pr' => 'Purchase Requisition',
            'po' => 'Purchase Order',
            default => $this->data['document_type'] ?? 'Document',
        };

        return new Envelope(
            subject: "Permintaan Approval: {$typeLabel} #{$this->data['document_number']}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.approval-request',
        );
    }
}