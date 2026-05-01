<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Qualification extends Model
{
    protected $fillable = [
        'application_id',
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
        'award_date',
        'qualification_type',
        'qualification_type_id',
        'is_foreign_qualification',
        'verification_state',
        'assigned_verifier_id',
        'assigned_at',
        'reviewed_at',
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

    public function assignedVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_verifier_id');
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
}

