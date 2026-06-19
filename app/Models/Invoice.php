<?php

namespace App\Models;

use App\Enums\InvoiceDocumentType;
use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'application_id',
        'supplementary_of_invoice_id',
        'billing_category_id',
        'qualification_type_id',
        'fee_structure_id',
        'is_foreign_snapshot',
        'processing_days_snapshot',
        'fee_label_snapshot',
        'invoice_number',
        'quotation_number',
        'document_type',
        'currency',
        'amount_cents',
        'status',
        'issued_at',
        'due_at',
        'expires_at',
        'paid_at',
        'converted_to_invoice_at',
        'metadata',
    ];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'document_type' => InvoiceDocumentType::class,
        'amount_cents' => 'int',
        'is_foreign_snapshot' => 'bool',
        'processing_days_snapshot' => 'int',
        'metadata' => 'array',
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'converted_to_invoice_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function primaryInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'supplementary_of_invoice_id');
    }

    public function supplementaryInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'supplementary_of_invoice_id');
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
