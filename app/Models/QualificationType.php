<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualificationType extends Model
{
    protected $fillable = [
        'zqf_level_code',
        'level_label',
        'name',
        'short_name',
        'description',
        'billing_category_id',
        'requires_subject_results',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'requires_subject_results' => 'bool',
        'is_active' => 'bool',
        'sort_order' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function billingCategory(): BelongsTo
    {
        return $this->belongsTo(BillingCategory::class);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(Qualification::class);
    }
}

