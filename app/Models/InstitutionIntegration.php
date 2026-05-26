<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstitutionIntegration extends Model
{
    protected $fillable = [
        'awarding_institution_id',
        'is_active',
        'supports_push',
        'supports_pull',
        'lookup_url',
        'auth_type',
        'credentials',
        'request_method',
        'timeout_seconds',
        'retry_attempts',
        'rate_limit_per_minute',
        'driver',
        'config',
        'last_success_at',
        'last_failure_at',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'supports_push' => 'bool',
        'supports_pull' => 'bool',
        'credentials' => 'encrypted:array',
        'config' => 'array',
        'timeout_seconds' => 'int',
        'retry_attempts' => 'int',
        'rate_limit_per_minute' => 'int',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function awardingInstitution(): BelongsTo
    {
        return $this->belongsTo(AwardingInstitution::class);
    }
}

