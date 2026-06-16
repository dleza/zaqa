<?php

namespace App\Models;

use App\Enums\LearnerRecordReviewDecision;
use App\Enums\LearnerRecordSubmissionSourceType;
use App\Enums\LearnerRecordSubmissionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearnerRecordSubmission extends Model
{
    protected $fillable = [
        'batch_id',
        'source_type',
        'source_institution_id',
        'source_integration_id',
        'institution_api_client_id',
        'institution_api_batch_id',
        'source_reference',
        'external_record_id',
        'row_number',
        'student_id',
        'certificate_no',
        'nrc_number',
        'passport_no',
        'first_name',
        'last_name',
        'other_names',
        'gender',
        'program_of_study',
        'year_awarded',
        'award_date',
        'classification',
        'qualification_title_id',
        'qualification_type_id',
        'examination_number',
        'nrc_normalized',
        'passport_normalized',
        'name_normalized',
        'student_id_normalized',
        'certificate_no_normalized',
        'qualification_title_normalized',
        'dedupe_hash',
        'payload_json',
        'status',
        'validation_errors',
        'risk_flags',
        'duplicate_candidates',
        'review_decision',
        'target_learner_record_id',
        'approved_learner_record_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'review_locked_by_user_id',
        'review_locked_at',
        'received_at',
    ];

    protected $casts = [
        'source_type' => LearnerRecordSubmissionSourceType::class,
        'status' => LearnerRecordSubmissionStatus::class,
        'review_decision' => LearnerRecordReviewDecision::class,
        'year_awarded' => 'int',
        'award_date' => 'date',
        'payload_json' => 'array',
        'validation_errors' => 'array',
        'risk_flags' => 'array',
        'duplicate_candidates' => 'array',
        'reviewed_at' => 'datetime',
        'review_locked_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LearnerRecordSubmissionBatch::class, 'batch_id');
    }

    public function sourceInstitution(): BelongsTo
    {
        return $this->belongsTo(AwardingInstitution::class, 'source_institution_id');
    }

    public function sourceIntegration(): BelongsTo
    {
        return $this->belongsTo(InstitutionIntegration::class, 'source_integration_id');
    }

    public function institutionApiClient(): BelongsTo
    {
        return $this->belongsTo(InstitutionApiClient::class);
    }

    public function institutionApiBatch(): BelongsTo
    {
        return $this->belongsTo(InstitutionApiBatch::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function reviewLockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_locked_by_user_id');
    }

    public function approvedLearnerRecord(): BelongsTo
    {
        return $this->belongsTo(LearnerRecord::class, 'approved_learner_record_id');
    }

    public function targetLearnerRecord(): BelongsTo
    {
        return $this->belongsTo(LearnerRecord::class, 'target_learner_record_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', LearnerRecordSubmissionStatus::Pending->value);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', LearnerRecordSubmissionStatus::Approved->value);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', LearnerRecordSubmissionStatus::Rejected->value);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForInstitution(Builder $query, int $institutionId): Builder
    {
        return $query->where('source_institution_id', $institutionId);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $w) use ($term) {
            $w->where('student_id', 'like', "%{$term}%")
                ->orWhere('certificate_no', 'like', "%{$term}%")
                ->orWhere('nrc_number', 'like', "%{$term}%")
                ->orWhere('passport_no', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('other_names', 'like', "%{$term}%")
                ->orWhere('program_of_study', 'like', "%{$term}%");
        });
    }

    public function displayName(): string
    {
        $parts = array_filter([
            trim((string) ($this->first_name ?? '')),
            trim((string) ($this->other_names ?? '')),
            trim((string) ($this->last_name ?? '')),
        ], fn (string $p) => $p !== '');

        return $parts !== [] ? implode(' ', $parts) : 'Unknown learner';
    }

    public function duplicateCandidateCount(): int
    {
        $candidates = $this->duplicate_candidates;

        return is_array($candidates) ? count($candidates) : 0;
    }
}
