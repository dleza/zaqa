<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsBalanceAdjustment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'adjustment_type',
        'amount',
        'reason',
        'actor_user_id',
        'balance_before',
        'balance_after',
        'sms_log_id',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'int',
        'balance_before' => 'int',
        'balance_after' => 'int',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function smsLog(): BelongsTo
    {
        return $this->belongsTo(SmsLog::class);
    }
}
