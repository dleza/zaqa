<?php

namespace App\Domain\Reports;

use App\Enums\VerificationState;
use App\Models\Qualification;
use App\Models\QualificationAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class VerifierPerformanceReportService
{
    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   workload_chart: array{labels: array<int, string>, values: array<int, int>},
     *   completed_chart: array{labels: array<int, string>, values: array<int, int>},
     *   pending_chart: array{labels: array<int, string>, values: array<int, int>}
     * }
     */
    public function dashboard(Carbon $from, Carbon $to, ?int $verifierUserId): array
    {
        $rows = $this->aggregateRows($from, $to, $verifierUserId);

        $labels = array_column($rows, 'name');
        $workload = array_column($rows, 'assignments');
        $completed = array_column($rows, 'completed');
        $pending = array_column($rows, 'pending');

        return [
            'rows' => $rows,
            'workload_chart' => ['labels' => $labels, 'values' => $workload],
            'completed_chart' => ['labels' => $labels, 'values' => $completed],
            'pending_chart' => ['labels' => $labels, 'values' => $pending],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregateRows(Carbon $from, Carbon $to, ?int $verifierUserId): array
    {
        $assignmentCounts = QualificationAssignment::query()
            ->whereBetween('assigned_at', [$from, $to])
            ->when($verifierUserId, fn ($q) => $q->where('assigned_to_user_id', $verifierUserId))
            ->selectRaw('assigned_to_user_id as uid, count(*) as c')
            ->whereNotNull('assigned_to_user_id')
            ->groupBy('uid')
            ->pluck('c', 'uid');

        $completedCounts = Qualification::query()
            ->whereNotNull('assigned_verifier_id')
            ->whereNotNull('reviewed_at')
            ->whereBetween('reviewed_at', [$from, $to])
            ->when($verifierUserId, fn ($q) => $q->where('assigned_verifier_id', $verifierUserId))
            ->selectRaw('assigned_verifier_id as uid, count(*) as c')
            ->groupBy('uid')
            ->pluck('c', 'uid');

        $avgExpr = DB::connection()->getDriverName() === 'sqlite'
            ? 'avg((strftime("%s", reviewed_at) - strftime("%s", assigned_at)))'
            : 'avg(timestampdiff(SECOND, assigned_at, reviewed_at))';

        $avgMap = Qualification::query()
            ->whereNotNull('assigned_verifier_id')
            ->whereNotNull('reviewed_at')
            ->whereNotNull('assigned_at')
            ->whereBetween('reviewed_at', [$from, $to])
            ->when($verifierUserId, fn ($q) => $q->where('assigned_verifier_id', $verifierUserId))
            ->selectRaw("assigned_verifier_id as uid, {$avgExpr} as avg_sec")
            ->groupBy('assigned_verifier_id')
            ->pluck('avg_sec', 'uid');

        $returned = Qualification::query()
            ->whereNotNull('assigned_verifier_id')
            ->where('verification_state', VerificationState::ReturnedToApplicant)
            ->whereBetween('returned_to_applicant_at', [$from, $to])
            ->when($verifierUserId, fn ($q) => $q->where('assigned_verifier_id', $verifierUserId))
            ->selectRaw('assigned_verifier_id as uid, count(*) as c')
            ->groupBy('uid')
            ->pluck('c', 'uid');

        $rejected = Qualification::query()
            ->whereNotNull('assigned_verifier_id')
            ->where('verification_state', VerificationState::Rejected)
            ->whereBetween('reviewed_at', [$from, $to])
            ->when($verifierUserId, fn ($q) => $q->where('assigned_verifier_id', $verifierUserId))
            ->selectRaw('assigned_verifier_id as uid, count(*) as c')
            ->groupBy('uid')
            ->pluck('c', 'uid');

        $pendingStates = [
            VerificationState::AssignedToLevel1,
            VerificationState::UnderLevel1Review,
            VerificationState::UnderLevel2Review,
        ];

        $pendingCounts = Qualification::query()
            ->whereNotNull('assigned_verifier_id')
            ->whereIn('verification_state', $pendingStates)
            ->when($verifierUserId, fn ($q) => $q->where('assigned_verifier_id', $verifierUserId))
            ->selectRaw('assigned_verifier_id as uid, count(*) as c')
            ->groupBy('uid')
            ->pluck('c', 'uid');

        $uids = collect()
            ->merge($assignmentCounts->keys())
            ->merge($completedCounts->keys())
            ->merge($pendingCounts->keys())
            ->merge($returned->keys())
            ->merge($rejected->keys())
            ->unique()
            ->filter()
            ->all();

        $users = User::query()->whereIn('id', $uids)->get(['id', 'name'])->keyBy('id');

        $out = [];
        foreach ($uids as $uid) {
            $id = (int) $uid;
            $out[] = [
                'user_id' => $id,
                'name' => $users->get($id)?->name ?? ('#'.$id),
                'assignments' => (int) ($assignmentCounts[$id] ?? 0),
                'completed' => (int) ($completedCounts[$id] ?? 0),
                'pending' => (int) ($pendingCounts[$id] ?? 0),
                'avg_review_seconds' => isset($avgMap[$id]) ? (int) round((float) $avgMap[$id]) : null,
                'returned' => (int) ($returned[$id] ?? 0),
                'rejected' => (int) ($rejected[$id] ?? 0),
            ];
        }

        usort($out, fn ($a, $b) => $b['assignments'] <=> $a['assignments']);

        return $out;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function verifierOptions(): array
    {
        $ids = Qualification::query()
            ->whereNotNull('assigned_verifier_id')
            ->distinct()
            ->pluck('assigned_verifier_id')
            ->merge(
                QualificationAssignment::query()
                    ->whereNotNull('assigned_to_user_id')
                    ->distinct()
                    ->pluck('assigned_to_user_id'),
            )
            ->unique()
            ->filter()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids->all())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }

    /**
     * @return \Generator<int, list<string|int|float|null>>
     */
    public function exportRows(Carbon $from, Carbon $to, ?int $verifierUserId): \Generator
    {
        yield ['user_id', 'name', 'assignments_in_period', 'completed_in_period', 'pending_now', 'avg_review_seconds', 'returned', 'rejected'];

        foreach ($this->aggregateRows($from, $to, $verifierUserId) as $r) {
            yield [
                $r['user_id'],
                $r['name'],
                $r['assignments'],
                $r['completed'],
                $r['pending'],
                $r['avg_review_seconds'],
                $r['returned'],
                $r['rejected'],
            ];
        }
    }
}
