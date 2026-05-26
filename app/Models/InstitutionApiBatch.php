<?php

namespace App\Models;

use App\Enums\InstitutionApiBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstitutionApiBatch extends Model
{
    protected $fillable = [
        'institution_api_client_id',
        'awarding_institution_id',
        'status',
        'records_json',
        'total_records',
        'processed_records',
        'inserted_records',
        'updated_records',
        'failed_records',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => InstitutionApiBatchStatus::class,
        'total_records' => 'int',
        'processed_records' => 'int',
        'inserted_records' => 'int',
        'updated_records' => 'int',
        'failed_records' => 'int',
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(InstitutionApiClient::class, 'institution_api_client_id');
    }

    public function awardingInstitution(): BelongsTo
    {
        return $this->belongsTo(AwardingInstitution::class);
    }
}

