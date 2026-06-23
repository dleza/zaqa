<?php

namespace App\Models;

use App\Domain\Applications\ApplicationQualificationOutcomeSyncService;
use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ServiceType;
use App\Enums\VerificationState;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Application extends Model
{
    protected $fillable = [
        'uuid',
        'application_number',
        'applicant_user_id',
        'applicant_type',
        'service_type',
        'qualification_category',
        'current_status',
        'verification_state',
        'is_foreign',
        'country_id',
        'awarding_body_id',
        'assigned_level1_user_id',
        'assigned_by_level2_user_id',
        'submitted_at',
        'paid_at',
        'completed_at',
        'service_deadline_at',
        'sent_back_at',
        'approved_at',
        'rejected_at',
        'metadata',
    ];

    protected $casts = [
        'applicant_type' => ApplicantType::class,
        'service_type' => ServiceType::class,
        'current_status' => ApplicationStatus::class,
        'verification_state' => VerificationState::class,
        'is_foreign' => 'bool',
        'metadata' => AsArrayObject::class,
        'submitted_at' => 'datetime',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
        'service_deadline_at' => 'datetime',
        'sent_back_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function awardingBody(): BelongsTo
    {
        return $this->belongsTo(AwardingBody::class);
    }

    public function qualification(): HasOne
    {
        return $this->hasOne(Qualification::class);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(Qualification::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class);
    }

    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(ApplicationLifecycleEvent::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ApplicationComment::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(QualificationDocument::class);
    }

    /**
     * Consent rows are stored per qualification. Expose the first linked consent form for legacy payloads
     * (single-qualification flows). Prefer qualification-specific access when multiple items exist.
     */
    public function consentForm(): HasOneThrough
    {
        return $this->hasOneThrough(
            ConsentForm::class,
            Qualification::class,
            'application_id',
            'qualification_id',
            'id',
            'id'
        );
    }

    /**
     * Primary invoice for the application (original fee — never repurposed as a supplementary row).
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->whereNull('supplementary_of_invoice_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function serviceFeedback(): HasOne
    {
        return $this->hasOne(ServiceFeedback::class);
    }

    /**
     * Applicant service feedback is collected once after the application has been submitted.
     * Status may advance to in_progress (or beyond) while the applicant is still on the form.
     */
    public function canReceiveApplicantServiceFeedback(): bool
    {
        return $this->submitted_at !== null;
    }

    public function hasPendingFinanceProofReview(): bool
    {
        return $this->payments()
            ->whereIn('method', [PaymentMethod::BankDeposit->value, PaymentMethod::BankTransfer->value])
            ->where('status', PaymentStatus::AwaitingFinanceReview->value)
            ->exists();
    }

    public function applicantStatusLabel(): string
    {
        return match ($this->current_status) {
            ApplicationStatus::Draft => 'Draft',
            ApplicationStatus::PendingPayment => 'Pending Payment',
            ApplicationStatus::Submitted => 'Submitted',
            ApplicationStatus::InProgress => 'In Progress',
            ApplicationStatus::SentBack => 'Sent Back',
            ApplicationStatus::Resubmitted => 'Submitted',
            ApplicationStatus::Approved => 'Approved',
            ApplicationStatus::Rejected => 'Rejected',
            ApplicationStatus::CertificateReady => 'Certificate Ready',
            ApplicationStatus::Completed => 'Completed',
        };
    }

    public function hasQualificationsAwaitingCorrection(): bool
    {
        return $this->qualifications()
            ->where('verification_state', VerificationState::ReturnedToApplicant->value)
            ->exists();
    }

    public function applicantDisplayStatusLabel(): string
    {
        if ($this->hasQualificationsAwaitingCorrection()) {
            return 'Correction required';
        }

        $outcomeSummary = $this->applicantQualificationOutcomeSummary();
        if ($outcomeSummary !== null) {
            return $outcomeSummary;
        }

        return $this->applicantStatusLabel();
    }

    public function applicantQualificationOutcomeSummary(): ?string
    {
        $this->loadMissing('qualifications');

        if ($this->qualifications->isEmpty()) {
            return null;
        }

        $sync = app(ApplicationQualificationOutcomeSyncService::class);
        if (! $sync->allQualificationsTerminal($this->qualifications)) {
            return null;
        }

        $total = $this->qualifications->count();
        $approvedCount = $this->qualifications->filter(
            fn (Qualification $q) => in_array($q->verification_state, [
                VerificationState::ApprovedForCertificate,
                VerificationState::CertificateIssued,
                VerificationState::Closed,
            ], true)
        )->count();
        $rejectedCount = $this->qualifications
            ->filter(fn (Qualification $q) => $q->verification_state === VerificationState::Rejected)
            ->count();
        $issuedCount = $this->qualifications
            ->filter(fn (Qualification $q) => $q->verification_state === VerificationState::CertificateIssued)
            ->count();

        if ($rejectedCount === $total) {
            return 'Rejected';
        }

        if ($rejectedCount > 0 && $approvedCount > 0) {
            return "Completed — {$approvedCount} approved, {$rejectedCount} rejected";
        }

        if ($issuedCount === $total) {
            return 'Completed — all certificates issued';
        }

        if ($approvedCount === $total) {
            return $issuedCount > 0
                ? "Completed — {$issuedCount} of {$total} certificates issued"
                : 'Approved — awaiting certificate(s)';
        }

        return 'Completed';
    }
}
