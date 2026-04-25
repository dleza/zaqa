<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'iso_code',
        'name',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'bool',
    ];

    public function awardingBodies(): HasMany
    {
        return $this->hasMany(AwardingBody::class);
    }

    public function awardingInstitutions(): HasMany
    {
        return $this->hasMany(AwardingInstitution::class);
    }
}

