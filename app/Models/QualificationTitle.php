<?php

namespace App\Models;

use App\Support\Normalization\LearnerRecordNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualificationTitle extends Model
{
    protected $fillable = [
        'name',
        'name_normalized',
        'qualification_type_id',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'sort_order' => 'int',
    ];

    public static function normalizeName(string $name): string
    {
        return (string) (LearnerRecordNormalizer::normalizeProgramTitle($name) ?? '');
    }

    protected static function booted(): void
    {
        static::saving(function (QualificationTitle $title) {
            $name = trim((string) $title->name);
            $title->name = $name;
            $title->name_normalized = self::normalizeName($name);
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function qualificationType(): BelongsTo
    {
        return $this->belongsTo(QualificationType::class);
    }

    public function awardingInstitutions(): BelongsToMany
    {
        return $this->belongsToMany(
            AwardingInstitution::class,
            'awarding_institution_qualification_title',
        )->withTimestamps();
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(Qualification::class);
    }
}
