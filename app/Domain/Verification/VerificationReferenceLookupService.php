<?php

namespace App\Domain\Verification;

use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Support\Search\ReferenceSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class VerificationReferenceLookupService
{
    public function __construct(
        private readonly VerificationLookupStatusMapper $statusMapper,
    ) {}

    /**
     * @return array{
     *   found: bool,
     *   searched_by: string|null,
     *   application_reference: string|null,
     *   qualification_reference: string|null,
     *   status: string,
     *   status_label: string,
     *   message: string,
     *   tone: string,
     *   qualifications: list<array<string, mixed>>,
     *   qualification: array<string, mixed>|null,
     *   certificate: array<string, mixed>|null
     * }
     */
    public function lookup(?string $applicationReference, ?string $qualificationReference, ?int $restrictToAwardingInstitutionId = null, ?string $certificateReference = null): array
    {
        $this->assertExactlyOneReference($applicationReference, $qualificationReference, $certificateReference);

        $certRef = ReferenceSearch::normalize($certificateReference);
        if (ReferenceSearch::isUsablePrefix($certRef)) {
            return $this->lookupByCertificateReference($certRef, $restrictToAwardingInstitutionId);
        }

        $qualRef = ReferenceSearch::normalize($qualificationReference);
        if (ReferenceSearch::isUsablePrefix($qualRef)) {
            return $this->lookupByQualificationReference($qualRef, $restrictToAwardingInstitutionId);
        }

        $appRef = ReferenceSearch::normalize($applicationReference);

        return $this->lookupByApplicationReference($appRef, $restrictToAwardingInstitutionId);
    }

    public function assertExactlyOneReference(?string $applicationReference, ?string $qualificationReference, ?string $certificateReference = null): void
    {
        $appRef = ReferenceSearch::normalize($applicationReference);
        $qualRef = ReferenceSearch::normalize($qualificationReference);
        $certRef = ReferenceSearch::normalize($certificateReference);

        $provided = array_filter([
            'application_reference' => $appRef,
            'qualification_reference' => $qualRef,
            'certificate_reference' => $certRef,
        ]);

        if (count($provided) > 1) {
            throw ValidationException::withMessages([
                'reference' => 'Provide one reference only.',
                'application_reference' => 'Provide one reference only (application, qualification, or certificate).',
                'qualification_reference' => 'Provide one reference only (application, qualification, or certificate).',
                'certificate_reference' => 'Provide one reference only (application, qualification, or certificate).',
            ]);
        }

        if (! ReferenceSearch::isUsablePrefix($appRef) && ! ReferenceSearch::isUsablePrefix($qualRef) && ! ReferenceSearch::isUsablePrefix($certRef)) {
            throw ValidationException::withMessages([
                'reference' => 'Enter a reference with at least three characters.',
                'application_reference' => 'Enter an application, qualification, or certificate reference (at least three characters).',
                'qualification_reference' => 'Enter an application, qualification, or certificate reference (at least three characters).',
                'certificate_reference' => 'Enter an application, qualification, or certificate reference (at least three characters).',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function lookupByApplicationReference(?string $appRef, ?int $restrictToAwardingInstitutionId): array
    {
        $applications = Application::query()
            ->select(['id', 'application_number'])
            ->when(true, fn (Builder $q) => ReferenceSearch::applyApplicationReference($q, $appRef))
            ->orderBy('application_number')
            ->limit(25)
            ->get();

        if ($applications->isEmpty()) {
            return $this->notFoundPayload('application_reference');
        }

        $qualifications = Qualification::query()
            ->with([
                'application:id,application_number',
                'awardingInstitution:id,name',
                'country:id,name',
                'certificates' => fn ($q) => $q->orderByDesc('id'),
            ])
            ->whereIn('application_id', $applications->pluck('id'))
            ->when(
                $restrictToAwardingInstitutionId,
                fn (Builder $q) => $q->where('awarding_institution_id', $restrictToAwardingInstitutionId),
            )
            ->orderBy('verification_reference_number')
            ->limit(100)
            ->get();

        if ($qualifications->isEmpty()) {
            return $this->notFoundPayload('application_reference');
        }

        $rows = $qualifications
            ->map(fn (Qualification $qualification) => $this->mapQualificationSummary($qualification))
            ->values()
            ->all();

        $primary = $rows[0];

        return [
            'found' => true,
            'searched_by' => 'application_reference',
            'application_reference' => (string) ($applications->first()->application_number ?? $appRef),
            'qualification_reference' => count($rows) === 1 ? ($primary['qualification_reference'] ?? null) : null,
            'status' => count($rows) === 1 ? ($primary['status'] ?? VerificationLookupStatusMapper::STATUS_IN_REVIEW) : VerificationLookupStatusMapper::STATUS_IN_REVIEW,
            'status_label' => count($rows) === 1 ? ($primary['status_label'] ?? 'In Review') : 'Multiple qualifications',
            'message' => count($rows) === 1
                ? ($primary['message'] ?? 'Verification record found.')
                : 'Multiple qualification records were found for this application reference.',
            'tone' => count($rows) === 1 ? ($primary['tone'] ?? 'neutral') : 'neutral',
            'qualifications' => $rows,
            'qualification' => count($rows) === 1 ? ($primary['qualification'] ?? null) : null,
            'certificate' => count($rows) === 1 ? ($primary['certificate'] ?? null) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lookupByQualificationReference(string $qualRef, ?int $restrictToAwardingInstitutionId): array
    {
        $qualifications = Qualification::query()
            ->with([
                'application:id,application_number',
                'awardingInstitution:id,name',
                'country:id,name',
                'certificates' => fn ($q) => $q->orderByDesc('id'),
            ])
            ->when(true, fn (Builder $q) => ReferenceSearch::applyQualificationReference($q, $qualRef))
            ->when(
                $restrictToAwardingInstitutionId,
                fn (Builder $q) => $q->where('awarding_institution_id', $restrictToAwardingInstitutionId),
            )
            ->orderBy('verification_reference_number')
            ->limit(25)
            ->get();

        if ($qualifications->isEmpty()) {
            return $this->notFoundPayload('qualification_reference');
        }

        $exact = $qualifications->first(
            fn (Qualification $q) => strcasecmp((string) $q->verification_reference_number, $qualRef) === 0,
        );

        if ($exact instanceof Qualification) {
            $mapped = $this->mapQualificationSummary($exact);

            return [
                'found' => true,
                'searched_by' => 'qualification_reference',
                'application_reference' => (string) ($exact->application?->application_number ?? ''),
                'qualification_reference' => (string) ($exact->verification_reference_number ?? ''),
                'status' => $mapped['status'],
                'status_label' => $mapped['status_label'],
                'message' => $mapped['message'],
                'tone' => $mapped['tone'],
                'qualifications' => [$mapped],
                'qualification' => $mapped['qualification'],
                'certificate' => $mapped['certificate'],
            ];
        }

        $rows = $qualifications
            ->map(fn (Qualification $qualification) => $this->mapQualificationSummary($qualification))
            ->values()
            ->all();

        return [
            'found' => true,
            'searched_by' => 'qualification_reference',
            'application_reference' => null,
            'qualification_reference' => $qualRef,
            'status' => VerificationLookupStatusMapper::STATUS_IN_REVIEW,
            'status_label' => 'Multiple matches',
            'message' => 'Multiple qualification references matched this prefix. Enter the full qualification reference.',
            'tone' => 'neutral',
            'qualifications' => $rows,
            'qualification' => null,
            'certificate' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lookupByCertificateReference(string $certRef, ?int $restrictToAwardingInstitutionId): array
    {
        $certificates = QualificationCertificate::query()
            ->with([
                'qualification.application:id,application_number',
                'qualification.awardingInstitution:id,name',
                'qualification.country:id,name',
                'qualification.certificates' => fn ($q) => $q->orderByDesc('id'),
            ])
            ->when(true, fn (Builder $q) => ReferenceSearch::applyCertificateReference($q, $certRef))
            ->when(
                $restrictToAwardingInstitutionId,
                fn (Builder $q) => $q->whereHas(
                    'qualification',
                    fn (Builder $qq) => $qq->where('awarding_institution_id', $restrictToAwardingInstitutionId),
                ),
            )
            ->orderBy('certificate_number')
            ->limit(25)
            ->get();

        if ($certificates->isEmpty()) {
            return $this->notFoundPayload('certificate_reference');
        }

        $exact = $certificates->first(
            fn (QualificationCertificate $certificate) => strcasecmp((string) $certificate->certificate_number, $certRef) === 0,
        );

        if ($exact instanceof QualificationCertificate && $exact->qualification) {
            $mapped = $this->mapQualificationSummary($exact->qualification);
            $mapped['certificate'] = $this->mapCertificatePayload($exact->qualification, $exact);
            $mapped['certificate_number'] = $exact->certificate_number;
            $mapped['public_verification_url'] = $this->publicVerificationUrl($exact);

            return [
                'found' => true,
                'searched_by' => 'certificate_reference',
                'application_reference' => (string) ($exact->qualification->application?->application_number ?? ''),
                'qualification_reference' => (string) ($exact->qualification->verification_reference_number ?? ''),
                'status' => $mapped['status'],
                'status_label' => $mapped['status_label'],
                'message' => $mapped['message'],
                'tone' => $mapped['tone'],
                'qualifications' => [$mapped],
                'qualification' => $mapped['qualification'],
                'certificate' => $mapped['certificate'],
            ];
        }

        $qualifications = $certificates
            ->map(fn (QualificationCertificate $certificate) => $certificate->qualification)
            ->filter()
            ->unique('id')
            ->values();

        $rows = $qualifications
            ->map(fn (Qualification $qualification) => $this->mapQualificationSummary($qualification))
            ->values()
            ->all();

        if (count($rows) === 1) {
            $primary = $rows[0];

            return [
                'found' => true,
                'searched_by' => 'certificate_reference',
                'application_reference' => $primary['application_reference'] ?? null,
                'qualification_reference' => $primary['qualification_reference'] ?? null,
                'status' => $primary['status'],
                'status_label' => $primary['status_label'],
                'message' => $primary['message'],
                'tone' => $primary['tone'],
                'qualifications' => $rows,
                'qualification' => $primary['qualification'],
                'certificate' => $primary['certificate'],
            ];
        }

        return [
            'found' => true,
            'searched_by' => 'certificate_reference',
            'application_reference' => null,
            'qualification_reference' => null,
            'status' => VerificationLookupStatusMapper::STATUS_IN_REVIEW,
            'status_label' => 'Multiple matches',
            'message' => 'Multiple certificate references matched this prefix. Enter the full certificate reference.',
            'tone' => 'neutral',
            'qualifications' => $rows,
            'qualification' => null,
            'certificate' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapQualificationSummary(Qualification $qualification): array
    {
        $certificate = $this->statusMapper->resolveDisplayCertificate($qualification);
        $status = $this->statusMapper->resolveForQualification($qualification, $certificate);
        $certificatePayload = $this->mapCertificatePayload($qualification, $certificate);

        return [
            'qualification_reference' => (string) ($qualification->verification_reference_number ?? ''),
            'application_reference' => (string) ($qualification->application?->application_number ?? ''),
            'holder_name' => $this->holderName($qualification),
            'qualification_title' => trim((string) ($qualification->title_of_qualification ?? '')) ?: '—',
            'awarding_institution' => $this->awardingInstitutionName($qualification),
            'country' => $this->countryName($qualification),
            'award_date' => optional($qualification->award_date)?->toDateString(),
            'status' => $status['status'],
            'status_label' => $status['status_label'],
            'message' => $status['message'],
            'tone' => $status['tone'],
            'qualification' => [
                'holder_name' => $this->holderName($qualification),
                'title' => trim((string) ($qualification->title_of_qualification ?? '')) ?: '—',
                'awarding_institution' => $this->awardingInstitutionName($qualification),
                'country' => $this->countryName($qualification),
                'award_date' => optional($qualification->award_date)?->toDateString(),
            ],
            'certificate' => $certificatePayload,
            'public_verification_url' => $certificatePayload['public_verification_url'] ?? null,
            'certificate_number' => $certificatePayload['number'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapCertificatePayload(Qualification $qualification, ?QualificationCertificate $certificate): ?array
    {
        $context = $this->statusMapper->resolveCertificateContext($qualification, $certificate);
        $display = $context['certificate'];

        if (! $display instanceof QualificationCertificate) {
            return [
                'exists' => false,
                'type' => null,
                'type_label' => null,
                'number' => null,
                'issued_at' => null,
                'revoked' => false,
                'revoked_at' => null,
                'public_verification_url' => null,
            ];
        }

        return [
            'exists' => true,
            'type' => $this->statusMapper->certificateTypeKey($display),
            'type_label' => $this->statusMapper->certificateTypeLabel($display),
            'number' => $display->certificate_number,
            'issued_at' => optional($display->issued_at)?->toDateString(),
            'revoked' => (bool) $context['revoked'],
            'revoked_at' => $context['revoked_at'],
            'public_verification_url' => $this->publicVerificationUrl($display),
        ];
    }

    private function publicVerificationUrl(QualificationCertificate $certificate): ?string
    {
        $token = trim((string) ($certificate->verification_token ?? ''));
        if ($token === '') {
            return null;
        }

        return rtrim((string) config('certificates.verify_url_base'), '/').'/'.$token;
    }

    private function holderName(Qualification $qualification): string
    {
        $holder = trim((string) ($qualification->qualification_holder_name ?? ''));
        if ($holder !== '') {
            return $holder;
        }

        $documentName = trim((string) ($qualification->names_as_on_qualification_document ?? ''));

        return $documentName !== '' ? $documentName : '—';
    }

    private function awardingInstitutionName(Qualification $qualification): string
    {
        $name = $qualification->awardingInstitution?->name
            ?? $qualification->awarding_institution_name_other
            ?? $qualification->awarding_institution_name;

        $trimmed = trim((string) $name);

        return $trimmed !== '' ? $trimmed : '—';
    }

    private function countryName(Qualification $qualification): string
    {
        $name = $qualification->country?->name ?? $qualification->country_name_other;
        $trimmed = trim((string) $name);

        return $trimmed !== '' ? $trimmed : '—';
    }

    /**
     * @return array<string, mixed>
     */
    private function notFoundPayload(string $searchedBy): array
    {
        return [
            'found' => false,
            'searched_by' => $searchedBy,
            'application_reference' => null,
            'qualification_reference' => null,
            'status' => VerificationLookupStatusMapper::STATUS_NOT_FOUND,
            'status_label' => 'Not Found',
            'message' => 'No ZAQA verification record was found for the supplied reference.',
            'tone' => 'neutral',
            'qualifications' => [],
            'qualification' => null,
            'certificate' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apiPayload(array $lookup): array
    {
        if (! ($lookup['found'] ?? false)) {
            return [
                'found' => false,
                'message' => (string) ($lookup['message'] ?? 'No ZAQA verification record was found for the supplied reference.'),
            ];
        }

        $payload = [
            'found' => true,
            'searched_by' => $lookup['searched_by'] ?? null,
            'application_reference' => $lookup['application_reference'] ?? null,
            'qualification_reference' => $lookup['qualification_reference'] ?? null,
            'status' => $lookup['status'] ?? null,
            'status_label' => $lookup['status_label'] ?? null,
            'message' => $lookup['message'] ?? null,
        ];

        if (isset($lookup['qualification']) && is_array($lookup['qualification'])) {
            $payload['qualification'] = $lookup['qualification'];
        }

        if (isset($lookup['certificate']) && is_array($lookup['certificate'])) {
            $payload['certificate'] = $lookup['certificate'];
        }

        if (isset($lookup['qualifications']) && is_array($lookup['qualifications']) && count($lookup['qualifications']) > 1) {
            $payload['qualifications'] = collect($lookup['qualifications'])
                ->map(fn (array $row) => [
                    'qualification_reference' => $row['qualification_reference'] ?? null,
                    'application_reference' => $row['application_reference'] ?? null,
                    'holder_name' => $row['holder_name'] ?? null,
                    'qualification_title' => $row['qualification_title'] ?? null,
                    'awarding_institution' => $row['awarding_institution'] ?? null,
                    'status' => $row['status'] ?? null,
                    'status_label' => $row['status_label'] ?? null,
                    'certificate_number' => $row['certificate_number'] ?? null,
                    'public_verification_url' => $row['public_verification_url'] ?? null,
                ])
                ->values()
                ->all();
        }

        return $payload;
    }
}
