<?php

namespace App\Domain\AdminDashboard;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationLifecycleEvent;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\QualificationDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Builds permission-filtered admin dashboard payloads (KPIs, charts, queues, quick actions).
 * No placeholder data: empty arrays when there is nothing to show.
 */
class AdminDashboardService
{
    /** @return list<ApplicationStatus> */
    private function poolStatuses(): array
    {
        return [
            ApplicationStatus::Submitted,
            ApplicationStatus::Resubmitted,
            ApplicationStatus::InProgress,
            ApplicationStatus::SentBack,
        ];
    }

    private function poolQuery(): Builder
    {
        return Application::query()->whereIn('current_status', $this->poolStatuses());
    }

    /**
     * @return array{labels: list<string>, dates: list<string>}
     */
    private function weekWindow(): array
    {
        $start = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $labels = [];
        $dates = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $start->copy()->addDays($i);
            $labels[] = $d->format('D');
            $dates[] = $d->toDateString();
        }

        return ['labels' => $labels, 'dates' => $dates];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $now->copy()->endOfWeek(Carbon::SUNDAY);

        $firstName = trim((string) ($user->first_name ?? ''));
        if ($firstName === '') {
            $firstName = trim(explode(' ', (string) ($user->name ?? ''), 2)[0] ?: 'there');
        }

        $hour = (int) $now->format('G');
        if ($hour < 12) {
            $greeting = 'Good morning';
        } elseif ($hour < 17) {
            $greeting = 'Good afternoon';
        } else {
            $greeting = 'Good evening';
        }

        $primaryRole = $user->getRoleNames()->first();
        if ($primaryRole === null || $primaryRole === '') {
            $primaryRole = 'Staff';
        }

        $subtitle = $this->resolveSubtitle($user);

        $kpis = [];
        $charts = [];
        $queues = [];
        $quickActions = [];

        $this->appendQuickActions($user, $quickActions);

        // ——— System / applications (broad) ———
        if ($user->can('admin.applications.view') || $user->can('verification.pool.view')) {
            $kpis[] = [
                'key' => 'applications_total',
                'label' => 'Total applications',
                'value' => Application::query()->count(),
                'icon' => 'files',
                'hint' => 'All time',
            ];

            $kpis[] = [
                'key' => 'applications_submitted_today',
                'label' => 'Submitted today',
                'value' => Application::query()->whereDate('submitted_at', $today)->count(),
                'icon' => 'inbox',
                'hint' => $now->timezoneName,
            ];
        }

        if ($user->can('verification.pool.view')) {
            $pendingVerification = $this->poolQuery()->count();
            $kpis[] = [
                'key' => 'pending_verification',
                'label' => 'Pending verification',
                'value' => $pendingVerification,
                'icon' => 'shield',
                'hint' => 'In verification pool',
                'href' => '/admin/verification/pool',
            ];

            $overduePool = $this->poolQuery()
                ->whereNotNull('service_deadline_at')
                ->where('service_deadline_at', '<', $now)
                ->count();

            $kpis[] = [
                'key' => 'verification_overdue',
                'label' => 'Overdue (pool)',
                'value' => $overduePool,
                'icon' => 'timer',
                'hint' => 'Past service deadline',
            ];

            $awaiting = Application::query()
                ->whereIn('current_status', $this->poolStatuses())
                ->where('verification_state', VerificationState::AwaitingAssignment)
                ->count();

            if ($user->can('verification.assign')) {
                $kpis[] = [
                    'key' => 'awaiting_assignment',
                    'label' => 'Awaiting assignment',
                    'value' => $awaiting,
                    'icon' => 'user-plus',
                    'href' => '/admin/verification/pool',
                ];
            }

            $queues[] = [
                'key' => 'recent_submissions',
                'title' => 'Recent submissions',
                'items' => $this->recentApplicationsQueue(8),
            ];
        }

        if ($user->can('admin.finance.view') || $user->can('finance.payment_proofs.view') || $user->can('finance.payments.view')) {
            $proofPending = Payment::query()
                ->where('status', PaymentStatus::AwaitingFinanceReview)
                ->count();

            $kpis[] = [
                'key' => 'payment_proofs_pending',
                'label' => 'Payment proofs to review',
                'value' => $proofPending,
                'icon' => 'banknote',
                'href' => '/finance/payment-proofs',
            ];

            $kpis[] = [
                'key' => 'invoices_today',
                'label' => 'Invoices issued today',
                'value' => Invoice::query()->whereDate('issued_at', $today)->count(),
                'icon' => 'receipt',
            ];

            $kpis[] = [
                'key' => 'payments_confirmed_today',
                'label' => 'Payments confirmed today',
                'value' => Payment::query()
                    ->where('status', PaymentStatus::Confirmed)
                    ->whereDate('confirmed_at', $today)
                    ->count(),
                'icon' => 'check',
            ];

            $revenueToday = (int) Payment::query()
                ->where('status', PaymentStatus::Confirmed)
                ->whereDate('confirmed_at', $today)
                ->sum('amount_cents');

            $kpis[] = [
                'key' => 'revenue_today',
                'label' => 'Revenue today',
                'value' => $revenueToday,
                'value_format' => 'cents',
                'icon' => 'coins',
            ];

            $revenueWeek = (int) Payment::query()
                ->where('status', PaymentStatus::Confirmed)
                ->whereBetween('confirmed_at', [$weekStart, $weekEnd])
                ->sum('amount_cents');

            $kpis[] = [
                'key' => 'revenue_week',
                'label' => 'Revenue this week',
                'value' => $revenueWeek,
                'value_format' => 'cents',
                'icon' => 'trending',
            ];

            $queues[] = [
                'key' => 'finance_proof_queue',
                'title' => 'Payment proofs awaiting review',
                'items' => $this->paymentProofQueuePreview(5),
            ];
        }

        if ($user->can('admin.certificates.view')) {
            $issued = Application::query()
                ->where(function ($q) {
                    $q->where('verification_state', VerificationState::CertificateIssued)
                        ->orWhere('current_status', ApplicationStatus::CertificateReady)
                        ->orWhere('current_status', ApplicationStatus::Completed);
                })
                ->count();

            $kpis[] = [
                'key' => 'certificates_path',
                'label' => 'Certificates / completions',
                'value' => $issued,
                'icon' => 'award',
                'hint' => 'Issued or ready',
                'href' => '/admin/certificates',
            ];
        }

        if ($user->can('admin.users.view')) {
            $staffActive = User::query()
                ->whereNull('applicant_type')
                ->where('is_active', true)
                ->whereNull('disabled_at')
                ->count();

            $kpis[] = [
                'key' => 'active_staff_users',
                'label' => 'Active staff users',
                'value' => $staffActive,
                'icon' => 'users',
                'href' => '/admin/users',
            ];
        }

        // ——— Level 1 ———
        if ($user->can('verification.level1.process')) {
            $mine = Application::query()
                ->where('assigned_level1_user_id', $user->id)
                ->whereIn('current_status', $this->poolStatuses())
                ->count();

            $kpis[] = [
                'key' => 'l1_assigned_to_me',
                'label' => 'Assigned to me',
                'value' => $mine,
                'icon' => 'user-check',
                'href' => '/admin/verification/assigned-to-me',
            ];

            $l1Overdue = Application::query()
                ->where('assigned_level1_user_id', $user->id)
                ->whereIn('current_status', $this->poolStatuses())
                ->whereNotNull('service_deadline_at')
                ->where('service_deadline_at', '<', $now)
                ->count();

            $kpis[] = [
                'key' => 'l1_my_overdue',
                'label' => 'My overdue cases',
                'value' => $l1Overdue,
                'icon' => 'alert',
                'href' => '/admin/verification/assigned-to-me',
            ];

            $sentBackMine = Application::query()
                ->where('assigned_level1_user_id', $user->id)
                ->where('current_status', ApplicationStatus::SentBack)
                ->count();

            $kpis[] = [
                'key' => 'l1_sent_back_assigned',
                'label' => 'Sent-back (assigned)',
                'value' => $sentBackMine,
                'icon' => 'undo',
            ];

            $completedToday = AuditLog::query()
                ->where('actor_user_id', $user->id)
                ->where('action_name', 'level1_completed')
                ->whereDate('created_at', $today)
                ->count();

            $kpis[] = [
                'key' => 'l1_completed_today',
                'label' => 'Reviews completed today',
                'value' => $completedToday,
                'icon' => 'check-circle',
            ];

            $queues[] = [
                'key' => 'l1_recent_assigned',
                'title' => 'Recently assigned to you',
                'items' => $this->assignedToUserQueue($user->id, 6),
            ];
        }

        // ——— Level 2 / supervisor ———
        if ($user->can('verification.level2.review') || $user->can('verification.assign')) {
            $l1Queue = Application::query()
                ->whereIn('current_status', $this->poolStatuses())
                ->whereNotNull('assigned_level1_user_id')
                ->count();

            $kpis[] = [
                'key' => 'l2_level1_queue',
                'label' => 'With Level 1',
                'value' => $l1Queue,
                'icon' => 'layers',
                'href' => '/admin/verification/pool?assigned=1',
            ];

            $pendingFinal = Application::query()
                ->where('verification_state', VerificationState::UnderLevel2Review)
                ->whereIn('current_status', $this->poolStatuses())
                ->count();

            $kpis[] = [
                'key' => 'l2_pending_final',
                'label' => 'Pending final review',
                'value' => $pendingFinal,
                'icon' => 'scale',
                'href' => '/admin/verification/pool',
            ];

            $sentBackResubmit = Application::query()
                ->whereIn('current_status', [ApplicationStatus::SentBack, ApplicationStatus::Resubmitted])
                ->count();

            $kpis[] = [
                'key' => 'l2_sent_back_resubmit',
                'label' => 'Sent-back / resubmitted',
                'value' => $sentBackResubmit,
                'icon' => 'refresh',
            ];

            $weekApprovals = AuditLog::query()
                ->where('action_name', 'approved')
                ->whereBetween('created_at', [$weekStart, $weekEnd])
                ->count();

            $weekRejections = AuditLog::query()
                ->where('action_name', 'rejected')
                ->whereBetween('created_at', [$weekStart, $weekEnd])
                ->count();

            $kpis[] = [
                'key' => 'l2_decisions_week',
                'label' => 'Decisions this week',
                'value' => $weekApprovals + $weekRejections,
                'hint' => $weekApprovals.' appr. · '.$weekRejections.' rej.',
                'icon' => 'gavel',
            ];
        }

        // ——— Audit ———
        if ($user->can('admin.audit.view')) {
            $kpis[] = [
                'key' => 'audit_events_today',
                'label' => 'Audit events today',
                'value' => AuditLog::query()->whereDate('created_at', $today)->count(),
                'icon' => 'scroll',
            ];


        }

        // ——— Charts (permission gated) ———
        $w = $this->weekWindow();

        if ($user->can('admin.applications.view') || $user->can('verification.pool.view')) {
            $series = [];
            foreach ($w['dates'] as $date) {
                $series[] = Application::query()->whereDate('submitted_at', $date)->count();
            }
            $charts[] = [
                'key' => 'applications_submitted_week',
                'title' => 'Applications submitted (this week)',
                'type' => 'bar',
                'labels' => $w['labels'],
                'values' => $series,
            ];

            $statusRows = Application::query()
                ->selectRaw('current_status as s, COUNT(*) as c')
                ->groupBy('current_status')
                ->pluck('c', 's')
                ->all();

            $charts[] = [
                'key' => 'applications_by_status',
                'title' => 'Applications by status',
                'type' => 'doughnut',
                'labels' => array_map(fn ($k) => str_replace('_', ' ', (string) $k), array_keys($statusRows)),
                'values' => array_values($statusRows),
            ];

            $local = Application::query()->where('is_foreign', false)->count();
            $foreign = Application::query()->where('is_foreign', true)->count();
            $charts[] = [
                'key' => 'applications_local_foreign',
                'title' => 'Local vs foreign',
                'type' => 'doughnut',
                'labels' => ['Local', 'Foreign'],
                'values' => [$local, $foreign],
            ];
        }

        if ($user->can('admin.finance.view')) {
            $revSeries = [];
            foreach ($w['dates'] as $date) {
                $revSeries[] = (int) Payment::query()
                    ->where('status', PaymentStatus::Confirmed)
                    ->whereDate('confirmed_at', $date)
                    ->sum('amount_cents');
            }
            $charts[] = [
                'key' => 'finance_revenue_week',
                'title' => 'Confirmed revenue by day (this week)',
                'type' => 'line',
                'labels' => $w['labels'],
                'values' => $revSeries,
                'value_format' => 'cents',
            ];

            $methodLabels = [];
            $methodValues = [];
            foreach (PaymentMethod::cases() as $case) {
                $methodLabels[] = str_replace('_', ' ', $case->value);
                $methodValues[] = (int) Payment::query()
                    ->where('status', PaymentStatus::Confirmed)
                    ->whereBetween('confirmed_at', [$weekStart, $weekEnd])
                    ->where('method', $case)
                    ->count();
            }
            $charts[] = [
                'key' => 'finance_methods_week',
                'title' => 'Confirmed payments by method (week)',
                'type' => 'doughnut',
                'labels' => $methodLabels,
                'values' => $methodValues,
            ];

            $pendingFinance = (int) Payment::query()->where('status', PaymentStatus::AwaitingFinanceReview)->count();
            $confirmedWeek = (int) Payment::query()
                ->where('status', PaymentStatus::Confirmed)
                ->whereBetween('confirmed_at', [$weekStart, $weekEnd])
                ->count();
            $charts[] = [
                'key' => 'finance_pending_vs_confirmed',
                'title' => 'Pending review vs confirmed (week)',
                'type' => 'doughnut',
                'labels' => ['Awaiting finance review', 'Confirmed this week'],
                'values' => [$pendingFinance, $confirmedWeek],
            ];
        }

        if ($user->can('verification.pool.view')) {
            $poolSeries = [];
            foreach ($w['dates'] as $date) {
                $poolSeries[] = $this->poolQuery()->whereDate('submitted_at', $date)->count();
            }
            $charts[] = [
                'key' => 'verification_pool_submissions_week',
                'title' => 'Pool applications by submission day (week)',
                'type' => 'line',
                'labels' => $w['labels'],
                'values' => $poolSeries,
            ];
        }

        if ($user->can('verification.level1.process')) {
            $l1Series = [];
            foreach ($w['dates'] as $date) {
                $l1Series[] = AuditLog::query()
                    ->where('actor_user_id', $user->id)
                    ->where('action_name', 'level1_completed')
                    ->whereDate('created_at', $date)
                    ->count();
            }
            $charts[] = [
                'key' => 'verification_l1_completed_week',
                'title' => 'Your Level 1 completions (this week)',
                'type' => 'bar',
                'labels' => $w['labels'],
                'values' => $l1Series,
            ];
        }

        if ($user->can('admin.audit.view')) {
            $auditSeries = [];
            foreach ($w['dates'] as $date) {
                $auditSeries[] = AuditLog::query()->whereDate('created_at', $date)->count();
            }
            $charts[] = [
                'key' => 'audit_events_week',
                'title' => 'Audit events by day (this week)',
                'type' => 'bar',
                'labels' => $w['labels'],
                'values' => $auditSeries,
            ];

            $byModule = AuditLog::query()
                ->selectRaw('module as m, COUNT(*) as c')
                ->whereBetween('created_at', [$weekStart, $weekEnd])
                ->groupBy('module')
                ->orderByDesc('c')
                ->limit(8)
                ->pluck('c', 'm')
                ->all();

            $charts[] = [
                'key' => 'audit_by_module_week',
                'title' => 'Audit activity by module (week)',
                'type' => 'doughnut',
                'labels' => array_keys($byModule),
                'values' => array_values($byModule),
            ];
        }



        // Receipt documents (generated receipt type) created today
        if ($user->can('admin.finance.view')) {
            $receiptsToday = QualificationDocument::query()
                ->where('document_type', DocumentType::GeneratedReceipt)
                ->whereDate('created_at', $today)
                ->count();

            $kpis[] = [
                'key' => 'receipts_documents_today',
                'label' => 'Receipt documents today',
                'value' => $receiptsToday,
                'icon' => 'file-text',
                'hint' => 'Generated receipt files',
            ];
        }

        $hasContent = $kpis !== [] || $charts !== [] || $queues !== [];

        return [
            'meta' => [
                'greeting_line' => $greeting.', '.$firstName,
                'subtitle' => $subtitle,
                'primary_role' => (string) $primaryRole,
                'current_date_formatted' => $now->translatedFormat('l, j F Y'),
                'timezone' => (string) config('app.timezone'),
            ],
            'kpis' => array_values($kpis),
            'charts' => array_values($charts),
            'queues' => array_values($queues),
            'quick_actions' => array_values($quickActions),
            'empty' => ! $hasContent,
        ];
    }

    private function resolveSubtitle(User $user): string
    {
        if ($user->can('verification.pool.view') || $user->can('verification.level1.process')) {
            return 'Here is what needs your attention today.';
        }
        if ($user->can('admin.finance.view')) {
            return 'Here is your finance operations summary.';
        }
        if ($user->can('admin.audit.view')) {
            return 'Monitoring and audit activity across the platform.';
        }

        return 'Welcome back to the ZAQA back-office portal.';
    }

    /**
     * @param  list<array{label: string, href?: string|null, permission: string}>  $actions
     */
    private function appendQuickActions(User $user, array &$actions): void
    {
        $push = function (string $label, string $href, string $icon, string $permission) use ($user, &$actions): void {
            if (! $user->can($permission)) {
                return;
            }
            $actions[] = ['label' => $label, 'href' => $href, 'icon' => $icon, 'permission' => $permission];
        };

        $push('Applications pool', '/admin/verification/pool', 'layers', 'verification.pool.view');
        $push('Assigned to me', '/admin/verification/assigned-to-me', 'user-check', 'verification.level1.process');
        $push('Application outcomes', '/admin/applications', 'clipboard', 'admin.applications.view');
        $push('Track application', '/admin/applications/track', 'search', 'admin.applications.view');
        $push('Finance dashboard', '/admin/finance', 'banknote', 'finance.dashboard.view');
        $push('Payment proofs', '/admin/finance/payment-proofs', 'banknote', 'finance.payment_proofs.view');
        $push('Processed payments', '/admin/finance/payments', 'banknote', 'finance.payments.view');
        $push('Manage users', '/admin/users', 'users', 'admin.users.view');
        $push('Applicants', '/admin/applicants', 'user', 'admin.applicants.view');
        $push('Roles & permissions', '/admin/roles', 'shield', 'admin.roles.view');
        $push('SLA performance', '/admin/reports/sla', 'activity', 'reports.sla.view');
        $push('Certificates', '/admin/certificates', 'award', 'admin.certificates.view');
        $push('Countries', '/admin/settings/countries', 'globe', 'settings.countries.view');
        $push('Certificate subjects', '/admin/settings/certificate-subjects', 'list', 'settings.certificate_subjects.view');
        $push('Awarding institutions', '/admin/settings/awarding-institutions', 'building', 'settings.awarding_institutions.view');
        $push('Qualification types', '/admin/settings/qualification-types', 'book', 'settings.qualification_types.view');
        $push('Fees', '/admin/settings/fees', 'coins', 'settings.fees.view');
        $push('Departments', '/admin/settings/departments', 'building-2', 'settings.departments.view');
    }

    /**
     * @return list<array{title: string, subtitle: string, href: string|null}>
     */
    private function recentApplicationsQueue(int $limit): array
    {
        $rows = Application::query()
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->limit($limit)
            ->get(['id', 'application_number', 'current_status', 'submitted_at']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'title' => $row->application_number,
                'subtitle' => 'Submitted '.optional($row->submitted_at)->toDateTimeString(),
                'href' => '/admin/verification/applications/'.$row->id,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{title: string, subtitle: string, href: string|null}>
     */
    private function assignedToUserQueue(int $userId, int $limit): array
    {
        $rows = Application::query()
            ->where('assigned_level1_user_id', $userId)
            ->whereIn('current_status', $this->poolStatuses())
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'application_number', 'current_status', 'updated_at']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'title' => $row->application_number,
                'subtitle' => 'Status: '.($row->current_status?->value ?? ''),
                'href' => '/admin/verification/applications/'.$row->id,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{title: string, subtitle: string, href: string|null}>
     */
    private function paymentProofQueuePreview(int $limit): array
    {
        $rows = Payment::query()
            ->where('status', PaymentStatus::AwaitingFinanceReview)
            ->with('application:id,application_number')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'title' => $row->application?->application_number ?? 'Payment #'.$row->id,
                'subtitle' => 'Method: '.($row->method?->value ?? '').' · '.$row->created_at?->toDateTimeString(),
                'href' => '/finance/payment-proofs',
            ];
        }

        return $out;
    }

    /**
     * @return list<array{title: string, subtitle: string, href: string|null}>
     */
    private function auditQueuePreview(int $limit): array
    {
        $rows = AuditLog::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'module', 'action_name', 'message', 'created_at']);

        $out = [];
        foreach ($rows as $row) {
            $msg = Str::limit((string) $row->message, 80);
            $out[] = [
                'title' => $row->module.' · '.$row->action_name,
                'subtitle' => $msg.' · '.$row->created_at?->toDateTimeString(),
                'href' => null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{title: string, subtitle: string, href: string|null}>
     */
    private function lifecycleQueuePreview(int $limit): array
    {
        $rows = ApplicationLifecycleEvent::query()
            ->with('application:id,application_number')
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $num = $row->application?->application_number ?? '#'.$row->application_id;
            $out[] = [
                'title' => $num.' — '.$row->title,
                'subtitle' => ($row->event_code ?? '').' · '.optional($row->occurred_at)->toDateTimeString(),
                'href' => $row->application_id ? '/admin/verification/applications/'.$row->application_id : null,
            ];
        }

        return $out;
    }
}
