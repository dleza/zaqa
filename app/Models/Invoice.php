<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'application_id',
        'billing_category_id',
        'qualification_type_id',
        'fee_structure_id',
        'is_foreign_snapshot',
        'processing_days_snapshot',
        'fee_label_snapshot',
        'invoice_number',
        'currency',
        'amount_cents',
        'status',
        'issued_at',
        'due_at',
        'paid_at',
        'metadata',
    ];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'amount_cents' => 'int',
        'is_foreign_snapshot' => 'bool',
        'processing_days_snapshot' => 'int',
        'metadata' => 'array',
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function billingCategory(): BelongsTo
    {
        return $this->belongsTo(BillingCategory::class);
    }

    public function qualificationType(): BelongsTo
    {
        return $this->belongsTo(QualificationType::class);
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}

