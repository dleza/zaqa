<?php

namespace App\Domain\Verification;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApplicationsPoolService
{
    /**
     * Pool query for verification users.
     *
     * Filters:
     * - q: application number
     * - assigned: 1|0
     * - mine: 1
     * - overdue: 1
     * - overdue_days: 30|60|90 (overdue by at least N days)
     * - foreign: 1|0
     * - awarding_institution_id: int
     * - country_id: int (qualification country of award)
     * - submitted_from: Y-m-d
     * - submitted_to: Y-m-d
     * - qualification_q: string (qualification title contains)
     */
    public function pool(Request $request, ?int $currentUserId = null): LengthAwarePaginator
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $restrictLevel1 = VerificationQualificationAccess::mustRestrictToAssignedQualifications($viewer);

        $q = trim((string) $request->query('q', ''));
        $assigned = $request->query('assigned');
        $mine = $request->query('mine');
        $overdue = $request->query('overdue');
        $overdueDays = $request->query('overdue_days');
        $foreign = $request->query('foreign');
        $awardingInstitutionId = $request->query('awarding_institution_id');
        // Back-compat alias for older links; we keep it as a UI/query synonym.
        $legacyAwardingBodyId = $request->query('awarding_body_id');
        $countryId = $request->query('country_id');
        $submittedFrom = trim((string) $request->query('submitted_from', ''));
        $submittedTo = trim((string) $request->query('submitted_to', ''));
        $qualificationQ = trim((string) $request->query('qualification_q', ''));

        $query = Application::query()
            ->with([
                'applicant',
                'country',
                'awardingBody',
                'qualification.country',
                'qualification.awardingInstitution.country',
                'qualification.qualificationTypeMaster',
            ])
            ->whereIn('current_status', [
                ApplicationStatus::Submitted,
                ApplicationStatus::Resubmitted,
                ApplicationStatus::InProgress,
                ApplicationStatus::SentBack,
            ]);

        if ($restrictLevel1 && $viewer) {
            $query->whereHas(
                'qualifications',
                fn ($qq) => $qq->where('assigned_verifier_id', $viewer->id)
            );
        }

        if ($q !== '') {
            $query->where(function ($inner) use ($q) {
                $inner->where('application_number', 'like', '%'.$q.'%')
                    ->orWhere('metadata->verification_subject->full_name', 'like', '%'.$q.'%')
                    ->orWhere('metadata->verification_subject->nrc_number', 'like', '%'.$q.'%')
                    ->orWhere('metadata->verification_subject->passport_number', 'like', '%'.$q.'%')
                    ->orWhereHas('qualification', function ($qq) use ($q) {
                        $qq->where('qualification_holder_name', 'like', '%'.$q.'%')
                            ->orWhere('nrc_passport_number', 'like', '%'.$q.'%')
                            ->orWhere('title_of_qualification', 'like', '%'.$q.'%')
                            ->orWhere('certificate_number', 'like', '%'.$q.'%')
                            ->orWhere('student_number', 'like', '%'.$q.'%')
                            ->orWhere('examination_number', 'like', '%'.$q.'%');
                    });
            });
        }

        if ($assigned === '1') {
            $query->whereNotNull('assigned_level1_user_id');
        } elseif ($assigned === '0') {
            $query->whereNull('assigned_level1_user_id');
        }

        if ($mine === '1' && $currentUserId) {
            if ($restrictLevel1) {
                // Already scoped to qualifications assigned to the viewer; no application-level filter needed.
            } else {
                $query->where('assigned_level1_user_id', $currentUserId);
            }
        }

        if ($foreign === '1') {
            $query->where('is_foreign', true);
        } elseif ($foreign === '0') {
            $query->where('is_foreign', false);
        }

        if ($overdue === '1') {
            $query->whereNotNull('service_deadline_at')->where('service_deadline_at', '<', now());
        }

        if (is_string($overdueDays) && $overdueDays !== '') {
            $days = (int) $overdueDays;
            if (in_array($days, [30, 60, 90], true)) {
                $query->whereNotNull('service_deadline_at')->where('service_deadline_at', '<', now()->subDays($days));
            }
        }

        if ($submittedFrom !== '') {
            $query->whereDate('submitted_at', '>=', $submittedFrom);
        }
        if ($submittedTo !== '') {
            $query->whereDate('submitted_at', '<=', $submittedTo);
        }

        if ($qualificationQ !== '') {
            $query->whereHas('qualification', fn ($q) => $q->where('title_of_qualification', 'like', '%'.$qualificationQ.'%'));
        }

        $institutionFilter = null;
        if (is_string($awardingInstitutionId) && $awardingInstitutionId !== '') {
            $institutionFilter = (int) $awardingInstitutionId;
        } elseif (is_string($legacyAwardingBodyId) && $legacyAwardingBodyId !== '') {
            // Legacy param maps to awarding_institution_id now (local applications).
            $institutionFilter = (int) $legacyAwardingBodyId;
        }

        if ($institutionFilter !== null) {
            $query->whereHas('qualification', fn ($q) => $q->where('awarding_institution_id', $institutionFilter));
        }

        if (is_string($countryId) && $countryId !== '') {
            $query->whereHas('qualification', fn ($q) => $q->where('country_id', (int) $countryId));
        }

        return $query
            ->orderByRaw('service_deadline_at is null') // nulls last
            ->orderBy('service_deadline_at')
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Grouping counts by country of award based on qualification.country_id.
     * Includes Zambia by default; can be hidden via a flag.
     *
     * @return array<int, array{country_id:int|null, country_name:string, count:int}>
     */
    public function byCountryCounts(bool $hideZambia = false, array $filters = [], ?int $restrictToVerifierId = null): array
    {
        $submittedFrom = trim((string) ($filters['submitted_from'] ?? ''));
        $submittedTo = trim((string) ($filters['submitted_to'] ?? ''));
        $qualificationQ = trim((string) ($filters['qualification_q'] ?? ''));
        $overdueDays = (int) ($filters['overdue_days'] ?? 0);

        $rows = Application::query()
            ->leftJoin('qualifications', 'qualifications.application_id', '=', 'applications.id')
            ->leftJoin('countries', 'countries.id', '=', 'qualifications.country_id')
            ->when($restrictToVerifierId !== null, fn ($q) => $q->where('qualifications.assigned_verifier_id', $restrictToVerifierId))
            ->whereIn('applications.current_status', [
                ApplicationStatus::Submitted->value,
                ApplicationStatus::Resubmitted->value,
                ApplicationStatus::InProgress->value,
                ApplicationStatus::SentBack->value,
            ])
            ->when($hideZambia, fn ($q) => $q->whereRaw('coalesce(upper(countries.iso_code), "") != ?', ['ZMB']))
            ->when($submittedFrom !== '', fn ($q) => $q->whereDate('applications.submitted_at', '>=', $submittedFrom))
            ->when($submittedTo !== '', fn ($q) => $q->whereDate('applications.submitted_at', '<=', $submittedTo))
            ->when($qualificationQ !== '', fn ($q) => $q->where('qualifications.title_of_qualification', 'like', '%'.$qualificationQ.'%'))
            ->when(in_array($overdueDays, [30, 60, 90], true), fn ($q) => $q->whereNotNull('applications.service_deadline_at')->where('applications.service_deadline_at', '<', now()->subDays($overdueDays)))
            ->groupBy('qualifications.country_id', 'countries.name')
            ->orderByDesc(DB::raw('count(*)'))
            ->get([
                DB::raw('qualifications.country_id as country_id'),
                DB::raw('coalesce(countries.name, "Other") as country_name'),
                DB::raw('count(*) as cnt'),
            ]);

        return $rows->map(fn ($r) => [
            'country_id' => $r->country_id ? (int) $r->country_id : null,
            'country_name' => (string) $r->country_name,
            'count' => (int) $r->cnt,
        ])->all();
    }

    /**
     * Grouping counts by awarding institution. Qualification locality is controlled by filters:
     * - locality=all (default): all qualifications
     * - locality=local: is_foreign_qualification = false
     * - locality=foreign: is_foreign_qualification = true
     *
     * Uses qualification-level locality so mixed applications still contribute per-item counts.
     *
     * @param  array<string, mixed>  $filters  locality: all|local|foreign
     * @return array<int, array{awarding_institution_id:int|null, awarding_institution_name:string, count:int, local_count:int, foreign_count:int}>
     */
    public function byAwardingInstitutionCounts(array $filters = [], ?int $restrictToVerifierId = null): array
    {
        $submittedFrom = trim((string) ($filters['submitted_from'] ?? ''));
        $submittedTo = trim((string) ($filters['submitted_to'] ?? ''));
        $qualificationQ = trim((string) ($filters['qualification_q'] ?? ''));
        $overdueDays = (int) ($filters['overdue_days'] ?? 0);
        $locality = trim((string) ($filters['locality'] ?? ''));
        if (! in_array($locality, ['local', 'foreign'], true)) {
            $locality = 'all';
        }

        $rows = Application::query()
            ->join('qualifications', 'qualifications.application_id', '=', 'applications.id')
            ->leftJoin('awarding_institutions', 'awarding_institutions.id', '=', 'qualifications.awarding_institution_id')
            ->when($restrictToVerifierId !== null, fn ($q) => $q->where('qualifications.assigned_verifier_id', $restrictToVerifierId))
            ->whereIn('applications.current_status', [
                ApplicationStatus::Submitted->value,
                ApplicationStatus::Resubmitted->value,
                ApplicationStatus::InProgress->value,
                ApplicationStatus::SentBack->value,
            ])
            ->when($submittedFrom !== '', fn ($q) => $q->whereDate('applications.submitted_at', '>=', $submittedFrom))
            ->when($submittedTo !== '', fn ($q) => $q->whereDate('applications.submitted_at', '<=', $submittedTo))
            ->when($qualificationQ !== '', fn ($q) => $q->where('qualifications.title_of_qualification', 'like', '%'.$qualificationQ.'%'))
            ->when(in_array($overdueDays, [30, 60, 90], true), fn ($q) => $q->whereNotNull('applications.service_deadline_at')->where('applications.service_deadline_at', '<', now()->subDays($overdueDays)))
            ->groupBy('qualifications.awarding_institution_id', 'awarding_institutions.name', 'qualifications.awarding_institution_name_other')
            ->orderByDesc(DB::raw('count(*)'))
            ->get([
                DB::raw('qualifications.awarding_institution_id as awarding_institution_id'),
                DB::raw('coalesce(awarding_institutions.name, qualifications.awarding_institution_name_other, "Other") as awarding_institution_name'),
                DB::raw('sum(case when qualifications.is_foreign_qualification = 0 then 1 else 0 end) as local_cnt'),
                DB::raw('sum(case when qualifications.is_foreign_qualification = 1 then 1 else 0 end) as foreign_cnt'),
            ]);

        return $rows
            ->map(function ($r) use ($locality) {
                $localCount = (int) $r->local_cnt;
                $foreignCount = (int) $r->foreign_cnt;
                $displayCount = match ($locality) {
                    'local' => $localCount,
                    'foreign' => $foreignCount,
                    default => $localCount + $foreignCount,
                };

                return [
                    'awarding_institution_id' => $r->awarding_institution_id ? (int) $r->awarding_institution_id : null,
                    'awarding_institution_name' => (string) $r->awarding_institution_name,
                    'count' => $displayCount,
                    'local_count' => $localCount,
                    'foreign_count' => $foreignCount,
                ];
            })
            ->filter(fn (array $g) => $g['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->all();
    }
}
