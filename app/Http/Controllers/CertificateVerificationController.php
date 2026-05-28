<?php

namespace App\Http\Controllers;

use App\Models\QualificationCertificate;
use App\Models\QualificationSubjectResult;
use App\Models\QualificationType;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class CertificateVerificationController extends Controller
{
    public function show(string $token)
    {
        $certificate = QualificationCertificate::query()
            ->with([
                'qualification:id,application_id,qualification_holder_name,title_of_qualification,awarding_institution_id,awarding_institution_name,awarding_institution_name_other,verification_reference_number,nrc_passport_number,award_date,qualification_type,qualification_type_id',
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

        $replacement = null;
        if ($certificate->status === QualificationCertificate::STATUS_REISSUED) {
            $replacement = QualificationCertificate::query()
                ->where('qualification_id', $certificate->qualification_id)
                ->where('status', QualificationCertificate::STATUS_ISSUED)
                ->latest('id')
                ->first();
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
                'status_label' => match ($certificate->status) {
                    QualificationCertificate::STATUS_ISSUED => 'Valid certificate',
                    QualificationCertificate::STATUS_REISSUED => 'Superseded certificate',
                    QualificationCertificate::STATUS_REVOKED => 'Revoked certificate',
                    default => 'Certificate record',
                },
                'message' => match ($certificate->status) {
                    QualificationCertificate::STATUS_ISSUED => 'This certificate is valid and was issued by ZAQA.',
                    QualificationCertificate::STATUS_REISSUED => 'This certificate has been superseded by a newer reissued certificate.',
                    QualificationCertificate::STATUS_REVOKED => 'This certificate is no longer valid because it has been revoked.',
                    default => 'Certificate status available.',
                },
                'verified_at' => now()->toIso8601String(),
                'verification_reference' => $certificate->verification_token,
                'certificate' => [
                    'certificate_number' => $certificate->certificate_number,
                    'zaqa_reference_number' => $certificate->zaqa_reference_number,
                    'issued_at' => optional($certificate->issued_at)?->toIso8601String(),
                    'award_date' => optional($qualification?->award_date)?->toIso8601String(),
                    'holder_name' => $qualification?->qualification_holder_name,
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
