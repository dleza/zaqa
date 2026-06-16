<?php

namespace App\Domain\Reports;

use App\Models\Qualification;
use App\Models\QualificationAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;

final class Level1OfficerReportService
{
    public function countAssigned(User $user, Carbon $from, Carbon $to): int
    {
        return (int) QualificationAssignment::query()
            ->where('assigned_to_user_id', $user->id)
            ->whereBetween('assigned_at', [$from, $to])
            ->distinct()
            ->count('qualification_id');
    }

    public function countProcessed(User $user, Carbon $from, Carbon $to): int
    {
        return (int) Qualification::query()
            ->where('assigned_verifier_id', $user->id)
            ->whereNotNull('reviewed_at')
            ->whereBetween('reviewed_at', [$from, $to])
            ->count();
    }

    /**
     * @return array{
     *   summary: array{assigned: int, processed: int},
     *   assigned_chart: array{labels: list<string>, values: list<int>},
     *   processed_chart: array{labels: list<string>, values: list<int>},
     *   recent_processed: list<array{id: int, title: string, subtitle: string, href: string}>
     * }
     */
    public function dashboard(User $user, Carbon $from, Carbon $to): array
    {
        $labels = [];
        $assignedValues = [];
        $processedValues = [];

        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $dayStart = $cursor->copy()->startOfDay();
            $dayEnd = $cursor->copy()->endOfDay();
            $labels[] = $cursor->format('d M');
            $assignedValues[] = $this->countAssigned($user, $dayStart, $dayEnd);
            $processedValues[] = $this->countProcessed($user, $dayStart, $dayEnd);
            $cursor->addDay();
        }

        $recentProcessed = Qualification::query()
            ->with('application:id,application_number')
            ->where('assigned_verifier_id', $user->id)
            ->whereNotNull('reviewed_at')
            ->whereBetween('reviewed_at', [$from, $to])
            ->orderByDesc('reviewed_at')
            ->limit(15)
            ->get(['id', 'application_id', 'title_of_qualification', 'reviewed_at'])
            ->map(fn (Qualification $q) => [
                'id' => (int) $q->id,
                'title' => $q->application?->application_number ?? ('Qualification #'.$q->id),
                'subtitle' => trim((string) ($q->title_of_qualification ?? '')) !== ''
                    ? $q->title_of_qualification.' · Reviewed '.$q->reviewed_at?->toDateTimeString()
                    : 'Reviewed '.$q->reviewed_at?->toDateTimeString(),
                'href' => '/admin/verification/qualifications/'.$q->id,
            ])
            ->values()
            ->all();

        return [
            'summary' => [
                'assigned' => $this->countAssigned($user, $from, $to),
                'processed' => $this->countProcessed($user, $from, $to),
            ],
            'assigned_chart' => [
                'labels' => $labels,
                'values' => $assignedValues,
            ],
            'processed_chart' => [
                'labels' => $labels,
                'values' => $processedValues,
            ],
            'recent_processed' => $recentProcessed,
        ];
    }
}
