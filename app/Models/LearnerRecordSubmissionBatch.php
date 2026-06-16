<?php

namespace App\Models;

use App\Enums\LearnerRecordSubmissionBatchStatus;
use App\Enums\LearnerRecordSubmissionSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearnerRecordSubmissionBatch extends Model
{
    protected $fillable = [
        'reference',
        'source_type',
        'source_institution_id',
        'institution_api_client_id',
        'institution_api_batch_id',
        'uploaded_by_user_id',
        'status',
        'total_records',
        'pending_count',
        'approved_count',
        'rejected_count',
        'duplicate_count',
        'failed_validation_count',
        'summary_message',
        'received_at',
        'completed_at',
    ];

    protected $casts = [
        'source_type' => LearnerRecordSubmissionSourceType::class,
        'status' => LearnerRecordSubmissionBatchStatus::class,
        'total_records' => 'int',
        'pending_count' => 'int',
        'approved_count' => 'int',
        'rejected_count' => 'int',
        'duplicate_count' => 'int',
        'failed_validation_count' => 'int',
        'received_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function submissions(): HasMany
    {
        return $this->hasMany(LearnerRecordSubmission::class, 'batch_id');
    }

    public function sourceInstitution(): BelongsTo
    {
        return $this->belongsTo(AwardingInstitution::class, 'source_institution_id');
    }

    public function institutionApiClient(): BelongsTo
    {
        return $this->belongsTo(InstitutionApiClient::class);
    }

    public function institutionApiBatch(): BelongsTo
    {
        return $this->belongsTo(InstitutionApiBatch::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
