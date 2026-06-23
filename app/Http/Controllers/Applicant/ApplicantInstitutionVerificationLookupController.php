<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Audit\AuditLogService;
use App\Domain\Verification\VerificationReferenceLookupService;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerificationReferenceLookupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApplicantInstitutionVerificationLookupController extends Controller
{
    public function show(Request $request): Response
    {
        $this->assertInstitutionApplicant($request);

        return Inertia::render('Applicant/Institution/VerificationLookup', [
            'filters' => [
                'reference_type' => (string) $request->old('reference_type', 'application_reference'),
                'reference' => (string) $request->old('reference', ''),
            ],
            'result' => session('lookup_result'),
        ]);
    }

    public function search(
        VerificationReferenceLookupRequest $request,
        VerificationReferenceLookupService $lookup,
        AuditLogService $audit,
    ): RedirectResponse {
        $this->assertInstitutionApplicant($request);

        [$applicationReference, $qualificationReference, $certificateReference] = $request->lookupInputs();

        $result = $lookup->lookup($applicationReference, $qualificationReference, null, $certificateReference);

        $audit->record(
            eventType: 'institution_verification_lookup.performed',
            module: 'Verification',
            actionName: 'institution_verification_lookup',
            message: 'Institution applicant performed a verification reference lookup.',
            entityType: null,
            entityId: null,
            metadata: [
                'searched_by' => $result['searched_by'] ?? null,
                'found' => (bool) ($result['found'] ?? false),
                'reference_type' => $request->usesUnifiedReferenceInput()
                    ? (string) $request->input('reference_type')
                    : null,
                'application_reference' => $applicationReference !== '' ? $applicationReference : null,
                'qualification_reference' => $qualificationReference !== '' ? $qualificationReference : null,
                'certificate_reference' => $certificateReference !== '' ? $certificateReference : null,
                'status' => $result['status'] ?? null,
            ],
            actor: $request->user(),
        );

        return redirect()
            ->route('applicant.institution.verification_lookup')
            ->with('lookup_result', $result)
            ->withInput();
    }

    private function assertInstitutionApplicant(Request $request): void
    {
        $user = $request->user();
        if (! $user || ($user->applicant_type?->value ?? (string) $user->applicant_type) !== 'institution') {
            abort(403, 'Verification lookup is available to institution accounts only.');
        }
    }
}
