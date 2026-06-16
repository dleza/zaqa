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
        $canOpenVerificationTask = (bool) $user?->can('verification.pool.view');
        $canRevoke = (bool) $user?->can('certificates.revoke');

        $certificates = QualificationCertificate::query()
            ->with([
                'qualification:id,application_id,title_of_qualification,qualification_holder_name',
                'application:id,application_number',
                'application.applicant:id,name,email',
                'issuedBy:id,name',
                'revokedBy:id,name',
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
            ->through(function (QualificationCertificate $cert) use ($canOpenVerificationTask, $canRevoke) {
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
                    'revoked_at' => optional($cert->revoked_at)?->toIso8601String(),
                    'revoked_by_name' => $cert->revokedBy?->name,
                    'recipient_email' => $cert->recipient_email,
                    'qualification_title' => $cert->qualification?->title_of_qualification,
                    'holder_name' => CertificateHolderName::displayFromCertificateMetadata($cert->metadata)
                        ?? $cert->qualification?->qualification_holder_name,
                    'application_number' => $cert->application?->application_number,
                    'applicant_name' => $cert->application?->applicant?->name,
                    'issued_by_name' => $cert->issuedBy?->name,
                    'download_url' => route('admin.certificates.download', ['qualificationCertificate' => $cert]),
                    'verification_url' => $verificationUrl,
                    'revoke_url' => $canRevoke && $cert->status === QualificationCertificate::STATUS_ISSUED
                        ? route('admin.certificates.revoke', ['qualificationCertificate' => $cert])
                        : null,
                    'verification_task_url' => $canOpenVerificationTask && $qualificationId > 0
                        ? route('admin.verification.qualifications.show', ['qualification' => $qualificationId])
                        : null,
                ];
            });

        return Inertia::render('Admin/Certificates/Index', [
            'certificates' => $certificates,
            'filters' => [
                'q' => $q,
                'status' => $status,
                'type' => $type,
            ],
            'can' => [
                'revoke' => $canRevoke,
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
        return response($certificates->pdfContents($qualificationCertificate))
            ->header('Content-Type', 'application/pdf')
            ->header(
                'Content-Disposition',
                'attachment; filename="ZAQA-'.$qualificationCertificate->certificate_number.'.pdf"',
            );
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
