<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Applicants\ApplicantIdentityDocumentService;
use App\Domain\Documents\ApplicantDocumentService;
use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\UploadApplicationIdentityDocumentRequest;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class ApplicantApplicationIdentityDocumentController extends Controller
{
    public function store(
        UploadApplicationIdentityDocumentRequest $request,
        Application $application,
        ApplicantDocumentService $documents,
        ApplicantIdentityDocumentService $identityDocuments,
    ): RedirectResponse
    {
        $this->authorize('update', $application);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $file = $request->file('file');
        if (! $file) {
            return back()->withErrors(['file' => 'Please choose a file to upload.']);
        }

        $identityType = strtolower(trim((string) $request->input('identity_type', 'nrc')));
        $meta = (array) ($application->metadata ?? []);
        $submittingFor = (string) ($meta['submitting_for'] ?? 'self');

        if ($submittingFor === 'other') {
            $type = $identityType === 'passport' ? DocumentType::PassportCopy : DocumentType::NrcCopy;
            $documents->upload($application, $type, $file, $user, null);
        } else {
            $identityDocuments->saveProfileIdentityDocument($user, $file, $user);
        }

        return back()->with('success', 'Identity document uploaded.');
    }
}

