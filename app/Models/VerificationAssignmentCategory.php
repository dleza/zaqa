<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VerificationAssignmentCategory extends Model
{
    protected $fillable = [
        'name',
        'type',
        'country_id',
        'awarding_institution_id',
        'is_active',
        'last_assigned_user_id',
        'last_assigned_at',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'last_assigned_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function awardingInstitution(): BelongsTo
    {
        return $this->belongsTo(AwardingInstitution::class);
    }

    public function lastAssignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_assigned_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(VerificationAssignmentCategoryUser::class, 'verification_assignment_category_id');
    }
}

