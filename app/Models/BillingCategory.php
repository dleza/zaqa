<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingCategory extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'local_processing_days',
        'foreign_processing_days',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'local_processing_days' => 'int',
        'foreign_processing_days' => 'int',
        'is_active' => 'bool',
        'sort_order' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function qualificationTypes(): HasMany
    {
        return $this->hasMany(QualificationType::class);
    }

    public function feeStructures(): HasMany
    {
        return $this->hasMany(FeeStructure::class);
    }
}

