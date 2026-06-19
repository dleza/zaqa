<?php

namespace App\Domain\Applications;

use App\Domain\Documents\QualificationDocumentEvidence;
use App\Enums\ConsentType;
use App\Enums\DocumentType;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\User;
use App\Support\Applications\ApplicationSubmissionMode;
use App\Support\CountryIso;
use Illuminate\Validation\ValidationException;

/**
 * Validates whether an application is complete enough to safely allow payment initiation.
 *
 * Payment confirmation now triggers automatic submission + locking, so we must prevent applicants
 * from paying while required information/documents are still missing.
 */
class ApplicationSubmissionReadinessService
{
    public function assertReadyForPayment(Application $application, User $actor): void
    {
        $application->refresh();
        $application->loadMissing([
            'applicant.applicantProfile',
            'qualifications.consentForm',
            'qualifications.awardingInstitution.country',
            'qualifications.country',
            'documents',
        ]);

        if ($application->qualifications->count() < 1) {
            throw ValidationException::withMessages([
                'qualification' => 'Qualification details are required before payment.',
            ]);
        }

        $missingHolderName = false;
        $missingHolderId = false;
        foreach ($application->qualifications as $q) {
            /** @var Qualification $q */
            $hn = trim((string) ($q->qualification_holder_name ?? ''));
            $hid = trim((string) ($q->nrc_passport_number ?? ''));
            $missingHolderName = $missingHolderName || $hn === '';
            $missingHolderId = $missingHolderId || $hid === '';
        }

        if ($missingHolderName || $missingHolderId) {
            throw ValidationException::withMessages([
                'qualification_holder_name' => 'Qualification holder name is required before payment.',
                'nrc_passport_number' => 'Qualification holder NRC/Passport number is required before payment.',
            ]);
        }

        $missingDocuments = $this->missingDocumentTypes($application);
        if ($missingDocuments !== []) {
            throw ValidationException::withMessages([
                'documents' => 'Missing required documents: '.implode(', ', $missingDocuments).'.',
            ]);
        }

        $this->assertConsentSatisfied($application);

        $this->assertWizardDeclarationsComplete($application);
    }

    /**
     * @return array<int, string>
     */
    private function missingDocumentTypes(Application $application): array
    {
        if (ApplicationSubmissionMode::isInstitutionalMultiple($application)) {
            return $this->missingDocumentTypesForInstitutionalMultiple($application);
        }

        $currentDocsByType = $application->documents
            ->filter(fn (QualificationDocument $doc) => QualificationDocumentEvidence::isActiveEvidence($doc))
            ->groupBy(fn (QualificationDocument $doc) => $doc->document_type?->value ?? (string) $doc->document_type);

        $missing = [];

        $hasIdentity = $currentDocsByType->has(DocumentType::NrcCopy->value)
            || $currentDocsByType->has(DocumentType::PassportCopy->value);

        if (! $hasIdentity) {
            $meta = (array) ($application->metadata ?? []);
            $submittingFor = (string) ($meta['submitting_for'] ?? 'self');
            if ($submittingFor === 'self') {
                $path = $application->applicant?->applicantProfile?->identity_document_path;
                if (is_string($path) && trim($path) !== '') {
                    $hasIdentity = true;
                }
            }
        }

        if (! $hasIdentity) {
            $missing[] = 'nrc_copy or passport_copy';
        }

        // Qualification-level documents must be present per qualification item.
        foreach ($application->qualifications as $q) {
            /** @var Qualification $q */
            $hasCertificate = $application->documents
                ->filter(fn (QualificationDocument $doc) => QualificationDocumentEvidence::isActiveEvidence($doc))
                ->contains(fn (QualificationDocument $doc) => (int) ($doc->qualification_id ?? 0) === (int) $q->id
                    && ($doc->document_type?->value ?? (string) $doc->document_type) === DocumentType::CertificateCopy->value);
            if (! $hasCertificate) {
                $missing[] = 'certificate_copy (qualification_id='.$q->id.')';
            }
        }

        return $missing;
    }

    /**
     * @return array<int, string>
     */
    private function missingDocumentTypesForInstitutionalMultiple(Application $application): array
    {
        $missing = [];

        foreach ($application->qualifications as $q) {
            /** @var Qualification $q */
            $label = trim((string) ($q->title_of_qualification ?? '')) ?: 'qualification #'.$q->id;

            $hasCertificate = $application->documents
                ->filter(fn (QualificationDocument $doc) => QualificationDocumentEvidence::isActiveEvidence($doc))
                ->contains(fn (QualificationDocument $doc) => (int) ($doc->qualification_id ?? 0) === (int) $q->id
                    && ($doc->document_type?->value ?? (string) $doc->document_type) === DocumentType::CertificateCopy->value);

            if (! $hasCertificate) {
                $missing[] = "certificate_copy for {$label}";
            }

            $hasIdentity = $application->documents
                ->filter(fn (QualificationDocument $doc) => QualificationDocumentEvidence::isActiveEvidence($doc))
                ->contains(fn (QualificationDocument $doc) => (int) ($doc->qualification_id ?? 0) === (int) $q->id
                    && in_array($doc->document_type?->value ?? (string) $doc->document_type, [
                        DocumentType::NrcCopy->value,
                        DocumentType::PassportCopy->value,
                    ], true));
            if (! $hasIdentity) {
                $missing[] = "nrc_copy or passport_copy for {$label}";
            }
        }

        return $missing;
    }

    private function assertConsentSatisfied(Application $application): void
    {
        foreach ($application->qualifications as $q) {
            /** @var Qualification $q */
            $consent = $q->consentForm;
            $instIso = strtoupper((string) (($q->awardingInstitution?->country?->iso_code) ?: ($q->country?->iso_code) ?: ''));
            $institutionIsForeign = $instIso !== '' && ! CountryIso::isZambia($instIso);

            if ($institutionIsForeign) {
                if (! $consent || ! $consent->uploaded_document_id) {
                    throw ValidationException::withMessages([
                        'consent' => 'Each foreign qualification requires a signed consent upload before payment.',
                    ]);
                }
                if ($consent->consent_type !== ConsentType::ForeignUploaded) {
                    throw ValidationException::withMessages([
                        'consent' => 'Foreign consent form is not recorded correctly. Please re-upload the consent form.',
                    ]);
                }
            }
        }
    }

    private function assertWizardDeclarationsComplete(Application $application): void
    {
        $meta = (array) ($application->metadata ?? []);
        $wd = $meta['wizard_declarations'] ?? null;
        if (! is_array($wd)) {
            throw ValidationException::withMessages([
                'declarations' => 'Please complete the Declarations step (terms and accuracy confirmation) before payment.',
            ]);
        }
        $terms = $wd['terms_accepted_at'] ?? null;
        $confirmed = $wd['information_confirmed_at'] ?? null;
        if (! is_string($terms) || trim($terms) === '' || ! is_string($confirmed) || trim($confirmed) === '') {
            throw ValidationException::withMessages([
                'declarations' => 'Please complete the Declarations step (terms and accuracy confirmation) before payment.',
            ]);
        }
    }
}

