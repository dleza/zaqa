<?php

namespace App\Domain\Reports;

use App\Domain\Verification\QualificationSlaService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SlaTurnaroundReportService
{
    /**
     * Qualification-level turnaround metrics (aggregated in SQL).
     *
     * @return array{
     *   avg_submission_to_assignment_sec: int|null,
     *   avg_assignment_to_review_sec: int|null,
     *   avg_review_to_certificate_sec: int|null,
     *   overdue_qualifications: int,
     *   active_qualifications_past_deadline: int,
     *   compliance: array{within_sla: int, overdue: int},
     *   overdue_by_verifier: list<array{name: string, count: int}>,
     *   trend_months: array{labels: array<int, string>, values: array<int, float>}
     * }
     */
    public function metrics(Carbon $from, Carbon $to): array
    {
        $driver = DB::connection()->getDriverName();

        $avgSubmitAssign = $this->scalarAvgSeconds(
            DB::table('qualifications as q')
                ->join('applications as a', 'a.id', '=', 'q.application_id')
                ->where(function ($q) {
                    $q->whereNotNull('q.service_started_at')
                        ->orWhereNotNull('a.submitted_at');
                })
                ->whereNotNull('q.assigned_at')
                ->whereBetween('q.assigned_at', [$from, $to]),
            $driver === 'sqlite'
                ? 'avg(strftime("%s", q.assigned_at) - strftime("%s", coalesce(q.service_started_at, a.submitted_at)))'
                : 'avg(timestampdiff(SECOND, coalesce(q.service_started_at, a.submitted_at), q.assigned_at))'
        );

        $avgAssignReview = $this->scalarAvgSeconds(
            DB::table('qualifications')
                ->whereNotNull('assigned_at')
                ->whereNotNull('reviewed_at')
                ->whereBetween('reviewed_at', [$from, $to]),
            $driver === 'sqlite'
                ? 'avg(strftime("%s", reviewed_at) - strftime("%s", assigned_at))'
                : 'avg(timestampdiff(SECOND, assigned_at, reviewed_at))'
        );

        $avgReviewCert = $this->scalarAvgSeconds(
            DB::table('qualifications as q')
                ->join('qualification_certificates as c', 'c.qualification_id', '=', 'q.id')
                ->whereNotNull('q.reviewed_at')
                ->whereBetween('c.issued_at', [$from, $to]),
            $driver === 'sqlite'
                ? 'avg(strftime("%s", c.issued_at) - strftime("%s", q.reviewed_at))'
                : 'avg(timestampdiff(SECOND, q.reviewed_at, c.issued_at))'
        );

        $overdueBase = $this->openQualificationDeadlineBase();

        $overdue = (int) (clone $overdueBase)
            ->whereRaw('coalesce(q.service_deadline_at, a.service_deadline_at) is not null')
            ->whereRaw('coalesce(q.service_deadline_at, a.service_deadline_at) < ?', [now()])
            ->count();

        $pastDeadline = (int) (clone $overdueBase)
            ->whereRaw('coalesce(q.service_deadline_at, a.service_deadline_at) is not null')
            ->whereRaw('coalesce(q.service_deadline_at, a.service_deadline_at) < ?', [now()])
            ->count();

        $within = (int) DB::table('applications')
            ->whereNotNull('submitted_at')
            ->whereNotNull('service_deadline_at')
            ->whereBetween('submitted_at', [$from, $to])
            ->where(function ($q) {
                $q->where(function ($w) {
                    $w->whereNotNull('approved_at')
                        ->whereColumn('approved_at', '<=', 'service_deadline_at');
                })->orWhere(function ($w) {
                    $w->whereNotNull('rejected_at')
                        ->whereColumn('rejected_at', '<=', 'service_deadline_at');
                });
            })
            ->count();

        $lateApps = (int) DB::table('applications')
            ->whereNotNull('submitted_at')
            ->whereNotNull('service_deadline_at')
            ->whereBetween('submitted_at', [$from, $to])
            ->where(function ($q) {
                $q->where(function ($w) {
                    $w->whereNotNull('approved_at')
                        ->whereColumn('approved_at', '>', 'service_deadline_at');
                })->orWhere(function ($w) {
                    $w->whereNotNull('rejected_at')
                        ->whereColumn('rejected_at', '>', 'service_deadline_at');
                });
            })
            ->count();

        $overdueByVerifier = DB::table('qualifications as q')
            ->join('applications as a', 'a.id', '=', 'q.application_id')
            ->join('users as u', 'u.id', '=', 'q.assigned_verifier_id')
            ->where(function ($q) {
                $q->whereNull('q.verification_state')
                    ->orWhereNotIn('q.verification_state', QualificationSlaService::CLOSED_QUALIFICATION_STATES);
            })
            ->whereNotIn('a.current_status', QualificationSlaService::CLOSED_APPLICATION_STATUSES)
            ->whereRaw('coalesce(q.service_deadline_at, a.service_deadline_at) is not null')
            ->whereRaw('coalesce(q.service_deadline_at, a.service_deadline_at) < ?', [now()])
            ->whereNotNull('q.assigned_verifier_id')
            ->selectRaw('u.name as name, count(*) as c')
            ->groupBy('u.name')
            ->orderByDesc('c')
            ->limit(10)
            ->get();

        $verifierRows = [];
        foreach ($overdueByVerifier as $r) {
            $verifierRows[] = ['name' => (string) $r->name, 'count' => (int) $r->c];
        }

        return [
            'avg_submission_to_assignment_sec' => $avgSubmitAssign,
            'avg_assignment_to_review_sec' => $avgAssignReview,
            'avg_review_to_certificate_sec' => $avgReviewCert,
            'overdue_qualifications' => $overdue,
            'active_qualifications_past_deadline' => $pastDeadline,
            'compliance' => [
                'within_sla' => $within,
                'overdue' => $lateApps,
            ],
            'overdue_by_verifier' => $verifierRows,
            'trend_months' => $this->avgTurnaroundTrend($from, $to),
        ];
    }

    private function scalarAvgSeconds(Builder $base, string $expression): ?int
    {
        $v = (clone $base)->selectRaw($expression.' as avg_sec')->value('avg_sec');
        if ($v === null) {
            return null;
        }

        return (int) round((float) $v);
    }

    /**
     * Average decision turnaround (submission → approve/reject) by application submission month.
     *
     * @return array{labels: array<int, string>, values: array<int, float>}
     */
    private function avgTurnaroundTrend(Carbon $from, Carbon $to): array
    {
        $driver = DB::connection()->getDriverName();
        $monthExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', submitted_at)"
            : "date_format(submitted_at, '%Y-%m')";

        $secExpr = $driver === 'sqlite'
            ? '(strftime("%s", coalesce(approved_at, rejected_at)) - strftime("%s", submitted_at))'
            : 'timestampdiff(SECOND, submitted_at, coalesce(approved_at, rejected_at))';

        $rows = DB::table('applications')
            ->whereNotNull('submitted_at')
            ->whereBetween('submitted_at', [$from, $to])
            ->where(function ($q) {
                $q->whereNotNull('approved_at')->orWhereNotNull('rejected_at');
            })
            ->selectRaw("{$monthExpr} as ym, avg({$secExpr}) as avg_sec")
            ->groupBy('ym')
            ->orderBy('ym')
            ->get();

        $labels = [];
        $values = [];
        foreach ($rows as $r) {
            $labels[] = (string) $r->ym;
            $values[] = round(((float) $r->avg_sec) / 3600, 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    private function openQualificationDeadlineBase(): Builder
    {
        return DB::table('qualifications as q')
            ->join('applications as a', 'a.id', '=', 'q.application_id')
            ->where(function ($q) {
                $q->whereNull('q.verification_state')
                    ->orWhereNotIn('q.verification_state', QualificationSlaService::CLOSED_QUALIFICATION_STATES);
            })
            ->whereNotIn('a.current_status', QualificationSlaService::CLOSED_APPLICATION_STATUSES);
    }
}
