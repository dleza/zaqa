<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualificationType extends Model
{
    public const CERTIFICATE_TEMPLATE_DEFAULT = 'default';

    public const CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS = 'school_subjects';

    protected $fillable = [
        'zqf_level_code',
        'level_label',
        'name',
        'short_name',
        'description',
        'billing_category_id',
        'certificate_template_key',
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

    public function resolvedCertificateTemplateKey(?string $fallbackQualificationTypeCode = null): string
    {
        return self::resolveCertificateTemplateKey($this, $fallbackQualificationTypeCode);
    }

    public static function resolveCertificateTemplateKey(?self $type, ?string $fallbackQualificationTypeCode = null): string
    {
        $configured = trim((string) ($type?->certificate_template_key ?? ''));
        if (in_array($configured, [self::CERTIFICATE_TEMPLATE_DEFAULT, self::CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS], true)) {
            return $configured;
        }

        if ($type?->requires_subject_results) {
            return self::CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS;
        }

        $fallbackCode = trim((string) ($fallbackQualificationTypeCode ?: $type?->zqf_level_code));
        if (in_array($fallbackCode, ['L1', 'L2A', 'L2B'], true)) {
            return self::CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS;
        }

        return self::CERTIFICATE_TEMPLATE_DEFAULT;
    }

    public static function certificateTemplateLabel(?string $templateKey): string
    {
        return match ($templateKey) {
            self::CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS => 'School certificate with subjects',
            default => 'Standard certificate',
        };
    }
}
