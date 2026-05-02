<?php

namespace App\Domain\Verification;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Qualification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class QualificationsPoolService
{
    /**
     * Qualification-centric pool query for verification users.
     *
     * Filters:
     * - q: application number / applicant / holder identifiers
     * - qualification_type_id: int
     * - awarding_institution_id: int
     * - country_id: int
     * - foreign: 1|0 (qualification locality)
     * - assigned: 1|0 (qualification assigned_verifier_id)
     * - assigned_verifier_id: int
     * - verification_state: string
     * - payment_status: paid|unpaid
     * - submitted_from/submitted_to: Y-m-d (application submitted_at)
     */
    public function pool(Request $request, ?int $currentUserId = null): LengthAwarePaginator
    {
        $q = trim((string) $request->query('q', ''));
        $qualificationTypeId = $request->query('qualification_type_id');
        $awardingInstitutionId = $request->query('awarding_institution_id');
        $countryId = $request->query('country_id');
        $foreign = $request->query('foreign');
        $assigned = $request->query('assigned');
        $assignedVerifierId = $request->query('assigned_verifier_id');
        $verificationState = trim((string) $request->query('verification_state', ''));
        $paymentStatus = trim((string) $request->query('payment_status', ''));
        $submittedFrom = trim((string) $request->query('submitted_from', ''));
        $submittedTo = trim((string) $request->query('submitted_to', ''));

        $query = Qualification::query()
            ->with([
                'application.applicant',
                'qualificationTypeMaster',
                'awardingInstitution',
                'country',
                'assignedVerifier',
            ])
            ->whereHas('application', function ($aq) use ($submittedFrom, $submittedTo, $paymentStatus) {
                $aq->whereIn('current_status', [
                    ApplicationStatus::Submitted,
                    ApplicationStatus::Resubmitted,
                    ApplicationStatus::InProgress,
                    ApplicationStatus::SentBack,
                ]);

                if ($submittedFrom !== '') {
                    $aq->whereDate('submitted_at', '>=', $submittedFrom);
                }
                if ($submittedTo !== '') {
                    $aq->whereDate('submitted_at', '<=', $submittedTo);
                }

                if ($paymentStatus === 'paid') {
                    $aq->whereNotNull('paid_at');
                } elseif ($paymentStatus === 'unpaid') {
                    $aq->whereNull('paid_at');
                }
            });

        if ($q !== '') {
            $query->where(function ($inner) use ($q) {
                $inner->whereHas('application', function ($aq) use ($q) {
                    $aq->where('application_number', 'like', '%'.$q.'%')
                        ->orWhere('metadata->verification_subject->full_name', 'like', '%'.$q.'%')
                        ->orWhere('metadata->verification_subject->nrc_number', 'like', '%'.$q.'%')
                        ->orWhere('metadata->verification_subject->passport_number', 'like', '%'.$q.'%');
                })
                    ->orWhere('qualification_holder_name', 'like', '%'.$q.'%')
                    ->orWhere('nrc_passport_number', 'like', '%'.$q.'%')
                    ->orWhere('title_of_qualification', 'like', '%'.$q.'%')
                    ->orWhere('certificate_number', 'like', '%'.$q.'%')
                    ->orWhere('student_number', 'like', '%'.$q.'%')
                    ->orWhere('examination_number', 'like', '%'.$q.'%');
            });
        }

        if (is_string($qualificationTypeId) && $qualificationTypeId !== '') {
            $query->where('qualification_type_id', (int) $qualificationTypeId);
        }
        if (is_string($awardingInstitutionId) && $awardingInstitutionId !== '') {
            $query->where('awarding_institution_id', (int) $awardingInstitutionId);
        }
        if (is_string($countryId) && $countryId !== '') {
            $query->where('country_id', (int) $countryId);
        }

        if ($foreign === '1') {
            $query->where('is_foreign_qualification', true);
        } elseif ($foreign === '0') {
            $query->where('is_foreign_qualification', false);
        }

        if ($assigned === '1') {
            $query->whereNotNull('assigned_verifier_id');
        } elseif ($assigned === '0') {
            $query->whereNull('assigned_verifier_id');
        }

        if (is_string($assignedVerifierId) && $assignedVerifierId !== '') {
            $query->where('assigned_verifier_id', (int) $assignedVerifierId);
        }

        if ($request->query('mine') === '1' && $currentUserId) {
            $query->where('assigned_verifier_id', $currentUserId);
        }

        if ($verificationState !== '') {
            $query->where('verification_state', $verificationState);
        } else {
            $query->where(function ($q) {
                $q->whereNull('qualifications.verification_state')
                    ->orWhere('qualifications.verification_state', '!=', VerificationState::ReturnedToApplicant->value);
            });
        }

        return $query
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString();
    }
}
