<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeStructure extends Model
{
    protected $fillable = [
        'billing_category_id',
        'local_fee_cents',
        'foreign_fee_cents',
        'currency',
        'effective_from',
        'effective_to',
        'is_active',
        'approved_by_user_id',
        'change_reason',
    ];

    protected $casts = [
        'local_fee_cents' => 'int',
        'foreign_fee_cents' => 'int',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'is_active' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function billingCategory(): BelongsTo
    {
        return $this->belongsTo(BillingCategory::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}

