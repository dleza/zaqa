<?php

namespace App\Models;

use App\Enums\VerificationState;
use App\Enums\LearnerRecordMatchStatus;
use App\Enums\QualificationTitleSource;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Qualification extends Model
{
    protected $fillable = [
        'application_id',
        'learner_record_id',
        'verification_reference_number',
        'awarding_institution_id',
        'awarding_institution_name',
        'awarding_institution_name_other',
        'qualification_holder_name',
        'country_id',
        'country_name_other',
        'awarding_body_id',
        'awarding_body_name_other',
        'nrc_passport_number',
        'certificate_number',
        'student_number',
        'examination_number',
        'title_of_qualification',
        'applicant_entered_qualification_title',
        'verified_qualification_title',
        'qualification_title_source',
        'award_date',
        'qualification_type',
        'qualification_type_id',
        'is_foreign_qualification',
        'verification_state',
        'assigned_verifier_id',
        'assigned_at',
        'reviewed_at',
        'returned_to_applicant_at',
        'auto_verification_attempted_at',
        'institution_pull_lookup_dispatched_at',
        'institution_pull_lookup_attempted_at',
        'institution_pull_lookup_status',
        'institution_pull_lookup_last_error',
        'auto_verification_status',
        'auto_verified_at',
        'auto_verification_confidence',
        'auto_verification_failure_reason',
        'auto_verification_match_summary',
        'verification_source',
        'send_back_by_user_id',
        'send_back_reopen_level',
        'level2_review_owner_id',
        'level2_review_locked_by',
        'level2_review_locked_at',
        'reviewer_notes',
        'fee_currency',
        'fee_amount_cents',
        'transcript_required',
        'transcript_reason',
        'notes',
        'raw_subject_results',
    ];

    protected $casts = [
        'award_date' => 'date',
        'transcript_required' => 'bool',
        'is_foreign_qualification' => 'bool',
        'assigned_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'returned_to_applicant_at' => 'datetime',
        'auto_verification_attempted_at' => 'datetime',
        'institution_pull_lookup_dispatched_at' => 'datetime',
        'institution_pull_lookup_attempted_at' => 'datetime',
        'auto_verification_status' => LearnerRecordMatchStatus::class,
        'auto_verified_at' => 'datetime',
        'auto_verification_confidence' => 'int',
        'auto_verification_match_summary' => AsArrayObject::class,
        'qualification_title_source' => QualificationTitleSource::class,
        'verification_state' => VerificationState::class,
        'level2_review_locked_at' => 'datetime',
        'fee_amount_cents' => 'int',
        'raw_subject_results' => AsArrayObject::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function awardingBody(): BelongsTo
    {
        return $this->belongsTo(AwardingBody::class);
    }

    public function awardingInstitution(): BelongsTo
    {
        return $this->belongsTo(AwardingInstitution::class);
    }

    public function qualificationTypeMaster(): BelongsTo
    {
        return $this->belongsTo(QualificationType::class, 'qualification_type_id');
    }

    public function learnerRecord(): BelongsTo
    {
        return $this->belongsTo(LearnerRecord::class);
    }

    public function learnerRecordMatchAttempts(): HasMany
    {
        return $this->hasMany(LearnerRecordMatchAttempt::class);
    }

    public function assignedVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_verifier_id');
    }

    public function sendBackBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'send_back_by_user_id');
    }

    public function level2ReviewOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'level2_review_owner_id');
    }

    public function level2ReviewLockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'level2_review_locked_by');
    }

    public function consentForm(): HasOne
    {
        return $this->hasOne(ConsentForm::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(QualificationAssignment::class);
    }

    public function subjectResults(): HasMany
    {
        return $this->hasMany(QualificationSubjectResult::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(QualificationDocument::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(QualificationCertificate::class);
    }
}
