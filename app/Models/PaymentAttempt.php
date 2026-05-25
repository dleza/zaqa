<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    protected $fillable = [
        'payment_id',
        'invoice_id',
        'application_id',
        'gateway',
        'method',
        'payment_reference',
        'provider_transaction_id',
        'mobile_number',
        'amount_cents',
        'currency',
        'status',
        'gateway_status',
        'response_code',
        'response_message',
        'initiated_at',
        'confirmed_at',
        'failed_at',
        'rejected_at',
        'expired_at',
        'last_queried_at',
        'query_attempts',
        'next_query_at',
        'request_payload',
        'response_payload',
        'metadata',
    ];

    protected $casts = [
        'amount_cents' => 'int',
        'status' => PaymentAttemptStatus::class,
        'response_code' => 'int',
        'query_attempts' => 'int',
        'initiated_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'failed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'expired_at' => 'datetime',
        'last_queried_at' => 'datetime',
        'next_query_at' => 'datetime',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
