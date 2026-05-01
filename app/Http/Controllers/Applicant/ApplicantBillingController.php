<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;

class ApplicantBillingController extends Controller
{
    public function invoices(Request $request): Response
    {
        $user = $request->user();

        $invoices = Invoice::query()
            ->with(['application'])
            ->whereHas('application', fn ($q) => $q->where('applicant_user_id', $user->id))
            ->latest('id')
            ->get()
            ->map(fn (Invoice $inv) => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'currency' => $inv->currency,
                'amount_cents' => $inv->amount_cents,
                'status' => $inv->status?->value ?? (string) $inv->status,
                'issued_at' => optional($inv->issued_at)?->toIso8601String(),
                'paid_at' => optional($inv->paid_at)?->toIso8601String(),
                'application' => $inv->application
                    ? [
                        'id' => $inv->application->id,
                        'application_number' => $inv->application->application_number,
                        'current_status' => $inv->application->current_status?->value ?? (string) $inv->application->current_status,
                    ]
                    : null,
            ]);

        return Inertia::render('Applicant/Invoices', [
            'invoices' => $invoices,
        ]);
    }

    public function payments(Request $request): Response
    {
        $user = $request->user();

        $payments = Payment::query()
            ->with(['application', 'invoice', 'proofDocument'])
            ->whereHas('application', fn ($q) => $q->where('applicant_user_id', $user->id))
            ->latest('id')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'method' => $p->method?->value ?? (string) $p->method,
                'status' => $p->status?->value ?? (string) $p->status,
                'currency' => $p->currency,
                'amount_cents' => $p->amount_cents,
                'provider' => $p->provider,
                'provider_reference' => $p->provider_reference,
                'created_at' => optional($p->created_at)?->toIso8601String(),
                'confirmed_at' => optional($p->confirmed_at)?->toIso8601String(),
                'rejection_reason' => $p->rejection_reason,
                'application' => $p->application
                    ? [
                        'id' => $p->application->id,
                        'application_number' => $p->application->application_number,
                    ]
                    : null,
                'invoice' => $p->invoice
                    ? [
                        'id' => $p->invoice->id,
                        'invoice_number' => $p->invoice->invoice_number,
                    ]
                    : null,
                'proof_document' => $p->proofDocument
                    ? [
                        'id' => $p->proofDocument->id,
                        'preview_url' => \Illuminate\Support\Facades\URL::temporarySignedRoute('applicant.documents.preview', now()->addMinutes(15), ['document' => $p->proofDocument->id]),
                        'download_url' => \Illuminate\Support\Facades\URL::temporarySignedRoute('applicant.documents.download', now()->addMinutes(15), ['document' => $p->proofDocument->id]),
                    ]
                    : null,
            ]);

        $summary = [
            'total_cents' => (int) $payments->sum('amount_cents'),
            'confirmed_cents' => (int) $payments->where('status', 'confirmed')->sum('amount_cents'),
            'count' => (int) $payments->count(),
        ];

        return Inertia::render('Applicant/Payments', [
            'payments' => $payments,
            'summary' => $summary,
        ]);
    }
}

