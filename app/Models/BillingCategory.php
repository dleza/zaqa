<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingCategory extends Model
{
    public const CODE_FOREIGN_QUALIFICATIONS = 'FOREIGN_QUALIFICATIONS';

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

    public function isSystemCategory(): bool
    {
        return (string) $this->code === self::CODE_FOREIGN_QUALIFICATIONS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string}>
     */
    public static function optionsForSelect(?int $includeId = null): \Illuminate\Support\Collection
    {
        return static::query()
            ->where(function ($q) use ($includeId) {
                $q->where('is_active', true);
                if ($includeId !== null && $includeId > 0) {
                    $q->orWhere('id', $includeId);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (self $c) => ['id' => $c->id, 'name' => $c->name])
            ->values();
    }
}

