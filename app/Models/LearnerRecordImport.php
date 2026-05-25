<?php

namespace App\Models;

use App\Enums\LearnerRecordImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearnerRecordImport extends Model
{
    protected $fillable = [
        'uploaded_by_user_id',
        'awarding_institution_id',
        'file_path',
        'original_filename',
        'status',
        'total_rows',
        'processed_rows',
        'inserted_rows',
        'updated_rows',
        'failed_rows',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => LearnerRecordImportStatus::class,
        'total_rows' => 'int',
        'processed_rows' => 'int',
        'inserted_rows' => 'int',
        'updated_rows' => 'int',
        'failed_rows' => 'int',
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function awardingInstitution(): BelongsTo
    {
        return $this->belongsTo(AwardingInstitution::class);
    }

    public function learnerRecords(): HasMany
    {
        return $this->hasMany(LearnerRecord::class, 'import_id');
    }
}

