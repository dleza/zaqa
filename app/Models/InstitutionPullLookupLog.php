<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstitutionPullLookupLog extends Model
{
    protected $fillable = [
        'awarding_institution_id',
        'institution_integration_id',
        'qualification_id',
        'endpoint',
        'method',
        'correlation_id',
        'status_code',
        'status',
        'request_payload',
        'response_payload',
        'error_message',
        'latency_ms',
    ];

    protected $casts = [
        'status_code' => 'int',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'latency_ms' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function awardingInstitution(): BelongsTo
    {
        return $this->belongsTo(AwardingInstitution::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(InstitutionIntegration::class, 'institution_integration_id');
    }

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(Qualification::class);
    }
}

