<?php

namespace App\Domain\Reports;

use App\Models\AwardingInstitution;
use App\Models\Qualification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class AwardingInstitutionsReportService
{
    /**
     * @return array{
     *   summary: array<string, int>,
     *   top_institutions: list<array{name: string, count: int}>,
     *   local_foreign_qualifications: array{labels: array<int, string>, values: array<int, int>},
     *   institutions_missing_consent: int,
     *   returned_rejected_by_institution: list<array{name: string, returned: int, rejected: int}>
     * }
     */
    public function dashboard(
        Carbon $from,
        Carbon $to,
        ?int $awardingInstitutionId,
        ?bool $foreignQualificationOnly,
    ): array {
        return [
            'summary' => $this->summary($from, $to, $awardingInstitutionId, $foreignQualificationOnly),
            'top_institutions' => $this->topInstitutions($from, $to, $awardingInstitutionId, $foreignQualificationOnly),
            'local_foreign_qualifications' => $this->localForeign($from, $to, $awardingInstitutionId, $foreignQualificationOnly),
            'institutions_missing_consent' => $this->missingConsentCount($from, $to),
            'returned_rejected_by_institution' => $this->returnedRejected($from, $to, $awardingInstitutionId, $foreignQualificationOnly),
        ];
    }

    private function base(Carbon $from, Carbon $to, ?int $awardingInstitutionId, ?bool $foreignQualificationOnly): Builder
    {
        $q = Qualification::query()
            ->whereBetween('qualifications.created_at', [$from, $to]);

        if ($awardingInstitutionId) {
            $q->where('qualifications.awarding_institution_id', $awardingInstitutionId);
        }
        if ($foreignQualificationOnly !== null) {
            $q->where('qualifications.is_foreign_qualification', $foreignQualificationOnly);
        }

        return $q;
    }

    /**
     * @return array<string, int>
     */
    private function summary(Carbon $from, Carbon $to, ?int $awardingInstitutionId, ?bool $foreignQualificationOnly): array
    {
        $b = $this->base($from, $to, $awardingInstitutionId, $foreignQualificationOnly);

        return [
            'qualifications_total' => (clone $b)->count(),
            'with_institution_id' => (clone $b)->whereNotNull('awarding_institution_id')->count(),
            'local_qualifications' => (clone $b)->where('is_foreign_qualification', false)->count(),
            'foreign_qualifications' => (clone $b)->where('is_foreign_qualification', true)->count(),
        ];
    }

    /**
     * @return list<array{name: string, count: int}>
     */
    private function topInstitutions(Carbon $from, Carbon $to, ?int $awardingInstitutionId, ?bool $foreignQualificationOnly): array
    {
        $rows = $this->base($from, $to, $awardingInstitutionId, $foreignQualificationOnly)
            ->whereNotNull('qualifications.awarding_institution_id')
            ->join('awarding_institutions as ai', 'ai.id', '=', 'qualifications.awarding_institution_id')
            ->selectRaw('ai.name as name, count(*) as c')
            ->groupBy('ai.name')
            ->orderByDesc('c')
            ->limit(10)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['name' => (string) $r->name, 'count' => (int) $r->c];
        }

        return $out;
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function localForeign(Carbon $from, Carbon $to, ?int $awardingInstitutionId, ?bool $foreignQualificationOnly): array
    {
        $b = $this->base($from, $to, $awardingInstitutionId, $foreignQualificationOnly);
        $l = (clone $b)->where('is_foreign_qualification', false)->count();
        $f = (clone $b)->where('is_foreign_qualification', true)->count();

        return [
            'labels' => ['Local qualification', 'Foreign qualification'],
            'values' => [$l, $f],
        ];
    }

    private function missingConsentCount(Carbon $from, Carbon $to): int
    {
        return (int) Qualification::query()
            ->whereBetween('qualifications.created_at', [$from, $to])
            ->whereNotNull('qualifications.awarding_institution_id')
            ->join('awarding_institutions as ai', 'ai.id', '=', 'qualifications.awarding_institution_id')
            ->where(function ($q) {
                $q->whereNull('ai.consent_form_path')->orWhere('ai.consent_form_path', '');
            })
            ->selectRaw('count(distinct qualifications.awarding_institution_id) as aggregate')
            ->value('aggregate');
    }

    /**
     * @return list<array{name: string, returned: int, rejected: int}>
     */
    private function returnedRejected(Carbon $from, Carbon $to, ?int $awardingInstitutionId, ?bool $foreignQualificationOnly): array
    {
        $rows = $this->base($from, $to, $awardingInstitutionId, $foreignQualificationOnly)
            ->whereNotNull('qualifications.awarding_institution_id')
            ->join('awarding_institutions as ai', 'ai.id', '=', 'qualifications.awarding_institution_id')
            ->selectRaw(
                'ai.name as name, '.
                "sum(case when qualifications.verification_state = 'returned_to_applicant' then 1 else 0 end) as returned, ".
                "sum(case when qualifications.verification_state = 'rejected' then 1 else 0 end) as rejected",
            )
            ->groupBy('ai.name')
            // MySQL: cannot ORDER BY alias expressions that use aggregates in this form; repeat the sums.
            ->orderByRaw(
                "sum(case when qualifications.verification_state = 'returned_to_applicant' then 1 else 0 end) + ".
                "sum(case when qualifications.verification_state = 'rejected' then 1 else 0 end) desc",
            )
            ->limit(15)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name' => (string) $r->name,
                'returned' => (int) $r->returned,
                'rejected' => (int) $r->rejected,
            ];
        }

        return $out;
    }

    /**
     * @return LengthAwarePaginator<int, Qualification>
     */
    public function paginateDetail(
        Carbon $from,
        Carbon $to,
        ?int $awardingInstitutionId,
        ?bool $foreignQualificationOnly,
        int $perPage = 25,
    ): LengthAwarePaginator {
        return $this->base($from, $to, $awardingInstitutionId, $foreignQualificationOnly)
            ->with(['awardingInstitution:id,name', 'application:id,application_number'])
            ->orderByDesc('qualifications.created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return \Generator<int, list<string|int|null>>
     */
    public function exportRows(
        Carbon $from,
        Carbon $to,
        ?int $awardingInstitutionId,
        ?bool $foreignQualificationOnly,
    ): \Generator {
        yield ['id', 'application_id', 'awarding_institution_id', 'awarding_institution_name', 'is_foreign_qualification', 'verification_state', 'created_at'];

        $q = $this->base($from, $to, $awardingInstitutionId, $foreignQualificationOnly)->orderBy('qualifications.id');

        foreach ($q->cursor() as $row) {
            /** @var Qualification $row */
            yield [
                $row->id,
                $row->application_id,
                $row->awarding_institution_id,
                $row->awarding_institution_name,
                $row->is_foreign_qualification ? 1 : 0,
                $row->verification_state?->value,
                $row->created_at?->toIso8601String(),
            ];
        }
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function institutionOptions(): array
    {
        return AwardingInstitution::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name])
            ->all();
    }
}
