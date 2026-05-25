<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    protected $fillable = [
        'application_id',
        'invoice_id',
        'method',
        'status',
        'currency',
        'amount_cents',
        'provider',
        'provider_reference',
        'provider_transaction_id',
        'mobile_number',
        'proof_document_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_comment',
        'rejection_reason',
        'initiated_at',
        'confirmed_at',
        'failed_at',
        'rejected_at',
        'expires_at',
        'last_status_at',
        'raw_payload',
    ];

    protected $casts = [
        'method' => PaymentMethod::class,
        'status' => PaymentStatus::class,
        'amount_cents' => 'int',
        'raw_payload' => 'array',
        'reviewed_at' => 'datetime',
        'initiated_at' => 'datetime',
        'awaiting_finance_review_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'failed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_status_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function proofDocument(): BelongsTo
    {
        return $this->belongsTo(QualificationDocument::class, 'proof_document_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function latestAttempt(): HasOne
    {
        return $this->hasOne(PaymentAttempt::class)->latestOfMany();
    }
}
