<?php

namespace App\Domain\Reports;

use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Support\Reports\SqlDialect;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class ApplicationsReportService
{
    /**
     * @return array{summary: array<string, mixed>, by_status: array<int, array{label: string, value: int}>, submissions_over_time: array{labels: array<int, string>, values: array<int, int>}, applicant_type: array{labels: array<int, string>, values: array<int, int>}}
     */
    public function dashboard(Carbon $from, Carbon $to, ?string $status, ?string $applicantType): array
    {
        $summary = $this->buildSummary($from, $to, $status, $applicantType);
        $byStatus = $this->statusBreakdown($from, $to, $status, $applicantType);
        $overTime = $this->submissionsOverTime($from, $to, $status, $applicantType);
        $applicant = $this->applicantTypeBreakdown($from, $to, $status, $applicantType);

        return [
            'summary' => $summary,
            'by_status' => $byStatus,
            'submissions_over_time' => $overTime,
            'applicant_type' => $applicant,
        ];
    }

    public function baseQuery(Carbon $from, Carbon $to, ?string $status, ?string $applicantType): Builder
    {
        $q = Application::query()
            ->whereBetween('created_at', [$from, $to]);

        if ($status !== null && $status !== '') {
            $q->where('current_status', $status);
        }
        if ($applicantType !== null && $applicantType !== '') {
            $q->where('applicant_type', $applicantType);
        }

        return $q;
    }

    /**
     * @return array<string, int|float>
     */
    private function buildSummary(Carbon $from, Carbon $to, ?string $status, ?string $applicantType): array
    {
        $base = $this->baseQuery($from, $to, $status, $applicantType);
        $total = (clone $base)->count();

        $by = [];
        foreach ((clone $base)
            ->selectRaw('current_status, count(*) as c')
            ->groupBy('current_status')
            ->get() as $row) {
            $by[$this->applicationStatusValue($row->current_status)] = (int) $row->c;
        }

        $draft = (int) ($by[ApplicationStatus::Draft->value] ?? 0);
        $submittedScope = (int) (($by[ApplicationStatus::Submitted->value] ?? 0) + ($by[ApplicationStatus::Resubmitted->value] ?? 0));
        $sentBack = (int) ($by[ApplicationStatus::SentBack->value] ?? 0);
        $approvedDone = (int) (($by[ApplicationStatus::Approved->value] ?? 0) + ($by[ApplicationStatus::CertificateReady->value] ?? 0) + ($by[ApplicationStatus::Completed->value] ?? 0));
        $rejected = (int) ($by[ApplicationStatus::Rejected->value] ?? 0);
        $inProgress = (int) (($by[ApplicationStatus::InProgress->value] ?? 0) + ($by[ApplicationStatus::PendingPayment->value] ?? 0));

        return [
            'total' => $total,
            'draft' => $draft,
            'submitted' => $submittedScope,
            'sent_back' => $sentBack,
            'approved_or_completed' => $approvedDone,
            'rejected' => $rejected,
            'in_progress' => $inProgress,
        ];
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function statusBreakdown(Carbon $from, Carbon $to, ?string $status, ?string $applicantType): array
    {
        $rows = $this->baseQuery($from, $to, $status, $applicantType)
            ->selectRaw('current_status, count(*) as c')
            ->groupBy('current_status')
            ->orderByDesc('c')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'label' => $this->statusLabel($this->applicationStatusValue($row->current_status)),
                'value' => (int) $row->c,
            ];
        }

        return $out;
    }

    private function applicationStatusValue(mixed $status): string
    {
        return $status instanceof ApplicationStatus ? $status->value : (string) $status;
    }

    private function applicantTypeValue(mixed $type): string
    {
        return $type instanceof ApplicantType ? $type->value : (string) $type;
    }

    private function statusLabel(string $s): string
    {
        foreach (ApplicationStatus::cases() as $case) {
            if ($case->value === $s) {
                return str_replace('_', ' ', ucfirst($case->name));
            }
        }

        return $s;
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function submissionsOverTime(Carbon $from, Carbon $to, ?string $status, ?string $applicantType): array
    {
        $q = Application::query()
            ->whereNotNull('submitted_at')
            ->whereBetween('submitted_at', [$from, $to]);
        if ($status !== null && $status !== '') {
            $q->where('current_status', $status);
        }
        if ($applicantType !== null && $applicantType !== '') {
            $q->where('applicant_type', $applicantType);
        }

        $d = SqlDialect::dateBucket('submitted_at');
        $rows = $q
            ->selectRaw("{$d} as d, count(*) as c")
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        $labels = [];
        $values = [];
        foreach ($rows as $r) {
            $labels[] = (string) $r->d;
            $values[] = (int) $r->c;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function applicantTypeBreakdown(Carbon $from, Carbon $to, ?string $status, ?string $applicantType): array
    {
        $rows = $this->baseQuery($from, $to, $status, $applicantType)
            ->selectRaw('applicant_type, count(*) as c')
            ->groupBy('applicant_type')
            ->get();

        $labels = [];
        $values = [];
        foreach ($rows as $r) {
            $t = $this->applicantTypeValue($r->applicant_type);
            $labels[] = $t === ApplicantType::Institution->value ? 'Institution' : 'Individual';
            $values[] = (int) $r->c;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @return LengthAwarePaginator<int, Application>
     */
    public function paginateDetail(Carbon $from, Carbon $to, ?string $status, ?string $applicantType, int $perPage = 25): LengthAwarePaginator
    {
        return $this->baseQuery($from, $to, $status, $applicantType)
            ->with(['applicant:id,name,email'])
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * CSV rows generator — bounded by same filters; caller streams.
     *
     * @return \Generator<int, list<string|int|null>>
     */
    public function exportRows(Carbon $from, Carbon $to, ?string $status, ?string $applicantType): \Generator
    {
        yield ['id', 'application_number', 'applicant_type', 'current_status', 'submitted_at', 'created_at'];

        $q = $this->baseQuery($from, $to, $status, $applicantType)
            ->orderBy('id');

        foreach ($q->cursor() as $a) {
            /** @var Application $a */
            yield [
                $a->id,
                $a->application_number,
                $a->applicant_type?->value,
                $a->current_status?->value,
                $a->submitted_at?->toIso8601String(),
                $a->created_at?->toIso8601String(),
            ];
        }
    }
}
