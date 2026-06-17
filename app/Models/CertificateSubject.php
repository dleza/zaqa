<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificateSubject extends Model
{
    protected $fillable = [
        'name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'int',
        'is_active' => 'bool',
    ];

    public function qualificationSubjectResults(): HasMany
    {
        return $this->hasMany(QualificationSubjectResult::class);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function resolveIdByName(?string $name): ?int
    {
        $normalized = strtolower(trim((string) $name));
        if ($normalized === '') {
            return null;
        }

        $id = static::query()
            ->active()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->value('id');

        return $id ? (int) $id : null;
    }
}
