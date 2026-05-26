<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstitutionIntegrationLog extends Model
{
    protected $fillable = [
        'awarding_institution_id',
        'institution_api_client_id',
        'endpoint',
        'method',
        'correlation_id',
        'status_code',
        'status',
        'request_payload',
        'response_payload',
        'error_message',
        'latency_ms',
        'ip_address',
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(InstitutionApiClient::class, 'institution_api_client_id');
    }
}

