<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(
            Country::class,
            'verification_assignment_category_countries',
            'verification_assignment_category_id',
            'country_id',
        )->withTimestamps();
    }

    public function awardingInstitutions(): BelongsToMany
    {
        return $this->belongsToMany(
            AwardingInstitution::class,
            'verification_assignment_category_awarding_institutions',
            'verification_assignment_category_id',
            'awarding_institution_id',
        )->withTimestamps();
    }

    public function lastAssignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_assigned_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(VerificationAssignmentCategoryUser::class, 'verification_assignment_category_id');
    }

    public function level1Memberships(): HasMany
    {
        return $this->memberships()->where('review_level', 'level1');
    }

    public function level2Memberships(): HasMany
    {
        return $this->memberships()->where('review_level', 'level2');
    }

    public function isForeignCountryType(): bool
    {
        return (string) $this->type === 'foreign_country';
    }

    public function isLocalInstitutionType(): bool
    {
        return (string) $this->type === 'local_institution';
    }
}
