<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $fillable = [
        'user_id',
        'application_id',
        'phone_number',
        'normalized_phone',
        'message_type',
        'message_body',
        'message_length',
        'provider',
        'status',
        'skip_reason',
        'provider_reference',
        'http_status',
        'provider_response',
        'balance_adjustment_id',
        'attempt_count',
        'sent_at',
    ];

    protected $casts = [
        'message_length' => 'int',
        'http_status' => 'int',
        'provider_response' => 'array',
        'balance_adjustment_id' => 'int',
        'attempt_count' => 'int',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function balanceAdjustment(): BelongsTo
    {
        return $this->belongsTo(SmsBalanceAdjustment::class, 'balance_adjustment_id');
    }
}
