<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationLifecycleEvent;
use App\Models\ApplicationStatusHistory;
use App\Models\QualificationAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminSlaReportController extends Controller
{
    public function index(Request $request): Response
    {
        $range = (string) $request->query('range', 'last30');

        $to = $request->query('to') ? Carbon::parse((string) $request->query('to'))->endOfDay() : now()->endOfDay();
        $from = match ($range) {
            'last7' => $to->copy()->subDays(7)->startOfDay(),
            'last90' => $to->copy()->subDays(90)->startOfDay(),
            'custom' => ($request->query('from') ? Carbon::parse((string) $request->query('from'))->startOfDay() : $to->copy()->subDays(30)->startOfDay()),
            default => $to->copy()->subDays(30)->startOfDay(),
        };

        // Decided applications within window (decision time).
        $decided = Application::query()
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('approved_at', [$from, $to])->orWhereBetween('rejected_at', [$from, $to]);
            })
            ->whereIn('current_status', [ApplicationStatus::Approved, ApplicationStatus::Rejected])
            ->get([
                'id',
                'submitted_at',
                'service_deadline_at',
                'approved_at',
                'rejected_at',
            ]);

        $durationsSec = [];
        $lateDurationsSec = [];
        $onTime = 0;
        $late = 0;

        foreach ($decided as $a) {
            $submittedAt = $a->submitted_at;
            $deadlineAt = $a->service_deadline_at;
            $decisionAt = $a->approved_at ?: $a->rejected_at;
            if (! $submittedAt || ! $decisionAt || ! $deadlineAt) {
                continue;
            }

            $turnaround = $decisionAt->diffInSeconds($submittedAt);
            $durationsSec[] = $turnaround;

            if ($decisionAt->lessThanOrEqualTo($deadlineAt)) {
                $onTime++;
            } else {
                $late++;
                $lateDurationsSec[] = $decisionAt->diffInSeconds($deadlineAt);
            }
        }

        $overall = [
            'decisions_total' => $onTime + $late,
            'on_time' => $onTime,
            'late' => $late,
            'on_time_pct' => ($onTime + $late) > 0 ? round(($onTime / ($onTime + $late)) * 100, 1) : 0,
            'turnaround_avg_sec' => $this->avg($durationsSec),
            'turnaround_median_sec' => $this->median($durationsSec),
            'late_avg_sec' => $this->avg($lateDurationsSec),
            'late_median_sec' => $this->median($lateDurationsSec),
        ];

        // Level 2 performance: use status history actor for approve/reject transitions in window.
        $level2Rows = ApplicationStatusHistory::query()
            ->whereIn('to_status', [ApplicationStatus::Approved->value, ApplicationStatus::Rejected->value], 'and', false)
            ->whereBetween('changed_at', [$from, $to])
            ->get(['application_id', 'to_status', 'changed_by_user_id', 'changed_at']);

        $appsById = Application::query()
            ->whereIn('id', $level2Rows->pluck('application_id')->unique()->values(), 'and', false)
            ->get(['id', 'submitted_at', 'service_deadline_at', 'approved_at', 'rejected_at']);

        $appMap = $appsById->keyBy('id');

        $level2Agg = [];
        foreach ($level2Rows as $row) {
            $actorId = (int) ($row->changed_by_user_id ?? 0);
            if ($actorId <= 0) {
                continue;
            }
            $app = $appMap->get($row->application_id);
            if (! $app || ! $app->submitted_at || ! $app->service_deadline_at) {
                continue;
            }
            $decisionAt = $app->approved_at ?: $app->rejected_at ?: $row->changed_at;
            if (! $decisionAt) {
                continue;
            }

            $turnaroundSec = $decisionAt->diffInSeconds($app->submitted_at);
            $isOnTime = $decisionAt->lessThanOrEqualTo($app->service_deadline_at);

            $bucket = $level2Agg[$actorId] ?? [
                'reviewer_user_id' => $actorId,
                'decisions_total' => 0,
                'approved' => 0,
                'rejected' => 0,
                'on_time' => 0,
                'late' => 0,
                'turnaround' => [],
            ];

            $bucket['decisions_total']++;
            $bucket[$row->to_status === ApplicationStatus::Approved->value ? 'approved' : 'rejected']++;
            $bucket[$isOnTime ? 'on_time' : 'late']++;
            $bucket['turnaround'][] = $turnaroundSec;

            $level2Agg[$actorId] = $bucket;
        }

        $reviewerUsers = User::query()
            ->whereIn('id', array_keys($level2Agg), 'and', false)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $level2 = collect($level2Agg)
            ->map(function (array $row) use ($reviewerUsers) {
                $turn = $row['turnaround'];
                unset($row['turnaround']);
                $row['reviewer_name'] = $reviewerUsers->get($row['reviewer_user_id'])?->name;
                $row['on_time_pct'] = $row['decisions_total'] > 0 ? round(($row['on_time'] / $row['decisions_total']) * 100, 1) : 0;
                $row['turnaround_avg_sec'] = $this->avg($turn);
                $row['turnaround_median_sec'] = $this->median($turn);
                return $row;
            })
            ->sortByDesc('decisions_total')
            ->values()
            ->all();

        // Level 1 throughput (qualification assignments in window + completions in window).
        $assignments = QualificationAssignment::query()
            ->leftJoin('qualifications', 'qualifications.id', '=', 'qualification_assignments.qualification_id')
            ->whereBetween('qualification_assignments.assigned_at', [$from, $to])
            ->get([
                DB::raw('qualifications.application_id as application_id'),
                DB::raw('qualification_assignments.assigned_to_user_id as assigned_to_user_id'),
                DB::raw('qualification_assignments.assigned_at as assigned_at'),
            ]);

        $level1Completions = ApplicationLifecycleEvent::query()
            ->where('event_code', 'like', 'review.level1_completed.%')
            ->whereBetween('occurred_at', [$from, $to])
            ->get(['application_id', 'actor_user_id', 'occurred_at']);

        $level1Agg = [];

        foreach ($assignments as $a) {
            $uid = (int) $a->assigned_to_user_id;
            if ($uid <= 0) continue;
            $bucket = $level1Agg[$uid] ?? ['reviewer_user_id' => $uid, 'assignments_received' => 0, 'completed_handoffs' => 0, 'durations' => []];
            $bucket['assignments_received']++;
            $level1Agg[$uid] = $bucket;
        }

        // Build assignment lookup per application+assignee for duration calculation.
        $assignmentsByApp = $assignments
            ->sortBy('assigned_at')
            ->groupBy('application_id');

        foreach ($level1Completions as $c) {
            $uid = (int) $c->actor_user_id;
            if ($uid <= 0) continue;
            $bucket = $level1Agg[$uid] ?? ['reviewer_user_id' => $uid, 'assignments_received' => 0, 'completed_handoffs' => 0, 'durations' => []];
            $bucket['completed_handoffs']++;

            $rows = $assignmentsByApp->get($c->application_id) ?? collect();
            $assignedAt = $rows
                ->filter(fn ($r) => (int) $r->assigned_to_user_id === $uid && $r->assigned_at && $r->assigned_at->lessThanOrEqualTo($c->occurred_at))
                ->sortByDesc('assigned_at')
                ->first()?->assigned_at;

            if ($assignedAt) {
                $bucket['durations'][] = $c->occurred_at->diffInSeconds($assignedAt);
            }

            $level1Agg[$uid] = $bucket;
        }

        $level1Users = User::query()
            ->whereIn('id', array_keys($level1Agg), 'and', false)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $level1 = collect($level1Agg)
            ->map(function (array $row) use ($level1Users) {
                $dur = $row['durations'];
                unset($row['durations']);
                $row['reviewer_name'] = $level1Users->get($row['reviewer_user_id'])?->name;
                $row['assignment_to_complete_avg_sec'] = $this->avg($dur);
                $row['assignment_to_complete_median_sec'] = $this->median($dur);
                return $row;
            })
            ->sortByDesc('completed_handoffs')
            ->values()
            ->all();

        return Inertia::render('Admin/Reports/Sla', [
            'filters' => [
                'range' => $range,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'overall' => $overall,
            'level2' => $level2,
            'level1' => $level1,
        ]);
    }

    /**
     * @param array<int,int|float> $values
     */
    private function avg(array $values): ?int
    {
        $n = count($values);
        if ($n === 0) return null;
        return (int) round(array_sum($values) / $n);
    }

    /**
     * @param array<int,int|float> $values
     */
    private function median(array $values): ?int
    {
        $n = count($values);
        if ($n === 0) return null;
        sort($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) return (int) round($values[$mid]);
        return (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }
}

