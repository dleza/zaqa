<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Models\AwardingInstitution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApplicantAwardingInstitutionConsentFormController extends Controller
{
    public function download(Request $request, AwardingInstitution $awardingInstitution): BinaryFileResponse
    {
        if (! $awardingInstitution->consent_form_path) {
            abort(404);
        }

        $disk = config('filesystems.default', 'local');
        $path = $awardingInstitution->consent_form_path;

        $filename = basename($path);
        $absolutePath = Storage::disk($disk)->path($path);

        return response()->download($absolutePath, $filename);
    }
}

