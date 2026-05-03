<?php

namespace App\Domain\Reports;

use App\Enums\VerificationState;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Support\Reports\SqlDialect;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class QualificationVerificationReportService
{
    /**
     * @return array{
     *   summary: array<string, int|float>,
     *   by_type: list<array{label: string, value: int}>,
     *   local_foreign: array{labels: array<int, string>, values: array<int, int>},
     *   by_state: list<array{label: string, value: int}>,
     *   stacked_by_month: array{labels: array<int, string>, datasets: list<array{key: string, label: string, data: array<int, int>}>}
     * }
     */
    public function dashboard(
        Carbon $from,
        Carbon $to,
        ?string $verificationState,
        ?int $qualificationTypeId,
    ): array {
        $summary = $this->buildSummary($from, $to, $verificationState, $qualificationTypeId);
        $byType = $this->byQualificationType($from, $to, $verificationState, $qualificationTypeId);
        $localForeign = $this->localForeign($from, $to, $verificationState, $qualificationTypeId);
        $byState = $this->byState($from, $to, $verificationState, $qualificationTypeId);
        $stacked = $this->stackedStatusByMonth($from, $to, $qualificationTypeId);

        return [
            'summary' => $summary,
            'by_type' => $byType,
            'local_foreign' => $localForeign,
            'by_state' => $byState,
            'stacked_by_month' => $stacked,
        ];
    }

    public function baseQuery(Carbon $from, Carbon $to, ?string $verificationState, ?int $qualificationTypeId): Builder
    {
        $q = Qualification::query()
            ->whereBetween('qualifications.created_at', [$from, $to]);

        if ($verificationState !== null && $verificationState !== '') {
            $q->where('qualifications.verification_state', $verificationState);
        }
        if ($qualificationTypeId) {
            $q->where('qualifications.qualification_type_id', $qualificationTypeId);
        }

        return $q;
    }

    /**
     * @return array<string, int>
     */
    private function buildSummary(Carbon $from, Carbon $to, ?string $verificationState, ?int $qualificationTypeId): array
    {
        $base = $this->baseQuery($from, $to, $verificationState, $qualificationTypeId);
        $total = (clone $base)->count();

        $returned = (clone $base)->where('verification_state', VerificationState::ReturnedToApplicant)->count();
        $approved = (clone $base)->whereIn('verification_state', [
            VerificationState::ApprovedForCertificate,
            VerificationState::CertificateIssued,
        ])->count();
        $rejected = (clone $base)->where('verification_state', VerificationState::Rejected)->count();
        $local = (clone $base)->where('is_foreign_qualification', false)->count();
        $foreign = (clone $base)->where('is_foreign_qualification', true)->count();

        return [
            'total' => $total,
            'returned_for_amendment' => $returned,
            'approved' => $approved,
            'rejected' => $rejected,
            'local' => $local,
            'foreign' => $foreign,
        ];
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function byQualificationType(Carbon $from, Carbon $to, ?string $verificationState, ?int $qualificationTypeId): array
    {
        $rows = $this->baseQuery($from, $to, $verificationState, $qualificationTypeId)
            ->leftJoin('qualification_types as qt', 'qt.id', '=', 'qualifications.qualification_type_id')
            ->selectRaw('coalesce(qt.name, qualifications.qualification_type) as label, count(*) as c')
            ->groupBy('label')
            ->orderByDesc('c')
            ->limit(20)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['label' => (string) $r->label, 'value' => (int) $r->c];
        }

        return $out;
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function localForeign(Carbon $from, Carbon $to, ?string $verificationState, ?int $qualificationTypeId): array
    {
        $l = (clone $this->baseQuery($from, $to, $verificationState, $qualificationTypeId))
            ->where('is_foreign_qualification', false)->count();
        $f = (clone $this->baseQuery($from, $to, $verificationState, $qualificationTypeId))
            ->where('is_foreign_qualification', true)->count();

        return [
            'labels' => ['Local', 'Foreign'],
            'values' => [$l, $f],
        ];
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function byState(Carbon $from, Carbon $to, ?string $verificationState, ?int $qualificationTypeId): array
    {
        $rows = $this->baseQuery($from, $to, $verificationState, $qualificationTypeId)
            ->selectRaw('verification_state, count(*) as c')
            ->groupBy('verification_state')
            ->orderByDesc('c')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $state = $r->verification_state;
            $label = $state instanceof VerificationState
                ? str_replace('_', ' ', ucfirst($state->name))
                : $this->verificationStateValue($state);
            $out[] = ['label' => $label, 'value' => (int) $r->c];
        }

        return $out;
    }

    /**
     * @return array{labels: array<int, string>, datasets: list<array{key: string, label: string, data: array<int, int>}>}
     */
    private function stackedStatusByMonth(Carbon $from, Carbon $to, ?int $qualificationTypeId): array
    {
        $keys = [
            VerificationState::AwaitingAssignment->value,
            VerificationState::AssignedToLevel1->value,
            VerificationState::UnderLevel1Review->value,
            VerificationState::UnderLevel2Review->value,
            VerificationState::ReturnedToApplicant->value,
            VerificationState::ApprovedForCertificate->value,
            VerificationState::Rejected->value,
            VerificationState::CertificateIssued->value,
        ];

        $q = Qualification::query()
            ->whereBetween('qualifications.created_at', [$from, $to]);
        if ($qualificationTypeId) {
            $q->where('qualifications.qualification_type_id', $qualificationTypeId);
        }

        $month = SqlDialect::monthBucket('qualifications.created_at');
        $raw = $q
            ->selectRaw("{$month} as ym, verification_state, count(*) as c")
            ->groupBy('ym', 'verification_state')
            ->orderBy('ym')
            ->get();

        $months = $raw->pluck('ym')->unique()->sort()->values()->all();
        $byMonth = [];
        foreach ($raw as $row) {
            $ym = (string) $row->ym;
            $st = $this->verificationStateValue($row->verification_state);
            if (! isset($byMonth[$ym])) {
                $byMonth[$ym] = array_fill_keys($keys, 0);
            }
            if (in_array($st, $keys, true)) {
                $byMonth[$ym][$st] = (int) $row->c;
            }
        }

        $datasets = [];
        foreach ($keys as $k) {
            $datasets[] = [
                'key' => $k,
                'label' => str_replace('_', ' ', $k),
                'data' => array_map(fn ($m) => (int) ($byMonth[$m][$k] ?? 0), $months),
            ];
        }

        return [
            'labels' => $months,
            'datasets' => $datasets,
        ];
    }

    private function verificationStateValue(mixed $state): string
    {
        if ($state === null) {
            return '';
        }

        return $state instanceof VerificationState ? $state->value : (string) $state;
    }

    /**
     * @return LengthAwarePaginator<int, Qualification>
     */
    public function paginateDetail(
        Carbon $from,
        Carbon $to,
        ?string $verificationState,
        ?int $qualificationTypeId,
        int $perPage = 25,
    ): LengthAwarePaginator {
        return $this->baseQuery($from, $to, $verificationState, $qualificationTypeId)
            ->with(['application:id,application_number', 'qualificationTypeMaster:id,name'])
            ->orderByDesc('qualifications.created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return \Generator<int, list<string|int|null>>
     */
    public function exportRows(Carbon $from, Carbon $to, ?string $verificationState, ?int $qualificationTypeId): \Generator
    {
        yield ['id', 'application_id', 'verification_reference_number', 'qualification_type', 'verification_state', 'is_foreign_qualification', 'assigned_verifier_id', 'created_at'];

        $q = $this->baseQuery($from, $to, $verificationState, $qualificationTypeId)->orderBy('qualifications.id');

        foreach ($q->cursor() as $row) {
            /** @var Qualification $row */
            yield [
                $row->id,
                $row->application_id,
                $row->verification_reference_number,
                $row->qualification_type,
                $row->verification_state?->value,
                $row->is_foreign_qualification ? 1 : 0,
                $row->assigned_verifier_id,
                $row->created_at?->toIso8601String(),
            ];
        }
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function qualificationTypeOptions(): array
    {
        return QualificationType::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])
            ->all();
    }
}
