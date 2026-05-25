<?php

namespace App\Models;

use App\Enums\LearnerRecordMatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearnerRecordMatchAttempt extends Model
{
    protected $fillable = [
        'qualification_id',
        'learner_record_id',
        'status',
        'confidence',
        'source',
        'matched_fields',
        'candidate_record_ids',
        'failure_reason',
    ];

    protected $casts = [
        'status' => LearnerRecordMatchStatus::class,
        'confidence' => 'int',
        'matched_fields' => 'array',
        'candidate_record_ids' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(Qualification::class);
    }

    public function learnerRecord(): BelongsTo
    {
        return $this->belongsTo(LearnerRecord::class);
    }
}

