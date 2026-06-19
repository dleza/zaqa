<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditLogService;
use App\Enums\ApplicationStatus;
use App\Enums\InvoiceDocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\QualificationDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class QuotationExpiryService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array{expired: int, skipped: int}
     */
    public function expireDueQuotations(): array
    {
        $expired = 0;
        $skipped = 0;

        Invoice::query()
            ->where('document_type', InvoiceDocumentType::Quotation)
            ->where('status', InvoiceStatus::Issued)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNull('supplementary_of_invoice_id')
            ->orderBy('id')
            ->lazyById()
            ->each(function (Invoice $quotation) use (&$expired, &$skipped) {
                $result = $this->expireQuotationIfEligible($quotation);
                if ($result) {
                    $expired++;
                } else {
                    $skipped++;
                }
            });

        return ['expired' => $expired, 'skipped' => $skipped];
    }

    public function expireQuotationIfEligible(Invoice $quotation): bool
    {
        if ($quotation->document_type !== InvoiceDocumentType::Quotation) {
            return false;
        }

        if ($quotation->status !== InvoiceStatus::Issued) {
            return false;
        }

        if ($quotation->expires_at === null || $quotation->expires_at->isFuture()) {
            return false;
        }

        $application = $quotation->application()->with(['payments', 'qualifications'])->first();
        if (! $application) {
            return false;
        }

        if (! $this->applicationIsEligibleForExpiry($application)) {
            return false;
        }

        DB::transaction(function () use ($quotation, $application) {
            $quotation->refresh();
            if ($quotation->status !== InvoiceStatus::Issued) {
                return;
            }

            $quotation->forceFill([
                'status' => InvoiceStatus::Expired,
                'metadata' => array_merge((array) ($quotation->metadata ?? []), [
                    'expired_at' => now()->toIso8601String(),
                ]),
            ])->save();

            $application->forceFill([
                'current_status' => ApplicationStatus::ExpiredUnpaid,
            ])->save();

            $this->purgeApplicationDocuments($application);

            $this->audit->record(
                eventType: 'quotation.expired_application_deleted',
                module: 'Finance',
                actionName: 'quotation_expired_application_deleted',
                message: 'Unpaid quotation expired; application marked expired and documents removed.',
                entityType: Application::class,
                entityId: $application->id,
                metadata: [
                    'quotation_id' => $quotation->id,
                    'quotation_number' => $quotation->quotation_number ?: $quotation->invoice_number,
                    'application_number' => $application->application_number,
                    'expires_at' => optional($quotation->expires_at)?->toIso8601String(),
                ],
            );
        });

        return true;
    }

    public function applicationIsEligibleForExpiry(Application $application): bool
    {
        if ($application->paid_at !== null) {
            return false;
        }

        if ($application->submitted_at !== null) {
            return false;
        }

        if ($application->payments->contains(fn ($payment) => $payment->status === PaymentStatus::Confirmed)) {
            return false;
        }

        $protectedStatuses = [
            ApplicationStatus::Submitted,
            ApplicationStatus::InProgress,
            ApplicationStatus::SentBack,
            ApplicationStatus::Resubmitted,
            ApplicationStatus::Approved,
            ApplicationStatus::Rejected,
            ApplicationStatus::CertificateReady,
            ApplicationStatus::Completed,
            ApplicationStatus::ExpiredUnpaid,
        ];

        if (in_array($application->current_status, $protectedStatuses, true)) {
            return false;
        }

        return in_array($application->current_status, [
            ApplicationStatus::Draft,
            ApplicationStatus::PendingPayment,
        ], true);
    }

    private function purgeApplicationDocuments(Application $application): void
    {
        QualificationDocument::query()
            ->where('application_id', $application->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lazyById()
            ->each(function (QualificationDocument $document) {
                $this->deleteDocumentFromStorage($document);
                $document->forceFill([
                    'is_current_version' => false,
                    'deleted_at' => now(),
                ])->save();
            });
    }

    private function deleteDocumentFromStorage(QualificationDocument $document): void
    {
        if ($document->path === null || $document->path === '') {
            return;
        }

        try {
            Storage::disk($document->disk)->delete($document->path);
        } catch (\Throwable) {
            // Best-effort cleanup; DB record is still marked deleted.
        }
    }
}
