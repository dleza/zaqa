<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Certificates\QualificationCertificateService;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplicantQualificationCertificateController extends Controller
{
    public function download(
        Request $request,
        Application $application,
        Qualification $qualification,
        QualificationCertificateService $certificates,
    ): Response {
        $this->authorize('view', $application);

        abort_unless((int) $qualification->application_id === (int) $application->id, 404);

        $record = QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->where('status', QualificationCertificate::STATUS_ISSUED)
            ->orderByDesc('id')
            ->firstOrFail();

        return response($certificates->pdfContents($record))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="ZAQA-'.$record->certificate_number.'.pdf"');
    }
}
