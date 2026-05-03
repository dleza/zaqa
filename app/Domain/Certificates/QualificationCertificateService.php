<?php

namespace App\Domain\Certificates;

use App\Domain\Audit\AuditLogService;
use App\Domain\Payments\ApplicationPaymentSatisfaction;
use App\Enums\VerificationState;
use App\Mail\QualificationCertificateIssuedMail;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QualificationCertificateService
{
    public function __construct(
        private readonly ApplicationPaymentSatisfaction $payments,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Issue a CVEQ PDF for this qualification, email the applicant, and mark the qualification issued.
     *
     * @return array{certificate: QualificationCertificate, download_path: string}
     */
    public function issue(Qualification $qualification, User $issuer, bool $reissue = false): array
    {
        if ($reissue && ! $issuer->hasRole('Super Admin')) {
            throw ValidationException::withMessages([
                'reissue' => 'Only a Super Admin may reissue a certificate.',
            ]);
        }

        $qualification->loadMissing([
            'application.applicant',
            'application',
            'qualificationTypeMaster',
            'awardingInstitution',
            'country',
        ]);

        $application = $qualification->application;
        if (! $application instanceof Application) {
            throw ValidationException::withMessages(['qualification' => 'Application not found for this qualification.']);
        }

        $existingActive = QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->where('status', QualificationCertificate::STATUS_ISSUED)
            ->first();

        if ($existingActive && ! $reissue) {
            throw ValidationException::withMessages([
                'qualification' => 'A certificate has already been issued for this qualification.',
            ]);
        }

        $this->assertEligible($qualification, $application, $reissue);

        return DB::transaction(function () use ($qualification, $application, $issuer, $reissue, $existingActive) {
            if ($existingActive && $reissue) {
                $existingActive->forceFill([
                    'status' => QualificationCertificate::STATUS_REISSUED,
                    'metadata' => array_merge($existingActive->metadata ?? [], [
                        'superseded_at' => now()->toIso8601String(),
                        'superseded_by_user_id' => $issuer->id,
                    ]),
                ])->save();
            }

            $certificateNumber = $this->allocateCertificateNumber();
            $token = $this->allocateVerificationToken();
            $verifyUrl = config('certificates.verify_url_base').'/'.$token;

            $year = now()->year;
            $relativePath = "qualification-certificates/{$year}/{$qualification->id}/{$token}.pdf";

            $pdfBinary = $this->renderPdf($qualification, $application, [
                'certificate_number' => $certificateNumber,
                'verification_url' => $verifyUrl,
                'issued_at' => now(),
            ]);

            Storage::disk('local')->put($relativePath, $pdfBinary);

            $applicant = $application->applicant;
            $recipientEmail = $applicant?->email;

            $record = QualificationCertificate::query()->create([
                'qualification_id' => $qualification->id,
                'application_id' => $application->id,
                'certificate_number' => $certificateNumber,
                'zaqa_reference_number' => $qualification->verification_reference_number,
                'verification_token' => $token,
                'file_path' => $relativePath,
                'issued_by_user_id' => $issuer->id,
                'issued_at' => now(),
                'recipient_email' => $recipientEmail,
                'status' => QualificationCertificate::STATUS_ISSUED,
                'metadata' => [
                    'reissue' => $reissue,
                    'previous_certificate_id' => $existingActive?->id,
                ],
            ]);

            $qualification->forceFill([
                'verification_state' => VerificationState::CertificateIssued,
            ])->save();

            if ($recipientEmail) {
                Mail::to($recipientEmail)->send(new QualificationCertificateIssuedMail(
                    qualification: $qualification,
                    application: $application,
                    certificate: $record,
                ));
            }

            $this->audit->record(
                eventType: 'certificates.qualification_issued',
                module: 'Certificates',
                actionName: 'qualification_certificate_issued',
                message: 'Qualification certificate (CVEQ) issued.',
                entityType: QualificationCertificate::class,
                entityId: $record->id,
                beforeState: null,
                afterState: $record->toArray(),
                metadata: [
                    'qualification_id' => $qualification->id,
                    'application_id' => $application->id,
                    'certificate_number' => $certificateNumber,
                    'reissue' => $reissue,
                ],
                actor: $issuer,
            );

            return [
                'certificate' => $record,
                'download_path' => $relativePath,
            ];
        });
    }

    public function assertEligible(Qualification $qualification, Application $application, bool $reissue = false): void
    {
        if (! $this->payments->isSatisfied($application)) {
            throw ValidationException::withMessages([
                'payment' => 'Application payment must be fully satisfied before issuing a certificate.',
            ]);
        }

        if ($application->verification_state !== VerificationState::ApprovedForCertificate) {
            throw ValidationException::withMessages([
                'application' => 'The application must be approved for certificate before issuing.',
            ]);
        }

        $vs = $qualification->verification_state;
        if ($reissue) {
            if ($vs !== VerificationState::CertificateIssued) {
                throw ValidationException::withMessages([
                    'qualification' => 'Reissue is only available after a certificate has been issued for this qualification.',
                ]);
            }

            return;
        }

        if ($vs !== VerificationState::ApprovedForCertificate) {
            throw ValidationException::withMessages([
                'qualification' => 'This qualification must be approved for certificate before a CVEQ can be issued.',
            ]);
        }
    }

    /**
     * Stream PDF for applicant download (authorization checked by caller).
     */
    public function pdfContents(QualificationCertificate $certificate): string
    {
        $path = $certificate->file_path;
        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'Certificate file not found.');
        }

        return Storage::disk('local')->get($path);
    }

    private function allocateCertificateNumber(): string
    {
        $year = now()->year;
        $prefix = sprintf('ZAQA-CVEQ-%d-', $year);

        $last = QualificationCertificate::query()
            ->where('certificate_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('certificate_number')
            ->value('certificate_number');

        $next = 1;
        if (is_string($last) && preg_match('/-(\d{6})$/', $last, $m)) {
            $next = (int) $m[1] + 1;
        }

        return sprintf('%s%06d', $prefix, $next);
    }

    private function allocateVerificationToken(): string
    {
        do {
            $token = Str::random(48);
        } while (QualificationCertificate::query()->where('verification_token', $token)->exists());

        return $token;
    }

    /**
     * @param  array{certificate_number: string, verification_url: string, issued_at: Carbon}  $issue
     */
    private function renderPdf(Qualification $qualification, Application $application, array $issue): string
    {
        $type = $qualification->qualificationTypeMaster;
        $frameworkLine = $type
            ? 'At '.trim((string) $type->level_label).' of the Zambia Qualifications Framework.'
            : 'As recognised under the Zambia Qualifications Framework.';

        $holderName = trim((string) ($qualification->qualification_holder_name ?? ''));
        if ($holderName === '') {
            $meta = $application->metadata;
            if (is_array($meta) && isset($meta['verification_subject']['full_name'])) {
                $holderName = trim((string) $meta['verification_subject']['full_name']);
            }
        }

        $institutionName = $qualification->awardingInstitution?->name
            ?? (string) ($qualification->awarding_institution_name_other ?: $qualification->awarding_institution_name);

        $recognisedName = $type?->name ?? (string) ($qualification->title_of_qualification ?? '');

        $logoPath = resource_path('images/zaqa-logo-tranparent.png');
        $logoDataUri = null;
        if (is_file($logoPath)) {
            $logoDataUri = 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath));
        }

        $qrDataUri = $this->buildQrDataUri($issue['verification_url']);

        $issuedForFooter = $issue['issued_at']->timezone(config('app.timezone'))->format('h:i A').' ('.$issue['issued_at']->timezone(config('app.timezone'))->getTimezone()->getName().') on '.$issue['issued_at']->format('d M Y');

        $pdf = Pdf::loadView('pdf.qualification-certificate', [
            'qualification' => $qualification,
            'application' => $application,
            'certificate_number' => $issue['certificate_number'],
            'verification_url' => $issue['verification_url'],
            'issued_at' => $issue['issued_at'],
            'holder_name' => $holderName !== '' ? $holderName : '—',
            'holder_id' => trim((string) ($qualification->nrc_passport_number ?? '')) ?: '—',
            'zaqa_reference' => (string) ($qualification->verification_reference_number ?? ''),
            'qualification_title' => (string) ($qualification->title_of_qualification ?? ''),
            'recognised_zambian_qualification' => $recognisedName,
            'awarding_institution' => $institutionName !== '' ? $institutionName : '—',
            'award_date' => optional($qualification->award_date)?->format('d/m/Y') ?? '—',
            'framework_line' => $frameworkLine,
            'recognition_statement' => config('certificates.recognition_act_clause'),
            'director_name' => config('certificates.director_general_name'),
            'director_title' => config('certificates.director_general_title'),
            'logo_data_uri' => $logoDataUri,
            'qr_data_uri' => $qrDataUri,
            'issued_for_footer' => $issuedForFooter,
            'app_url' => config('app.url'),
        ]);

        $pdf->setPaper('A4', 'portrait');

        return $pdf->output();
    }

    private function buildQrDataUri(string $url): string
    {
        $result = Builder::create()
            ->writer(new PngWriter)
            ->data($url)
            ->size(140)
            ->margin(8)
            ->build();

        return $result->getDataUri();
    }
}
