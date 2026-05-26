<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\URL;

class AwardingInstitution extends Model
{
    protected $fillable = [
        'country_id',
        'name',
        'consent_form_path',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'bool',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function learnerRecords(): HasMany
    {
        return $this->hasMany(LearnerRecord::class);
    }

    public function learnerRecordImports(): HasMany
    {
        return $this->hasMany(LearnerRecordImport::class);
    }

    public function integration(): HasOne
    {
        return $this->hasOne(InstitutionIntegration::class);
    }

    public function getHasConsentFormAttribute(): bool
    {
        return trim((string) ($this->consent_form_path ?? '')) !== '';
    }

    public function getConsentFormUrlAttribute(): ?string
    {
        if (! $this->has_consent_form) {
            return null;
        }

        return URL::signedRoute('applicant.reference.awarding_institutions.consent_form', ['awardingInstitution' => $this->id]);
    }
}
