<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookLog extends Model
{
    protected $fillable = [
        'provider',
        'event_type',
        'provider_reference',
        'provider_transaction_id',
        'application_id',
        'payment_id',
        'payload',
        'signature_valid',
        'received_at',
        'processed_at',
        'process_status',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
        'signature_valid' => 'bool',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

