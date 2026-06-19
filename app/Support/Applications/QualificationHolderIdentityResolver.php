<?php

namespace App\Support\Applications;

use App\Enums\ApplicantType;
use App\Models\Application;
use App\Models\InstitutionProfile;
use App\Models\Qualification;
use App\Models\User;
use App\Support\Certificates\CertificateHolderName;

final class QualificationHolderIdentityResolver
{
    public static function isInstitutionalMultiple(Application $application): bool
    {
        return ApplicationSubmissionMode::isInstitutionalMultiple($application);
    }

    public static function resolveDisplayName(Qualification $qualification, Application $application): string
    {
        if (self::isInstitutionalMultiple($application)) {
            $fromQual = trim((string) ($qualification->qualification_holder_name ?? ''));
            if ($fromQual !== '') {
                return CertificateHolderName::format($fromQual) ?? $fromQual;
            }

            $fromDoc = trim((string) ($qualification->names_as_on_qualification_document ?? ''));
            if ($fromDoc !== '') {
                return CertificateHolderName::format($fromDoc) ?? $fromDoc;
            }

            return '—';
        }

        return CertificateHolderName::resolve($qualification, $application)['display'];
    }

    public static function resolveIdentityNumber(Qualification $qualification, Application $application): ?string
    {
        $fromQual = trim((string) ($qualification->nrc_passport_number ?? ''));
        if ($fromQual !== '') {
            return $fromQual;
        }

        if (self::isInstitutionalMultiple($application)) {
            return null;
        }

        $meta = is_array($application->metadata) ? $application->metadata : [];
        $subject = is_array($meta['verification_subject'] ?? null) ? $meta['verification_subject'] : [];
        $identityType = strtolower(trim((string) ($subject['identity_type'] ?? '')));
        if ($identityType === 'passport') {
            $passport = trim((string) ($subject['passport_number'] ?? ''));

            return $passport !== '' ? $passport : null;
        }

        $nrc = trim((string) ($subject['nrc_number'] ?? ''));

        return $nrc !== '' ? $nrc : null;
    }

    public static function resolveAdminApplicantLabel(Qualification $qualification, Application $application): string
    {
        if (self::isInstitutionalMultiple($application)) {
            $application->loadMissing('applicant.institutionProfile');
            $institution = trim((string) ($application->applicant?->institutionProfile?->institution_name ?? ''));
            if ($institution !== '') {
                return $institution;
            }

            return trim((string) ($application->applicant?->name ?? '')) ?: 'Institution';
        }

        $meta = is_array($application->metadata) ? $application->metadata : [];
        $subject = is_array($meta['verification_subject'] ?? null) ? $meta['verification_subject'] : [];
        $fromSubject = trim((string) ($subject['full_name'] ?? ''));
        if ($fromSubject !== '') {
            return $fromSubject;
        }

        return trim((string) ($application->applicant?->name ?? '')) ?: '—';
    }

    public static function requiresPerQualificationIdentityDocument(Application $application): bool
    {
        return self::isInstitutionalMultiple($application);
    }

    /**
     * @return array<string, mixed>
     */
    public static function composeHolderIdentityFromPayload(array $data): array
    {
        $first = trim((string) ($data['holder_first_name'] ?? ''));
        $middle = trim((string) ($data['holder_middle_name'] ?? ''));
        $surname = trim((string) ($data['holder_surname'] ?? ''));
        $identityType = strtolower(trim((string) ($data['holder_identity_type'] ?? 'nrc')));
        if (! in_array($identityType, ['nrc', 'passport'], true)) {
            $identityType = 'nrc';
        }

        $holderName = trim(implode(' ', array_filter([$first, $middle, $surname], fn ($p) => $p !== '')));
        $nrcPassport = trim((string) ($data['nrc_passport_number'] ?? ''));

        $identity = array_filter([
            'first_name' => $first !== '' ? $first : null,
            'middle_name' => $middle !== '' ? $middle : null,
            'surname' => $surname !== '' ? $surname : null,
            'identity_type' => $identityType,
            'date_of_birth' => ($dob = trim((string) ($data['holder_date_of_birth'] ?? ''))) !== '' ? $dob : null,
            'gender' => ($g = trim((string) ($data['holder_gender'] ?? ''))) !== '' ? $g : null,
            'phone' => ($p = trim((string) ($data['holder_phone'] ?? ''))) !== '' ? $p : null,
            'email' => ($e = trim((string) ($data['holder_email'] ?? ''))) !== '' ? $e : null,
        ], fn ($v) => $v !== null && $v !== '');

        return [
            'holder_name' => $holderName,
            'nrc_passport_number' => $nrcPassport,
            'holder_identity' => $identity,
        ];
    }

    public static function resolveBillToName(Application $application, ?User $applicant = null): string
    {
        if (self::isInstitutionalMultiple($application)) {
            $applicant?->loadMissing('institutionProfile');
            /** @var InstitutionProfile|null $profile */
            $profile = $applicant?->institutionProfile;
            $name = trim((string) ($profile?->institution_name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        $meta = is_array($application->metadata) ? $application->metadata : [];
        $subject = is_array($meta['verification_subject'] ?? null) ? $meta['verification_subject'] : [];
        $fromSubject = trim((string) ($subject['full_name'] ?? ''));
        if ($fromSubject !== '') {
            return $fromSubject;
        }

        return trim((string) ($applicant?->name ?? '')) ?: 'Applicant';
    }

    public static function applicantIsInstitution(?User $user): bool
    {
        return ($user?->applicant_type?->value ?? (string) ($user?->applicant_type ?? '')) === ApplicantType::Institution->value;
    }
}
