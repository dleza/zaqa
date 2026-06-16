<?php

namespace App\Http\Controllers;

use App\Models\QualificationCertificate;
use App\Models\QualificationSubjectResult;
use App\Models\QualificationType;
use Illuminate\Support\Facades\Schema;
use App\Support\Certificates\CertificateHolderName;
use Inertia\Inertia;

class CertificateVerificationController extends Controller
{
    public function show(string $token)
    {
        $certificate = QualificationCertificate::query()
            ->with([
                'qualification:id,application_id,qualification_holder_name,names_as_on_qualification_document,title_of_qualification,awarding_institution_id,awarding_institution_name,awarding_institution_name_other,verification_reference_number,nrc_passport_number,award_date,qualification_type,qualification_type_id',
                'qualification.application:id,applicant_user_id,metadata',
                'qualification.application.applicant:id,name',
                'qualification.awardingInstitution:id,name',
                'qualification.qualificationTypeMaster:'.implode(',', $this->qualificationTypeSelectColumns()),
                'qualification.subjectResults' => fn ($query) => $query
                    ->select('id', 'qualification_id', 'subject_name', 'grade', 'display_order')
                    ->orderBy('display_order')
                    ->orderBy('id'),
            ])
            ->where('verification_token', $token)
            ->first();

        if (! $certificate) {
            return Inertia::render('Certificates/Verify', [
                'verification' => [
                    'found' => false,
                    'status' => 'not_found',
                    'status_label' => 'Certificate not found',
                    'message' => 'We could not find a certificate for this verification code.',
                    'certificate' => null,
                ],
            ])->toResponse(request())->setStatusCode(404);
        }

        $isRejection = $certificate->isRejectionCertificate();

        $replacement = null;
        if ($certificate->status === QualificationCertificate::STATUS_REISSUED) {
            $replacement = QualificationCertificate::query()
                ->where('qualification_id', $certificate->qualification_id)
                ->where('status', QualificationCertificate::STATUS_ISSUED)
                ->latest('id')
                ->first();
        }

        $hasNewerActiveCertificate = false;
        if ($certificate->status === QualificationCertificate::STATUS_REVOKED) {
            $hasNewerActiveCertificate = QualificationCertificate::query()
                ->where('qualification_id', $certificate->qualification_id)
                ->where('status', QualificationCertificate::STATUS_ISSUED)
                ->where('id', '>', $certificate->id)
                ->exists();
        }

        $institutionName = $certificate->qualification?->awardingInstitution?->name
            ?? $certificate->qualification?->awarding_institution_name_other
            ?? $certificate->qualification?->awarding_institution_name;

        $qualification = $certificate->qualification;
        $templateKey = $certificate->metadata['template_key']
            ?? QualificationType::resolveCertificateTemplateKey(
                $qualification?->qualificationTypeMaster,
                $qualification?->qualification_type,
            );

        $subjectResults = $qualification?->subjectResults
            ?->map(fn (QualificationSubjectResult $row, int $index) => [
                'index' => $index + 1,
                'subject_name' => trim((string) $row->subject_name),
                'grade' => trim((string) $row->grade),
            ])
            ->values()
            ->all() ?? [];

        return Inertia::render('Certificates/Verify', [
            'verification' => [
                'found' => true,
                'status' => $certificate->status,
                'certificate_type' => $certificate->certificate_type ?: QualificationCertificate::TYPE_VERIFICATION,
                'status_label' => $this->resolveStatusLabel($certificate, $isRejection),
                'message' => $this->resolveMessage($certificate, $isRejection),
                'revoked_at' => optional($certificate->revoked_at)?->toIso8601String(),
                'revocation_public_note' => $certificate->status === QualificationCertificate::STATUS_REVOKED
                    ? ($certificate->revocation_public_note ?: null)
                    : null,
                'has_newer_active_certificate' => $hasNewerActiveCertificate,
                'verified_at' => now()->toIso8601String(),
                'verification_reference' => $certificate->verification_token,
                'certificate' => [
                    'certificate_number' => $certificate->certificate_number,
                    'zaqa_reference_number' => $certificate->zaqa_reference_number,
                    'issued_at' => optional($certificate->issued_at)?->toIso8601String(),
                    'award_date' => optional($qualification?->award_date)?->toIso8601String(),
                    'holder_name' => CertificateHolderName::displayForPublicVerification(
                        $certificate,
                        $qualification,
                        $qualification?->application,
                    ),
                    'holder_identifier' => $qualification?->nrc_passport_number,
                    'qualification_title' => $qualification?->title_of_qualification,
                    'awarding_institution' => $institutionName,
                    'qualification_type_label' => $qualification?->qualificationTypeMaster?->name,
                    'qualification_level_label' => $qualification?->qualificationTypeMaster?->level_label,
                    'qualification_type_code' => $qualification?->qualificationTypeMaster?->zqf_level_code
                        ?? $qualification?->qualification_type,
                    'template_key' => $templateKey,
                    'subject_count' => count($subjectResults),
                    'subject_results' => $subjectResults,
                    'replacement_certificate_number' => $replacement?->certificate_number,
                ],
            ],
        ]);
    }

    private function resolveStatusLabel(QualificationCertificate $certificate, bool $isRejection): string
    {
        if ($certificate->status === QualificationCertificate::STATUS_REVOKED) {
            return $isRejection ? 'Rejection certificate recalled' : 'Revoked certificate';
        }

        if ($certificate->status === QualificationCertificate::STATUS_REISSUED) {
            return 'Superseded certificate';
        }

        if ($certificate->status === QualificationCertificate::STATUS_ISSUED) {
            return $isRejection ? 'Rejection notice issued' : 'Valid certificate';
        }

        return 'Certificate record';
    }

    private function resolveMessage(QualificationCertificate $certificate, bool $isRejection): string
    {
        if ($certificate->status === QualificationCertificate::STATUS_REVOKED) {
            return $isRejection
                ? 'This rejection certificate has been recalled by the Zambia Qualifications Authority and is no longer valid.'
                : 'This certificate has been recalled by the Zambia Qualifications Authority and is no longer valid.';
        }

        if ($certificate->status === QualificationCertificate::STATUS_REISSUED) {
            return 'This certificate has been superseded by a newer reissued certificate.';
        }

        if ($certificate->status === QualificationCertificate::STATUS_ISSUED) {
            return $isRejection
                ? 'This QR code confirms that ZAQA issued a rejection notice for the qualification shown below.'
                : 'This certificate is valid and was issued by ZAQA.';
        }

        return 'Certificate status available.';
    }

    /**
     * @return array<int, string>
     */
    private function qualificationTypeSelectColumns(): array
    {
        $columns = ['id', 'zqf_level_code', 'level_label', 'name', 'requires_subject_results'];

        if (Schema::hasColumn('qualification_types', 'certificate_template_key')) {
            $columns[] = 'certificate_template_key';
        }

        return $columns;
    }
}
