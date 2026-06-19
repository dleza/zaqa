<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Certificates\QualificationCertificateBulkIssueExcelService;
use App\Domain\Certificates\QualificationCertificateRevocationService;
use App\Domain\Certificates\QualificationCertificateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportCertificateBulkIssueExcelRequest;
use App\Http\Requests\Admin\RevokeQualificationCertificateRequest;
use App\Models\QualificationCertificate;
use App\Support\Certificates\CertificateHolderName;
use App\Support\Imports\ExcelTemplateDownload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminCertificatesController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $type = trim((string) $request->query('type', ''));

        $allowedStatuses = [
            QualificationCertificate::STATUS_ISSUED,
            QualificationCertificate::STATUS_REISSUED,
            QualificationCertificate::STATUS_REVOKED,
        ];

        $allowedTypes = [
            QualificationCertificate::TYPE_VERIFICATION,
            QualificationCertificate::TYPE_REJECTION,
        ];

        $user = $request->user();

        $certificates = QualificationCertificate::query()
            ->with([
                'qualification:id,title_of_qualification,qualification_holder_name',
            ])
            ->when($status !== '' && in_array($status, $allowedStatuses, true), fn ($query) => $query->where('status', $status))
            ->when($type !== '' && in_array($type, $allowedTypes, true), fn ($query) => $query->where('certificate_type', $type))
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like) {
                    $inner
                        ->where('certificate_number', 'like', $like)
                        ->orWhere('zaqa_reference_number', 'like', $like)
                        ->orWhereHas('application', fn ($a) => $a->where('application_number', 'like', $like))
                        ->orWhereHas(
                            'qualification',
                            fn ($qual) => $qual
                                ->where('title_of_qualification', 'like', $like)
                                ->orWhere('qualification_holder_name', 'like', $like),
                        );
                });
            })
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (QualificationCertificate $cert) => $this->mapRegistryRow($cert));

        return Inertia::render('Admin/Certificates/Index', [
            'certificates' => $certificates,
            'filters' => [
                'q' => $q,
                'status' => $status,
                'type' => $type,
            ],
            'status_options' => [
                ['value' => '', 'label' => 'All statuses'],
                ['value' => QualificationCertificate::STATUS_ISSUED, 'label' => 'Active / Issued'],
                ['value' => QualificationCertificate::STATUS_REISSUED, 'label' => 'Reissued / Superseded'],
                ['value' => QualificationCertificate::STATUS_REVOKED, 'label' => 'Revoked'],
            ],
            'type_options' => [
                ['value' => '', 'label' => 'All types'],
                ['value' => QualificationCertificate::TYPE_VERIFICATION, 'label' => 'Verification'],
                ['value' => QualificationCertificate::TYPE_REJECTION, 'label' => 'Rejection'],
            ],
            'excel_import' => [
                'template_url' => route('admin.certificates.bulk_issue_template'),
                'import_url' => route('admin.certificates.bulk_issue_import'),
                'can_import' => (bool) $user?->can('verification.certificate.issue'),
            ],
        ]);
    }

    public function show(Request $request, QualificationCertificate $qualificationCertificate): Response
    {
        abort_unless($request->user()?->can('admin.certificates.view'), 403);

        $qualificationCertificate->load([
            'qualification:id,application_id,title_of_qualification,qualification_holder_name,verification_reference_number',
            'application:id,application_number',
            'issuedBy:id,name',
            'revokedBy:id,name',
        ]);

        $user = $request->user();
        $canRevoke = (bool) $user?->can('certificates.revoke');
        $canOpenVerificationTask = (bool) $user?->can('verification.pool.view');
        $qualificationId = (int) $qualificationCertificate->qualification_id;

        return Inertia::render('Admin/Certificates/Show', [
            'certificate' => $this->mapCertificateDetail($qualificationCertificate),
            'preview_document' => $this->mapPreviewDocument($qualificationCertificate),
            'can' => [
                'revoke' => $canRevoke,
                'open_verification_task' => $canOpenVerificationTask && $qualificationId > 0,
            ],
        ]);
    }

    public function preview(
        Request $request,
        QualificationCertificate $qualificationCertificate,
        QualificationCertificateService $certificates,
    ): SymfonyResponse {
        abort_unless($request->user()?->can('admin.certificates.view'), 403);

        return response($certificates->pdfContents($qualificationCertificate))
            ->header('Content-Type', 'application/pdf')
            ->header(
                'Content-Disposition',
                'inline; filename="ZAQA-'.$qualificationCertificate->certificate_number.'.pdf"',
            );
    }

    public function bulkIssueTemplate(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('admin.certificates.view'), 403);

        return ExcelTemplateDownload::stream(
            'cveq-bulk-issue-template.xlsx',
            ['qualification_id'],
            [],
        );
    }

    public function bulkIssueImport(
        ImportCertificateBulkIssueExcelRequest $request,
        QualificationCertificateBulkIssueExcelService $bulk,
    ): RedirectResponse {
        $report = $bulk->import($request->file('file'), $request->user());

        $msg = $report->created > 0
            ? "Bulk issue: {$report->created} CVEQ certificate(s) issued."
            : 'No CVEQ certificates were issued.';
        if ($report->errors !== []) {
            $msg .= ' '.count($report->errors).' row message(s).';
        }

        return back()
            ->with('success', $msg)
            ->with('import_report', ['errors' => $report->errors]);
    }

    public function revoke(
        RevokeQualificationCertificateRequest $request,
        QualificationCertificate $qualificationCertificate,
        QualificationCertificateRevocationService $revocation,
    ): RedirectResponse {
        $revocation->revoke(
            $qualificationCertificate,
            $request->user(),
            (string) $request->input('revocation_reason'),
            $request->input('revocation_public_note'),
        );

        return back()->with('success', 'Certificate revoked successfully.');
    }

    public function download(
        Request $request,
        QualificationCertificate $qualificationCertificate,
        QualificationCertificateService $certificates,
    ): SymfonyResponse {
        abort_unless($request->user()?->can('admin.certificates.view'), 403);

        return response($certificates->pdfContents($qualificationCertificate))
            ->header('Content-Type', 'application/pdf')
            ->header(
                'Content-Disposition',
                'attachment; filename="ZAQA-'.$qualificationCertificate->certificate_number.'.pdf"',
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRegistryRow(QualificationCertificate $cert): array
    {
        return [
            'id' => $cert->id,
            'certificate_number' => $cert->certificate_number,
            'status' => $cert->status,
            'status_label' => $this->statusLabel($cert->status),
            'certificate_type_label' => $this->typeLabel($cert->certificate_type),
            'issued_at' => optional($cert->issued_at)?->toIso8601String(),
            'qualification_title' => $cert->qualification?->title_of_qualification,
            'holder_name' => CertificateHolderName::displayFromCertificateMetadata($cert->metadata)
                ?? $cert->qualification?->qualification_holder_name,
            'show_url' => route('admin.certificates.show', ['qualificationCertificate' => $cert]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCertificateDetail(QualificationCertificate $cert): array
    {
        $qualificationId = (int) $cert->qualification_id;
        $verificationUrl = rtrim((string) config('certificates.verify_url_base'), '/').'/'.$cert->verification_token;

        return [
            'id' => $cert->id,
            'certificate_number' => $cert->certificate_number,
            'zaqa_reference_number' => $cert->zaqa_reference_number,
            'status' => $cert->status,
            'status_label' => $this->statusLabel($cert->status),
            'certificate_type' => $cert->certificate_type ?: QualificationCertificate::TYPE_VERIFICATION,
            'certificate_type_label' => $this->typeLabel($cert->certificate_type),
            'issued_at' => optional($cert->issued_at)?->toIso8601String(),
            'issued_by_name' => $cert->issuedBy?->name,
            'qualification_title' => $cert->qualification?->title_of_qualification,
            'qualification_id' => $qualificationId > 0 ? $qualificationId : null,
            'holder_name' => CertificateHolderName::displayFromCertificateMetadata($cert->metadata)
                ?? $cert->qualification?->qualification_holder_name,
            'application_number' => $cert->application?->application_number,
            'revoked_at' => optional($cert->revoked_at)?->toIso8601String(),
            'revoked_by_name' => $cert->revokedBy?->name,
            'revocation_reason' => $cert->revocation_reason,
            'revocation_public_note' => $cert->revocation_public_note,
            'download_url' => route('admin.certificates.download', ['qualificationCertificate' => $cert]),
            'preview_url' => route('admin.certificates.preview', ['qualificationCertificate' => $cert]),
            'verification_url' => $verificationUrl,
            'revoke_url' => $cert->status === QualificationCertificate::STATUS_ISSUED
                ? route('admin.certificates.revoke', ['qualificationCertificate' => $cert])
                : null,
            'verification_task_url' => $qualificationId > 0
                ? route('admin.verification.qualifications.show', ['qualification' => $qualificationId])
                : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function mapPreviewDocument(QualificationCertificate $cert): array
    {
        $filename = 'ZAQA-'.$cert->certificate_number.'.pdf';

        return [
            'label' => $this->typeLabel($cert->certificate_type).' certificate',
            'filename' => $filename,
            'mime_type' => 'application/pdf',
            'preview_url' => route('admin.certificates.preview', ['qualificationCertificate' => $cert]),
            'download_url' => route('admin.certificates.download', ['qualificationCertificate' => $cert]),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            QualificationCertificate::STATUS_ISSUED => 'Active',
            QualificationCertificate::STATUS_REISSUED => 'Superseded',
            QualificationCertificate::STATUS_REVOKED => 'Revoked',
            default => ucfirst($status),
        };
    }

    private function typeLabel(?string $type): string
    {
        return match ($type) {
            QualificationCertificate::TYPE_REJECTION => 'Rejection',
            QualificationCertificate::TYPE_VERIFICATION => 'Verification',
            default => 'Verification',
        };
    }
}
