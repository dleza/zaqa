<?php

namespace App\Domain\Certificates;

use App\Domain\Applications\ApplicationOutcomeNotificationDispatcher;
use App\Domain\Audit\AuditLogService;
use App\Domain\Notifications\OutboundMailService;
use App\Domain\Notifications\OutboundSmsService;
use App\Domain\Payments\ApplicationPaymentSatisfaction;
use App\Domain\Settings\DocumentSignatureService;
use App\Domain\Verification\VerifiedQualificationIngestionService;
use App\Enums\DocumentSignatureType;
use App\Enums\VerificationState;
use App\Mail\QualificationCertificateIssuedMail;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\QualificationSubjectResult;
use App\Models\QualificationType;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QualificationCertificateService
{
    private const TEMPLATE_VERSION = 1;

    /**
     * @var array<string, string>
     */
    private const TEMPLATE_VIEWS = [
        QualificationType::CERTIFICATE_TEMPLATE_DEFAULT => 'pdf.qualification-certificate',
        QualificationType::CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS => 'pdf.qualification-certificate-subjects',
    ];

    public function __construct(
        private readonly ApplicationPaymentSatisfaction $payments,
        private readonly AuditLogService $audit,
        private readonly OutboundMailService $outboundMail,
        private readonly OutboundSmsService $outboundSms,
        private readonly ApplicationOutcomeNotificationDispatcher $outcomeNotifications,
        private readonly VerifiedQualificationIngestionService $verifiedIngestion,
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
            'learnerRecord',
            'subjectResults',
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
            $issuedAt = now();

            $year = $issuedAt->year;
            $relativePath = "qualification-certificates/{$year}/{$qualification->id}/{$token}.pdf";

            $renderContext = $this->buildRenderContext($qualification, $application, [
                'certificate_number' => $certificateNumber,
                'verification_url' => $verifyUrl,
                'issued_at' => $issuedAt,
            ]);
            $pdfBinary = $this->renderPdf($renderContext['view'], $renderContext['data']);

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
                'issued_at' => $issuedAt,
                'recipient_email' => $recipientEmail,
                'status' => QualificationCertificate::STATUS_ISSUED,
                'metadata' => array_merge([
                    'reissue' => $reissue,
                    'previous_certificate_id' => $existingActive?->id,
                ], $renderContext['metadata']),
            ]);

            $qualification->forceFill([
                'verification_state' => VerificationState::CertificateIssued,
            ])->save();

            if (! $reissue) {
                $this->verifiedIngestion->ingestFromIssuedCertificate($qualification, $record, $issuer);
                $qualification->refresh();
            }

            if ($recipientEmail) {
                $this->outboundMail->queue(
                    mailable: new QualificationCertificateIssuedMail(
                        qualification: $qualification,
                        application: $application,
                        certificate: $record,
                    ),
                    to: $recipientEmail,
                    logContext: [
                        'user_id' => $application->applicant_user_id,
                        'application_id' => $application->id,
                        'email' => $recipientEmail,
                        'subject' => 'ZAQA qualification certificate issued',
                        'template_key' => 'qualification_certificate_issued',
                    ],
                );

                $this->outcomeNotifications->notifyCertificateIssuedCopy(
                    qualification: $qualification,
                    application: $application,
                    certificate: $record,
                );
            }

            $applicant = $application->applicant;
            $phone = trim((string) ($applicant?->phone_primary ?? ''));
            if ($phone !== '') {
                $this->outboundSms->queueTemplate(
                    templateKey: 'certificate_issued',
                    placeholders: [
                        'application_number' => (string) $application->application_number,
                    ],
                    phone: $phone,
                    userId: $application->applicant_user_id,
                    applicationId: $application->id,
                );
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

        if ($application->verification_state === VerificationState::Rejected || $application->verification_state === VerificationState::Closed) {
            throw ValidationException::withMessages([
                'application' => 'Certificates cannot be issued for rejected or closed applications.',
            ]);
        }

        if ($this->resolveTemplateKey($qualification) === QualificationType::CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS) {
            $this->assertSubjectResultsReady($qualification);
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
    public function describeTemplate(Qualification $qualification): array
    {
        $qualification->loadMissing(['qualificationTypeMaster', 'subjectResults']);

        $templateKey = $this->resolveTemplateKey($qualification);
        $subjectRows = $this->orderedSubjectResults($qualification);
        $requiresSubjects = $templateKey === QualificationType::CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS;
        $hasIncompleteSubjectRows = $requiresSubjects && $subjectRows->contains(fn (QualificationSubjectResult $row) => ! $this->subjectRowIsComplete($row));
        $subjectCount = $subjectRows->count();

        return [
            'key' => $templateKey,
            'label' => QualificationType::certificateTemplateLabel($templateKey),
            'requires_subjects' => $requiresSubjects,
            'subject_count' => $subjectCount,
            'missing_subjects' => $requiresSubjects && $subjectCount === 0,
            'has_incomplete_subjects' => $hasIncompleteSubjectRows,
            'warning' => match (true) {
                ! $requiresSubjects => null,
                $subjectCount === 0 => 'This certificate type requires subject results before issuing.',
                $hasIncompleteSubjectRows => 'This certificate type requires complete subject names and grades before issuing.',
                default => null,
            },
        ];
    }

    /**
     * @param  array{certificate_number: string, verification_url: string, issued_at: Carbon}  $issue
     * @return array{
     *   view: string,
     *   data: array<string, mixed>,
     *   metadata: array<string, mixed>
     * }
     */
    private function buildRenderContext(Qualification $qualification, Application $application, array $issue): array
    {
        $type = $qualification->qualificationTypeMaster;
        $templateKey = $this->resolveTemplateKey($qualification);
        $subjectRows = $this->orderedSubjectResults($qualification);
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

        $verifiedTitle = trim((string) ($qualification->verified_qualification_title ?? ''));
        $learnerRecordTitle = trim((string) ($qualification->learnerRecord?->program_of_study ?? ''));
        $manualTitle = trim((string) ($qualification->applicant_entered_qualification_title ?? ''));
        $applicantTitle = trim((string) ($qualification->title_of_qualification ?? ''));

        $qualificationTitle = $verifiedTitle !== '' ? $verifiedTitle : ($learnerRecordTitle !== '' ? $learnerRecordTitle : '');

        if ($qualificationTitle === '') {
            // Safety: auto-verified certificates must not be issued using applicant-entered titles.
            $isAutoVerified = in_array((string) ($qualification->verification_source ?? ''), ['internal_learner_record', 'institution_api'], true);
            if ($isAutoVerified) {
                throw ValidationException::withMessages([
                    'qualification' => 'Cannot issue an auto-verified certificate without a verified qualification title.',
                ]);
            }

            $qualificationTitle = $manualTitle !== '' ? $manualTitle : $applicantTitle;
        }

        $logoDataUri = $this->imageDataUriFromPath(resource_path('images/zaqa_logo_clean.png'));
        $watermarkEnabled = (bool) config('certificates.watermark_enabled', true);
        $coatOfArmsWatermarkDataUri = $watermarkEnabled ? $this->buildCoatOfArmsWatermarkDataUri($qualification) : null;
        $watermarkAssetPresent = $coatOfArmsWatermarkDataUri !== null;

        $qrDataUri = $this->buildQrDataUri($issue['verification_url']);

        $issuedAt = $issue['issued_at']->timezone(config('app.timezone'));
        $issuedForFooter = $issuedAt->format('h:i A').' ('.$issuedAt->getTimezone()->getName().') on '.$issuedAt->format('d M Y');

        $viewData = [
            'qualification' => $qualification,
            'application' => $application,
            'certificate_number' => $issue['certificate_number'],
            'verification_url' => $issue['verification_url'],
            'issued_at' => $issue['issued_at'],
            'holder_name' => $holderName !== '' ? $holderName : '—',
            'holder_id' => trim((string) ($qualification->nrc_passport_number ?? '')) ?: '—',
            'zaqa_reference' => (string) ($qualification->verification_reference_number ?? ''),
            'qualification_title' => $qualificationTitle !== '' ? $qualificationTitle : '—',
            'recognised_zambian_qualification' => $recognisedName,
            'awarding_institution' => $institutionName !== '' ? $institutionName : '—',
            'award_date' => optional($qualification->award_date)?->format('d/m/Y') ?? '—',
            'framework_line' => $frameworkLine,
            'recognition_statement' => config('certificates.recognition_act_clause'),
            'director_name' => config('certificates.director_general_name'),
            'director_title' => config('certificates.director_general_title'),
            'signature_data_uri' => app(DocumentSignatureService::class)->dataUriForType(DocumentSignatureType::Certificate),
            'logo_data_uri' => $logoDataUri,
            'coat_of_arms_watermark_data_uri' => $coatOfArmsWatermarkDataUri,
            'qr_data_uri' => $qrDataUri,
            'issued_for_footer' => $issuedForFooter,
            'award_year' => optional($qualification->award_date)?->format('Y'),
            'subject_results' => $subjectRows
                ->map(fn (QualificationSubjectResult $row, int $index) => [
                    'index' => $index + 1,
                    'subject_name' => trim((string) $row->subject_name),
                    'grade' => trim((string) $row->grade),
                ])
                ->values()
                ->all(),
            'app_url' => config('app.url'),
        ];

        return [
            'view' => self::TEMPLATE_VIEWS[$templateKey] ?? self::TEMPLATE_VIEWS[QualificationType::CERTIFICATE_TEMPLATE_DEFAULT],
            'data' => $viewData,
            'metadata' => [
                'template_key' => $templateKey,
                'template_version' => self::TEMPLATE_VERSION,
                'watermark_enabled' => $watermarkEnabled,
                'watermark_asset_present' => $watermarkAssetPresent,
                'verification_base_url' => config('certificates.verify_url_base'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderPdf(string $view, array $data): string
    {
        $pdf = Pdf::loadView($view, $data);
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

    private function resolveTemplateKey(Qualification $qualification): string
    {
        return QualificationType::resolveCertificateTemplateKey(
            $qualification->qualificationTypeMaster,
            $qualification->qualification_type,
        );
    }

    /**
     * @return Collection<int, QualificationSubjectResult>
     */
    private function orderedSubjectResults(Qualification $qualification): Collection
    {
        if ($qualification->relationLoaded('subjectResults')) {
            /** @var Collection<int, QualificationSubjectResult> $rows */
            $rows = $qualification->subjectResults;

            return $rows
                ->sortBy(fn (QualificationSubjectResult $row) => [$row->display_order ?? PHP_INT_MAX, $row->id])
                ->values();
        }

        /** @var Collection<int, QualificationSubjectResult> $rows */
        $rows = $qualification->subjectResults()
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return $rows;
    }

    private function assertSubjectResultsReady(Qualification $qualification): void
    {
        $subjectRows = $this->orderedSubjectResults($qualification);
        if ($subjectRows->isEmpty()) {
            throw ValidationException::withMessages([
                'qualification' => 'This certificate type requires subject results before issuing.',
            ]);
        }

        if ($subjectRows->contains(fn (QualificationSubjectResult $row) => ! $this->subjectRowIsComplete($row))) {
            throw ValidationException::withMessages([
                'qualification' => 'This certificate type requires subject results before issuing.',
            ]);
        }
    }

    private function subjectRowIsComplete(QualificationSubjectResult $row): bool
    {
        return trim((string) $row->subject_name) !== '' && trim((string) $row->grade) !== '';
    }

    private function buildCoatOfArmsWatermarkDataUri(Qualification $qualification): ?string
    {
        $configuredPath = trim((string) config('certificates.coat_of_arms_path', ''));
        if ($configuredPath === '') {
            Log::warning('Certificate watermark asset path is empty; continuing without watermark.', [
                'qualification_id' => $qualification->id,
            ]);

            return null;
        }

        $resolvedPath = $this->resolveConfiguredAssetPath($configuredPath);
        $dataUri = $this->imageDataUriFromPath($resolvedPath);

        if ($dataUri === null) {
            Log::warning('Certificate watermark asset missing; continuing without watermark.', [
                'qualification_id' => $qualification->id,
                'configured_path' => $configuredPath,
                'resolved_path' => $resolvedPath,
            ]);
        }

        return $dataUri;
    }

    private function resolveConfiguredAssetPath(string $configuredPath): string
    {
        return Str::startsWith($configuredPath, ['/', '\\']) ? $configuredPath : base_path($configuredPath);
    }

    private function imageDataUriFromPath(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
