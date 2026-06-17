<?php

namespace App\Domain\AdminDashboard;

use App\Domain\Finance\FinanceDashboardMetricsService;
use App\Domain\Reports\Level1OfficerReportService;
use App\Domain\Verification\QualificationSlaService;
use App\Domain\Verification\VerificationQualificationAccess;
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
use App\Models\Qualification;
use App\Models\QualificationAssignment;
use App\Models\QualificationDocument;
use App\Models\SmsBalanceAccount;
use App\Models\SmsLog;
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
    public function build(User $user, DashboardDateRange $range): array
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $from = $range->from;
        $to = $range->to;
        $rangeLabel = $range->label();
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
        $alerts = [];
        $financeBreakdowns = null;

        $this->appendQuickActions($user, $quickActions);
        $this->appendSmsDashboardWidgets($user, $kpis, $alerts);

        // ——— System / applications (broad) ———
        if ($user->can('admin.applications.view')) {
            if ($this->isLevel2ScopedDashboard($user)) {
                $periodLabel = $rangeLabel.' · entered workflow';
                $processedLabel = $rangeLabel.' · final decisions';

                $kpis[] = [
                    'key' => 'l2_total_qualifications',
                    'label' => 'Total qualifications',
                    'value' => $this->applyWorkflowEntryDateRange(
                        $this->level2InWorkflowQualificationQuery(),
                        $from,
                        $to
                    )->count(),
                    'icon' => 'files',
                    'hint' => $periodLabel,
                    'metric_scope' => 'period',
                    'href' => '/admin/verification/pool',
                ];

                $kpis[] = [
                    'key' => 'l2_processed',
                    'label' => 'Processed',
                    'value' => $this->countLevel2ProcessedQualifications($from, $to),
                    'icon' => 'check-circle',
                    'hint' => $processedLabel,
                    'metric_scope' => 'period',
                ];

                $kpis[] = [
                    'key' => 'l2_with_level1',
                    'label' => 'With Level 1',
                    'value' => $this->level2WithLevel1Query()->count(),
                    'icon' => 'user',
                    'hint' => 'Current queue · Level 1 review',
                    'metric_scope' => 'current_queue',
                    'href' => '/admin/verification/pool?verification_state=under_level1_review',
                ];

                $kpis[] = [
                    'key' => 'l2_with_level2',
                    'label' => 'With Level 2',
                    'value' => $this->level2WithLevel2Query()->count(),
                    'icon' => 'scale',
                    'hint' => 'Current queue · Level 2 review',
                    'metric_scope' => 'current_queue',
                    'href' => '/admin/verification/pool?verification_state=under_level2_review',
                ];

                $kpis[] = [
                    'key' => 'l2_unassigned_level1',
                    'label' => 'Unassigned Level 1',
                    'value' => $this->level2UnassignedLevel1Query()->count(),
                    'icon' => 'user-plus',
                    'hint' => 'Current queue · awaiting Level 1',
                    'metric_scope' => 'current_queue',
                    'href' => '/admin/verification/pool?verification_state=awaiting_assignment',
                ];

                $kpis[] = [
                    'key' => 'l2_unassigned_level2',
                    'label' => 'Unassigned Level 2',
                    'value' => $this->level2UnassignedLevel2Query()->count(),
                    'icon' => 'user-plus',
                    'hint' => 'Current queue · awaiting Level 2',
                    'metric_scope' => 'current_queue',
                    'href' => '/admin/verification/pool?verification_state=under_level2_review',
                ];

                $kpis[] = [
                    'key' => 'l2_auto_verified_awaiting',
                    'label' => 'Auto-verified awaiting L2',
                    'value' => $this->level2AutoVerifiedAwaitingQuery()->count(),
                    'icon' => 'sparkles',
                    'hint' => 'Current queue · pending L2 approval',
                    'metric_scope' => 'current_queue',
                    'href' => '/admin/verification/auto-verified',
                ];

                $kpis[] = [
                    'key' => 'l2_assigned_to_me',
                    'label' => 'Assigned to me',
                    'value' => $this->level2AssignedToUserQuery($user)->count(),
                    'icon' => 'user-check',
                    'hint' => 'Current queue · your Level 2 tasks',
                    'metric_scope' => 'user_specific',
                    'href' => '/admin/verification/assigned-to-me',
                ];

                $kpis[] = [
                    'key' => 'l2_overdue_local',
                    'label' => 'Local overdue',
                    'value' => $this->level2OverdueQualificationQuery(false)->count(),
                    'icon' => 'timer',
                    'hint' => 'Current overdue · local',
                    'metric_scope' => 'overdue',
                    'href' => '/admin/verification/pool?overdue=1&foreign=0',
                ];

                $kpis[] = [
                    'key' => 'l2_overdue_foreign',
                    'label' => 'Foreign overdue',
                    'value' => $this->level2OverdueQualificationQuery(true)->count(),
                    'icon' => 'timer',
                    'hint' => 'Current overdue · foreign',
                    'metric_scope' => 'overdue',
                    'href' => '/admin/verification/pool?overdue=1&foreign=1',
                ];
            } elseif ($this->isLevel1ScopedDashboard($user)) {
                $reportService = app(Level1OfficerReportService::class);

                $kpis[] = [
                    'key' => 'l1_total_assigned_30d',
                    'label' => 'Assigned to me',
                    'value' => $reportService->countAssigned($user, $from, $to),
                    'icon' => 'user-check',
                    'hint' => $rangeLabel,
                    'href' => '/admin/reports/my-performance?range=last'.$range->selected,
                ];

                $kpis[] = [
                    'key' => 'l1_total_processed_30d',
                    'label' => 'Processed',
                    'value' => $reportService->countProcessed($user, $from, $to),
                    'icon' => 'check-circle',
                    'hint' => $rangeLabel.' · Level 1 reviews completed',
                    'href' => '/admin/reports/my-performance?range=last'.$range->selected,
                ];

                $pendingAssigned = $this->applyWorkflowEntryDateRange(
                    Qualification::query()
                        ->where('assigned_verifier_id', $user->id)
                        ->whereHas('application', fn ($q) => $q->whereIn('current_status', $this->poolStatuses()))
                        ->where(function ($q) {
                            $q->whereNull('verification_state')
                                ->orWhereNotIn('verification_state', [
                                    VerificationState::ApprovedForCertificate->value,
                                    VerificationState::Rejected->value,
                                    VerificationState::CertificateIssued->value,
                                    VerificationState::Closed->value,
                                ]);
                        }),
                    $from,
                    $to
                );

                $kpis[] = [
                    'key' => 'l1_pending_assigned',
                    'label' => 'Pending',
                    'value' => $pendingAssigned->count(),
                    'icon' => 'shield',
                    'hint' => $rangeLabel.' · open assigned tasks',
                    'href' => '/admin/verification/assigned-to-me',
                ];

                $everAssigned = $this->qualificationsEverAssignedToQuery($user);

                $kpis[] = [
                    'key' => 'l1_assigned_submitted_today',
                    'label' => 'Received in period',
                    'value' => $this->applyWorkflowEntryDateRange(clone $everAssigned, $from, $to)
                        ->distinct()
                        ->count('qualifications.id'),
                    'icon' => 'inbox',
                    'hint' => $rangeLabel,
                    'href' => '/admin/verification/assigned-to-me?submitted_from='.$from->toDateString().'&submitted_to='.$to->toDateString(),
                ];
            } else {
                $kpis[] = [
                    'key' => 'applications_total',
                    'label' => 'Applications submitted',
                    'value' => Application::query()
                        ->whereNotNull('submitted_at')
                        ->whereBetween('submitted_at', [$from, $to])
                        ->count(),
                    'icon' => 'files',
                    'hint' => $rangeLabel,
                ];

                $qualSubmitted = $this->applyWorkflowEntryDateRange(
                    $this->level2WorkflowQualificationQuery(),
                    $from,
                    $to
                );

                $kpis[] = [
                    'key' => 'qualifications_submitted',
                    'label' => 'Qualifications submitted',
                    'value' => $qualSubmitted->count(),
                    'icon' => 'inbox',
                    'hint' => $rangeLabel.' · entered workflow',
                    'href' => '/admin/verification/pool',
                ];
            }
        }

        if ($user->can('verification.pool.view')) {
            if (! $this->isLevel2ScopedDashboard($user)) {
                if (VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)) {
                    $pendingVerification = $this->applyWorkflowEntryDateRange(
                        Qualification::query()
                            ->where('assigned_verifier_id', $user->id)
                            ->whereHas('application', fn ($q) => $q->whereIn('current_status', $this->poolStatuses()))
                            ->where(function ($q) {
                                $q->whereNull('verification_state')
                                    ->orWhere('verification_state', '!=', VerificationState::ReturnedToApplicant->value);
                            }),
                        $from,
                        $to
                    )->count();
                } else {
                    $pendingVerification = $this->applyWorkflowEntryDateRange(
                        Qualification::query()
                            ->whereHas('application', fn ($q) => $q->whereIn('current_status', $this->poolStatuses()))
                            ->where(function ($q) {
                                $q->whereNull('verification_state')
                                    ->orWhereIn('verification_state', [
                                        VerificationState::AwaitingAssignment->value,
                                        VerificationState::AssignedToLevel1->value,
                                        VerificationState::UnderLevel1Review->value,
                                        VerificationState::UnderLevel2Review->value,
                                        VerificationState::AutoVerifiedPendingLevel2->value,
                                    ]);
                            }),
                        $from,
                        $to
                    )->count();
                }

                $kpis[] = [
                    'key' => 'pending_verification',
                    'label' => $this->isLevel1ScopedDashboard($user) ? 'Active assigned tasks' : 'Pending verification',
                    'value' => $pendingVerification,
                    'icon' => 'shield',
                    'hint' => $rangeLabel,
                    'href' => $this->isLevel1ScopedDashboard($user) ? '/admin/verification/assigned-to-me' : '/admin/verification/pool',
                ];

                if (! $this->isLevel1ScopedDashboard($user)) {
                    $overduePool = $this->applyWorkflowEntryDateRange(
                        Qualification::query()->whereHas(
                            'application',
                            fn ($q) => $q->whereIn('current_status', $this->poolStatuses())
                        ),
                        $from,
                        $to
                    );
                    $this->applyOpenQualificationSlaScope($overduePool);
                    $this->applyQualificationOverdueFilter($overduePool, $now);

                    $kpis[] = [
                        'key' => 'verification_overdue',
                        'label' => 'Overdue qualifications',
                        'value' => $overduePool->count(),
                        'icon' => 'timer',
                        'hint' => $rangeLabel.' · past service deadline',
                        'href' => '/admin/verification/pool?overdue=1',
                    ];
                }

                $awaiting = $this->applyWorkflowEntryDateRange(
                    Qualification::query()
                        ->where('verification_state', VerificationState::AwaitingAssignment)
                        ->whereHas('application', fn ($q) => $q->whereIn('current_status', $this->poolStatuses())),
                    $from,
                    $to
                )->count();

                if ($user->can('verification.assign')) {
                    $kpis[] = [
                        'key' => 'awaiting_assignment',
                        'label' => 'Awaiting assignment',
                        'value' => $awaiting,
                        'icon' => 'user-plus',
                        'hint' => $rangeLabel,
                        'href' => '/admin/verification/pool',
                    ];
                }
            }

            $queues[] = [
                'key' => 'recent_submissions',
                'title' => $this->isLevel2ScopedDashboard($user)
                    ? 'Current qualification tasks'
                    : ($this->isLevel1ScopedDashboard($user)
                        ? 'Your current assigned qualifications'
                        : (VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)
                            ? 'Applications with your assigned qualifications'
                            : 'Recent submissions')),
                'subtitle' => ($this->isLevel2ScopedDashboard($user) || $this->isLevel1ScopedDashboard($user))
                    ? 'Actionable items (not limited by date range)'
                    : null,
                'items' => $this->isLevel2ScopedDashboard($user)
                    ? $this->recentLevel2QualificationsQueue(8)
                    : ($this->isLevel1ScopedDashboard($user)
                        ? $this->recentAssignedQualificationsQueue($user, 8)
                        : (VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)
                            ? $this->recentAssignedQualificationApplicationsQueue($user, 8)
                            : $this->recentApplicationsQueue(8))),
            ];
        }

        if ($user->can('admin.finance.view') || $user->can('finance.payment_proofs.view') || $user->can('finance.payments.view')) {
            /** @var FinanceDashboardMetricsService $financeMetrics */
            $financeMetrics = app(FinanceDashboardMetricsService::class);

            $proofPending = Payment::query()
                ->where('status', PaymentStatus::AwaitingFinanceReview)
                ->count();

            $revenuePeriod = $financeMetrics->totalRevenueCents($from, $to);

            $financeKpis = [
                [
                    'key' => 'revenue_period',
                    'label' => 'Revenue',
                    'value' => $revenuePeriod,
                    'value_format' => 'cents',
                    'icon' => 'coins',
                    'hint' => $rangeLabel,
                ],
            ];

            if ($this->isFinanceScopedDashboard($user)) {
                $financeKpis[] = [
                    'key' => 'revenue_local_qualifications',
                    'label' => 'Local qualification revenue',
                    'value' => $financeMetrics->localQualificationRevenueCents($from, $to),
                    'value_format' => 'cents',
                    'icon' => 'building',
                    'hint' => $rangeLabel,
                ];
                $financeKpis[] = [
                    'key' => 'revenue_foreign_qualifications',
                    'label' => 'Foreign qualification revenue',
                    'value' => $financeMetrics->foreignQualificationRevenueCents($from, $to),
                    'value_format' => 'cents',
                    'icon' => 'globe',
                    'hint' => $rangeLabel,
                ];
            }

            $financeKpis = array_merge($financeKpis, [
                [
                    'key' => 'payment_proofs_pending',
                    'label' => 'Payment proofs to review',
                    'value' => $proofPending,
                    'icon' => 'banknote',
                    'hint' => 'Current queue',
                    'href' => '/admin/finance/payment-proofs',
                ],
                [
                    'key' => 'payments_confirmed',
                    'label' => 'Payments confirmed',
                    'value' => Payment::query()
                        ->where('status', PaymentStatus::Confirmed)
                        ->whereBetween('confirmed_at', [$from, $to])
                        ->count(),
                    'icon' => 'check',
                    'hint' => $rangeLabel,
                ],
                [
                    'key' => 'invoices_issued',
                    'label' => 'Invoices issued',
                    'value' => Invoice::query()->whereBetween('issued_at', [$from, $to])->count(),
                    'icon' => 'receipt',
                    'hint' => $rangeLabel,
                ],
                [
                    'key' => 'payment_proofs_reviewed',
                    'label' => 'Proofs reviewed',
                    'value' => Payment::query()
                        ->whereIn('status', [PaymentStatus::Confirmed, PaymentStatus::Rejected])
                        ->whereBetween('reviewed_at', [$from, $to])
                        ->count(),
                    'icon' => 'check-circle',
                    'hint' => $rangeLabel,
                    'href' => '/admin/finance/payment-proofs',
                ],
                [
                    'key' => 'receipts_documents_period',
                    'label' => 'Receipt documents',
                    'value' => QualificationDocument::query()
                        ->where('document_type', DocumentType::GeneratedReceipt)
                        ->whereBetween('created_at', [$from, $to])
                        ->count(),
                    'icon' => 'file-text',
                    'hint' => $rangeLabel,
                ],
            ]);

            if ($this->isFinanceScopedDashboard($user)) {
                $kpis = array_merge($financeKpis, $kpis);
                $financeBreakdowns = [
                    'revenue_by_fee_structure' => $financeMetrics->revenueByFeeStructure($from, $to),
                ];
            } else {
                $kpis = array_merge($kpis, $financeKpis);
            }

            $queues[] = [
                'key' => 'finance_proof_queue',
                'title' => 'Payment proofs awaiting review',
                'subtitle' => 'Current queue (not limited by date range)',
                'items' => $this->paymentProofQueuePreview(5),
            ];
        }

        if ($user->can('admin.certificates.view') && ! $this->isLevel2ScopedDashboard($user)) {
            $issued = Application::query()
                ->where(function ($q) {
                    $q->where('verification_state', VerificationState::CertificateIssued)
                        ->orWhere('current_status', ApplicationStatus::CertificateReady)
                        ->orWhere('current_status', ApplicationStatus::Completed);
                })
                ->where('updated_at', '>=', $from)
                ->where('updated_at', '<=', $to)
                ->count();

            $kpis[] = [
                'key' => 'certificates_path',
                'label' => 'Certificates / completions',
                'value' => $issued,
                'icon' => 'award',
                'hint' => $rangeLabel.' · issued or ready',
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
        if ($user->can('verification.level1.process') && ! $this->isLevel1ScopedDashboard($user)) {
            if (VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)) {
                $mine = Application::query()
                    ->whereHas('qualifications', fn ($q) => $q->where('assigned_verifier_id', $user->id))
                    ->whereIn('current_status', $this->poolStatuses())
                    ->count();
            } else {
                $mine = Application::query()
                    ->where('assigned_level1_user_id', $user->id)
                    ->whereIn('current_status', $this->poolStatuses())
                    ->count();
            }

            $kpis[] = [
                'key' => 'l1_assigned_to_me',
                'label' => 'Assigned to me',
                'value' => $mine,
                'icon' => 'user-check',
                'href' => '/admin/verification/assigned-to-me',
            ];

            if (VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)) {
                $l1Overdue = Application::query()
                    ->whereHas('qualifications', fn ($q) => $q->where('assigned_verifier_id', $user->id))
                    ->whereIn('current_status', $this->poolStatuses())
                    ->whereNotNull('service_deadline_at')
                    ->where('service_deadline_at', '<', $now)
                    ->count();
            } else {
                $l1Overdue = Application::query()
                    ->where('assigned_level1_user_id', $user->id)
                    ->whereIn('current_status', $this->poolStatuses())
                    ->whereNotNull('service_deadline_at')
                    ->where('service_deadline_at', '<', $now)
                    ->count();
            }

            $kpis[] = [
                'key' => 'l1_my_overdue',
                'label' => 'My overdue cases',
                'value' => $l1Overdue,
                'icon' => 'alert',
                'href' => '/admin/verification/assigned-to-me',
            ];

            if (VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)) {
                $sentBackMine = Application::query()
                    ->whereHas('qualifications', fn ($q) => $q->where('assigned_verifier_id', $user->id))
                    ->where('current_status', ApplicationStatus::SentBack)
                    ->count();
            } else {
                $sentBackMine = Application::query()
                    ->where('assigned_level1_user_id', $user->id)
                    ->where('current_status', ApplicationStatus::SentBack)
                    ->count();
            }

            $kpis[] = [
                'key' => 'l1_sent_back_assigned',
                'label' => 'Sent-back (assigned)',
                'value' => $sentBackMine,
                'icon' => 'undo',
                'href' => '/admin/verification/awaiting-applicant-resubmission',
            ];

            $completedToday = Qualification::query()
                ->where('assigned_verifier_id', $user->id)
                ->whereNotNull('reviewed_at')
                ->whereDate('reviewed_at', $today)
                ->count();

            $kpis[] = [
                'key' => 'l1_completed_today',
                'label' => 'Reviews completed today',
                'value' => $completedToday,
                'icon' => 'check-circle',
                'href' => '/admin/verification/assigned-to-me',
            ];

            $queues[] = [
                'key' => 'l1_recent_assigned',
                'title' => 'Recently assigned to you',
                'items' => $this->isLevel1ScopedDashboard($user)
                    ? $this->assignedToUserQualificationsQueue($user, 6)
                    : $this->assignedToUserQueue($user, 6),
            ];
        }

        // ——— Level 2 / supervisor (broad roles — not the Level 2 officer scoped dashboard) ———
        if (($user->can('verification.level2.review') || $user->can('verification.assign')) && ! $this->isLevel2ScopedDashboard($user)) {
            $l1Queue = $this->applyWorkflowEntryDateRange(
                Qualification::query()
                    ->whereIn('verification_state', [
                        VerificationState::AssignedToLevel1->value,
                        VerificationState::UnderLevel1Review->value,
                    ])
                    ->whereHas('application', fn ($q) => $q->whereIn('current_status', $this->poolStatuses())),
                $from,
                $to
            )->count();

            $kpis[] = [
                'key' => 'l2_level1_queue',
                'label' => 'With Level 1',
                'value' => $l1Queue,
                'icon' => 'layers',
                'hint' => $rangeLabel,
                'href' => '/admin/verification/pool?assigned=1',
            ];

            $pendingFinal = $this->applyWorkflowEntryDateRange(
                $this->level2ReadyForReviewQuery(),
                $from,
                $to
            )->count();

            $kpis[] = [
                'key' => 'l2_pending_final',
                'label' => 'Ready for Level 2',
                'value' => $pendingFinal,
                'icon' => 'scale',
                'hint' => $rangeLabel,
                'href' => '/admin/verification/pool',
            ];

            $autoVerified = $this->applyWorkflowEntryDateRange(
                $this->level2AutoVerifiedAwaitingQuery(),
                $from,
                $to
            )->count();

            $kpis[] = [
                'key' => 'l2_auto_verified_pending',
                'label' => 'Auto-verified awaiting L2',
                'value' => $autoVerified,
                'icon' => 'sparkles',
                'hint' => $rangeLabel,
                'href' => '/admin/verification/auto-verified',
            ];

            $sentBackResubmit = Application::query()
                ->whereIn('current_status', [ApplicationStatus::SentBack, ApplicationStatus::Resubmitted])
                ->where('updated_at', '>=', $from)
                ->where('updated_at', '<=', $to)
                ->count();

            $kpis[] = [
                'key' => 'l2_sent_back_resubmit',
                'label' => 'Sent-back / resubmitted',
                'value' => $sentBackResubmit,
                'icon' => 'refresh',
                'hint' => $rangeLabel,
            ];

            $l2Processed = $this->countLevel2ProcessedQualifications($from, $to);

            $kpis[] = [
                'key' => 'l2_decisions_period',
                'label' => 'Level 2 decisions',
                'value' => $l2Processed,
                'icon' => 'gavel',
                'hint' => $rangeLabel,
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
        $w = $this->rangeWindow($range);

        if ($user->can('admin.applications.view') && ! $this->isLevel1ScopedDashboard($user) && ! $this->isLevel2ScopedDashboard($user)) {
            $series = [];
            foreach ($w['dates'] as $date) {
                $series[] = Application::query()->whereDate('submitted_at', $date)->count();
            }
            $charts[] = [
                'key' => 'applications_submitted_week',
                'title' => 'Applications submitted ('.$rangeLabel.')',
                'type' => 'bar',
                'labels' => $w['labels'],
                'values' => $series,
            ];

            $statusRows = Application::query()
                ->whereNotNull('submitted_at')
                ->whereBetween('submitted_at', [$from, $to])
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

            $local = Application::query()
                ->where('is_foreign', false)
                ->whereNotNull('submitted_at')
                ->whereBetween('submitted_at', [$from, $to])
                ->count();
            $foreign = Application::query()
                ->where('is_foreign', true)
                ->whereNotNull('submitted_at')
                ->whereBetween('submitted_at', [$from, $to])
                ->count();
            $charts[] = [
                'key' => 'applications_local_foreign',
                'title' => 'Local vs foreign ('.$rangeLabel.')',
                'type' => 'doughnut',
                'labels' => ['Local', 'Foreign'],
                'values' => [$local, $foreign],
            ];
        }

        if ($user->can('admin.finance.view') || $user->can('finance.payments.view')) {
            $revSeries = [];
            foreach ($w['dates'] as $date) {
                $revSeries[] = (int) Payment::query()
                    ->where('status', PaymentStatus::Confirmed)
                    ->whereDate('confirmed_at', $date)
                    ->sum('amount_cents');
            }
            $charts[] = [
                'key' => 'finance_revenue_week',
                'title' => 'Confirmed revenue by day ('.$rangeLabel.')',
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
                    ->whereBetween('confirmed_at', [$from, $to])
                    ->where('method', $case)
                    ->count();
            }
            $charts[] = [
                'key' => 'finance_methods_week',
                'title' => 'Confirmed payments by method ('.$rangeLabel.')',
                'type' => 'doughnut',
                'labels' => $methodLabels,
                'values' => $methodValues,
            ];

            $pendingFinance = (int) Payment::query()->where('status', PaymentStatus::AwaitingFinanceReview)->count();
            $confirmedPeriod = (int) Payment::query()
                ->where('status', PaymentStatus::Confirmed)
                ->whereBetween('confirmed_at', [$from, $to])
                ->count();
            $charts[] = [
                'key' => 'finance_pending_vs_confirmed',
                'title' => 'Pending review vs confirmed ('.$rangeLabel.')',
                'type' => 'doughnut',
                'labels' => ['Awaiting finance review', 'Confirmed in period'],
                'values' => [$pendingFinance, $confirmedPeriod],
            ];
        }

        if ($this->isLevel1ScopedDashboard($user)) {
            $stateRows = $this->applyWorkflowEntryDateRange(
                $this->qualificationsEverAssignedToQuery($user),
                $from,
                $to
            )
                ->selectRaw('verification_state as s, COUNT(*) as c')
                ->groupBy('verification_state')
                ->pluck('c', 's')
                ->all();

            $charts[] = [
                'key' => 'verification_l1_assigned_by_state',
                'title' => 'Your assigned qualifications by status ('.$rangeLabel.')',
                'type' => 'doughnut',
                'labels' => array_map(fn ($k) => str_replace('_', ' ', (string) $k), array_keys($stateRows)),
                'values' => array_values($stateRows),
            ];
        }

        if ($this->isLevel2ScopedDashboard($user)) {
            $stageRows = $this->level2CurrentWorkflowStageChartRows();

            $charts[] = [
                'key' => 'verification_l2_workflow_by_state',
                'title' => 'Current qualifications by workflow stage',
                'type' => 'doughnut',
                'labels' => array_keys($stageRows),
                'values' => array_values($stageRows),
            ];
        }

        if ($user->can('verification.pool.view')) {
            $poolSeries = [];
            foreach ($w['dates'] as $date) {
                if ($this->isLevel2ScopedDashboard($user)) {
                    $poolSeries[] = $this->applyWorkflowEntryDateRange(
                        $this->level2InWorkflowQualificationQuery(),
                        Carbon::parse($date)->startOfDay(),
                        Carbon::parse($date)->endOfDay()
                    )->count();
                } elseif ($this->isLevel1ScopedDashboard($user)) {
                    $poolSeries[] = $this->applyWorkflowEntryDateRange(
                        $this->qualificationsEverAssignedToQuery($user),
                        Carbon::parse($date)->startOfDay(),
                        Carbon::parse($date)->endOfDay()
                    )
                        ->distinct()
                        ->count('qualifications.id');
                } elseif (VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)) {
                    $poolSeries[] = $this->applyWorkflowEntryDateRange(
                        Qualification::query()
                            ->where('assigned_verifier_id', $user->id)
                            ->whereHas('application', fn ($q) => $q->whereIn('current_status', $this->poolStatuses())),
                        Carbon::parse($date)->startOfDay(),
                        Carbon::parse($date)->endOfDay()
                    )->count();
                } else {
                    $poolSeries[] = $this->applyWorkflowEntryDateRange(
                        $this->level2WorkflowQualificationQuery(),
                        Carbon::parse($date)->startOfDay(),
                        Carbon::parse($date)->endOfDay()
                    )->count();
                }
            }
            $charts[] = [
                'key' => 'verification_pool_submissions_week',
                'title' => $this->isLevel2ScopedDashboard($user)
                    ? 'Qualifications entering workflow by day ('.$rangeLabel.')'
                    : ($this->isLevel1ScopedDashboard($user)
                        ? 'Your assigned qualifications by workflow entry ('.$rangeLabel.')'
                        : (VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)
                            ? 'Your pool qualifications by workflow entry ('.$rangeLabel.')'
                            : 'Qualifications entering workflow by day ('.$rangeLabel.')')),
                'type' => 'line',
                'labels' => $w['labels'],
                'values' => $poolSeries,
            ];
        }

        if ($user->can('verification.level1.process') && ! $this->isLevel1ScopedDashboard($user)) {
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
                'title' => 'Your Level 1 completions ('.$rangeLabel.')',
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

        $hasContent = $kpis !== [] || $charts !== [] || $queues !== [] || $alerts !== [];

        return [
            'meta' => [
                'greeting_line' => $greeting.', '.$firstName,
                'subtitle' => $subtitle,
                'primary_role' => (string) $primaryRole,
                'current_date_formatted' => $now->translatedFormat('l, j F Y'),
                'timezone' => (string) config('app.timezone'),
                'dashboard_scope' => $this->isFinanceScopedDashboard($user)
                    ? 'finance'
                    : ($this->isLevel1ScopedDashboard($user)
                        ? 'level1_assigned'
                        : ($this->isLevel2ScopedDashboard($user) ? 'level2_qualifications' : 'default')),
                'date_range' => $range->toArray(),
                'workflow_entry_date_field' => 'qualifications.service_started_at, else applications.submitted_at, else qualifications.created_at',
                'l2_metrics_explainer' => $this->isLevel2ScopedDashboard($user)
                    ? 'Total qualifications and Processed use the selected date range. Queue cards show current workload by stage.'
                    : null,
            ],
            'kpis' => array_values($kpis),
            'charts' => array_values($charts),
            'queues' => array_values($queues),
            'quick_actions' => array_values($quickActions),
            'alerts' => array_values($alerts),
            'finance_breakdowns' => $financeBreakdowns,
            'empty' => ! $hasContent,
        ];
    }

    private function resolveSubtitle(User $user): string
    {
        if ($this->isLevel1ScopedDashboard($user)) {
            return 'Here are your assigned qualification tasks and what needs your attention today.';
        }
        if ($this->isLevel2ScopedDashboard($user)) {
            return 'Total qualifications and processed decisions reflect the selected period; queue cards show live workload by stage.';
        }
        if ($this->isFinanceScopedDashboard($user)) {
            return 'Here is your finance operations summary for the selected period.';
        }
        if ($user->can('verification.pool.view') || $user->can('verification.level1.process')) {
            return 'Here is what needs your attention today.';
        }
        if ($user->can('admin.finance.view') || $user->can('finance.payments.view')) {
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

        if ($this->isLevel1ScopedDashboard($user)) {
            $push('Assigned to me', '/admin/verification/assigned-to-me', 'user-check', 'verification.level1.process');
            $push('My performance', '/admin/reports/my-performance', 'activity', 'verification.level1.process');
        } elseif ($this->isLevel2ScopedDashboard($user)) {
            $push('Verification pool', '/admin/verification/pool', 'layers', 'verification.pool.view');
            $push('Assigned to me', '/admin/verification/assigned-to-me', 'user-check', 'verification.level2.review');
            $push('Auto-verified queue', '/admin/verification/auto-verified', 'shield', 'verification.level2.review');
        } elseif ($this->isFinanceScopedDashboard($user)) {
            $push('Payment proofs', '/admin/finance/payment-proofs', 'banknote', 'finance.payment_proofs.view');
            $push('Processed payments', '/admin/finance/payments', 'banknote', 'finance.payments.view');
            $push('Finance dashboard', '/admin/finance', 'banknote', 'finance.dashboard.view');
            $push('Finance reports', '/admin/reports/payments', 'activity', 'finance.reports.view');
        } else {
            $push('Applications pool', '/admin/verification/pool', 'layers', 'verification.pool.view');
            $push('Assigned to me', '/admin/verification/assigned-to-me', 'user-check', 'verification.level1.process');
            $push('Application outcomes', '/admin/applications', 'clipboard', 'admin.applications.view');
            $push('Track application', '/admin/applications/track', 'search', 'admin.applications.view');
        }
        if (! $this->isFinanceScopedDashboard($user)) {
            $push('Finance dashboard', '/admin/finance', 'banknote', 'finance.dashboard.view');
            $push('Payment proofs', '/admin/finance/payment-proofs', 'banknote', 'finance.payment_proofs.view');
            $push('Processed payments', '/admin/finance/payments', 'banknote', 'finance.payments.view');
        }
        $push('Manage users', '/admin/users', 'users', 'admin.users.view');
        $push('Applicants', '/admin/applicants', 'user', 'admin.applicants.view');
        $push('Roles & permissions', '/admin/roles', 'shield', 'admin.roles.view');
        $push('SLA performance', '/admin/reports/sla', 'activity', 'reports.view');
        $push('Certificates', '/admin/certificates', 'award', 'admin.certificates.view');
        $push('Countries', '/admin/settings/countries', 'globe', 'settings.countries.view');
        $push('Subjects', '/admin/settings/certificate-subjects', 'list', 'settings.certificate_subjects.view');
        $push('Awarding institutions', '/admin/settings/awarding-institutions', 'building', 'settings.awarding_institutions.view');
        $push('Qualification types', '/admin/settings/qualification-types', 'book', 'settings.qualification_types.view');
        $push('Fees', '/admin/settings/fees', 'coins', 'settings.fees.view');
        $push('Departments', '/admin/settings/departments', 'building-2', 'settings.departments.view');
        $push('SMS balance', '/admin/settings/sms/balance', 'message-square', 'sms.balance.view');
        $push('SMS logs', '/admin/settings/sms/logs', 'message-square', 'sms.logs.view');
    }

    /**
     * @param  list<array<string, mixed>>  $kpis
     * @param  list<array<string, mixed>>  $alerts
     */
    private function appendSmsDashboardWidgets(User $user, array &$kpis, array &$alerts): void
    {
        if (! $user->can('sms.balance.view') && ! $user->can('sms.balance.manage')) {
            return;
        }

        $account = SmsBalanceAccount::currentReadOnly();
        $balance = (int) $account->balance;
        $lowThreshold = (int) $account->low_balance_threshold;
        $criticalThreshold = (int) $account->critical_balance_threshold;

        $sentToday = SmsLog::query()
            ->where('status', 'sent')
            ->whereDate('sent_at', now()->toDateString())
            ->count();

        $failedToday = SmsLog::query()
            ->where('status', 'failed')
            ->whereDate('updated_at', now()->toDateString())
            ->count();

        $kpis[] = [
            'key' => 'sms_balance',
            'label' => 'Current SMS balance',
            'value' => $balance,
            'icon' => 'message-square',
            'hint' => 'Internal SMS units',
            'href' => '/admin/settings/sms/balance',
        ];

        $kpis[] = [
            'key' => 'sms_sent_today',
            'label' => 'SMS sent today',
            'value' => $sentToday,
            'icon' => 'check-circle',
            'hint' => 'Successful sends',
            'href' => '/admin/settings/sms/logs',
        ];

        $kpis[] = [
            'key' => 'sms_failed_today',
            'label' => 'SMS failed today',
            'value' => $failedToday,
            'icon' => 'alert',
            'hint' => 'Failed provider sends',
            'href' => '/admin/settings/sms/logs',
        ];

        if ($balance <= $criticalThreshold) {
            $alerts[] = [
                'key' => 'sms_balance_critical',
                'severity' => 'critical',
                'title' => 'SMS balance critically low',
                'message' => "Only {$balance} SMS remaining (critical threshold {$criticalThreshold}). Add credits immediately.",
                'href' => '/admin/settings/sms/balance',
            ];
        } elseif ($balance <= $lowThreshold) {
            $alerts[] = [
                'key' => 'sms_balance_low',
                'severity' => 'warning',
                'title' => 'SMS balance is low',
                'message' => "Only {$balance} SMS remaining (warning threshold {$lowThreshold}). Add credits to avoid notification failures.",
                'href' => '/admin/settings/sms/balance',
            ];
        }
    }

    /**
     * @return array{labels: list<string>, dates: list<string>}
     */
    private function rangeWindow(DashboardDateRange $range): array
    {
        $labels = [];
        $dates = [];
        $cursor = $range->from->copy()->startOfDay();
        $end = $range->to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $labels[] = $range->selected === 7
                ? $cursor->format('D')
                : $cursor->format('j M');
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return ['labels' => $labels, 'dates' => $dates];
    }

    /**
     * Workflow entry date: service_started_at, else application submitted_at, else created_at.
     *
     * @param  Builder<Qualification>  $query
     * @return Builder<Qualification>
     */
    private function applyWorkflowEntryDateRange(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->where(function (Builder $outer) use ($from, $to) {
            $outer->where(function (Builder $q) use ($from, $to) {
                $q->whereNotNull('qualifications.service_started_at')
                    ->whereBetween('qualifications.service_started_at', [$from, $to]);
            })->orWhere(function (Builder $q) use ($from, $to) {
                $q->whereNull('qualifications.service_started_at')
                    ->whereHas('application', function (Builder $aq) use ($from, $to) {
                        $aq->whereNotNull('submitted_at')
                            ->whereBetween('submitted_at', [$from, $to]);
                    });
            })->orWhere(function (Builder $q) use ($from, $to) {
                $q->whereNull('qualifications.service_started_at')
                    ->whereHas('application', function (Builder $aq) {
                        $aq->whereNull('submitted_at');
                    })
                    ->whereBetween('qualifications.created_at', [$from, $to]);
            });
        });
    }

    private function countLevel2ProcessedQualifications(Carbon $from, Carbon $to): int
    {
        return (int) AuditLog::query()
            ->where('entity_type', Qualification::class)
            ->whereIn('action_name', ['qualification_approved', 'qualification_rejected'])
            ->whereBetween('created_at', [$from, $to])
            ->distinct()
            ->count('entity_id');
    }

    /**
     * Qualifications currently with Level 1 (assignment or review).
     *
     * @return Builder<Qualification>
     */
    private function level2WithLevel1Query(): Builder
    {
        return $this->level2WorkflowQualificationQuery()
            ->whereIn('qualifications.verification_state', [
                VerificationState::AwaitingAutoVerification->value,
                VerificationState::AwaitingAssignment->value,
                VerificationState::AssignedToLevel1->value,
                VerificationState::UnderLevel1Review->value,
            ]);
    }

    /**
     * Qualifications actively owned or locked at Level 2.
     *
     * @return Builder<Qualification>
     */
    private function level2WithLevel2Query(): Builder
    {
        return $this->level2WorkflowQualificationQuery()
            ->where(function (Builder $q) {
                $q->where(function (Builder $manual) {
                    $manual->where('qualifications.verification_state', VerificationState::UnderLevel2Review->value)
                        ->whereNotNull('qualifications.level2_review_owner_id');
                })->orWhere(function (Builder $auto) {
                    $auto->where('qualifications.verification_state', VerificationState::AutoVerifiedPendingLevel2->value)
                        ->whereNotNull('qualifications.level2_review_locked_by');
                });
            });
    }

    /**
     * Level 1 pool items awaiting verifier assignment or pickup.
     *
     * @return Builder<Qualification>
     */
    private function level2UnassignedLevel1Query(): Builder
    {
        return $this->level2WorkflowQualificationQuery()
            ->where(function (Builder $q) {
                $q->where('qualifications.verification_state', VerificationState::AwaitingAssignment->value)
                    ->orWhere(function (Builder $assigned) {
                        $assigned->whereIn('qualifications.verification_state', [
                            VerificationState::AssignedToLevel1->value,
                            VerificationState::UnderLevel1Review->value,
                        ])->whereNull('qualifications.assigned_verifier_id');
                    });
            });
    }

    /**
     * Level 2-reviewable qualifications without an owner or active lock.
     *
     * @return Builder<Qualification>
     */
    private function level2UnassignedLevel2Query(): Builder
    {
        return $this->level2WorkflowQualificationQuery()
            ->where(function (Builder $q) {
                $q->where(function (Builder $manual) {
                    $manual->where('qualifications.verification_state', VerificationState::UnderLevel2Review->value)
                        ->whereNull('qualifications.level2_review_owner_id');
                })->orWhere(function (Builder $auto) {
                    $auto->where('qualifications.verification_state', VerificationState::AutoVerifiedPendingLevel2->value)
                        ->whereNull('qualifications.level2_review_locked_by');
                });
            });
    }

    /**
     * @return Builder<Qualification>
     */
    private function level2OverdueQualificationQuery(bool $isForeign): Builder
    {
        $query = $this->level2WorkflowQualificationQuery();
        $this->applyOpenQualificationSlaScope($query);
        $this->applyQualificationOverdueFilter($query, now());
        $query->where('qualifications.is_foreign_qualification', $isForeign);

        return $query;
    }

    /**
     * @return array<string, int>
     */
    private function level2CurrentWorkflowStageChartRows(): array
    {
        $rows = [
            'With Level 1' => $this->level2WithLevel1Query()->count(),
            'With Level 2' => $this->level2WithLevel2Query()->count(),
            'Unassigned Level 1' => $this->level2UnassignedLevel1Query()->count(),
            'Unassigned Level 2' => $this->level2UnassignedLevel2Query()->count(),
            'Auto-verified awaiting L2' => $this->level2AutoVerifiedAwaitingQuery()->count(),
        ];

        return array_filter($rows, fn (int $count) => $count > 0);
    }

    /**
     * Level 2 pending states: manual and auto-verified paths awaiting Level 2 action.
     *
     * @return Builder<Qualification>
     */
    private function level2PendingQuery(): Builder
    {
        return $this->level2WorkflowQualificationQuery()
            ->whereIn('qualifications.verification_state', [
                VerificationState::UnderLevel2Review->value,
                VerificationState::AutoVerifiedPendingLevel2->value,
            ]);
    }

    /**
     * Passed Level 1 — manual Level 2 review queue.
     *
     * @return Builder<Qualification>
     */
    private function level2ManualReviewQuery(): Builder
    {
        return $this->level2WorkflowQualificationQuery()
            ->where('qualifications.verification_state', VerificationState::UnderLevel2Review->value);
    }

    /**
     * @deprecated Use level2UnassignedLevel2Query()
     *
     * @return Builder<Qualification>
     */
    private function level2UnassignedQuery(): Builder
    {
        return $this->level2UnassignedLevel2Query();
    }

    /**
     * @return Builder<Qualification>
     */
    private function level2AutoVerifiedAwaitingQuery(): Builder
    {
        return $this->level2WorkflowQualificationQuery()
            ->where('qualifications.verification_state', VerificationState::AutoVerifiedPendingLevel2->value);
    }

    private function isLevel1ScopedDashboard(User $user): bool
    {
        return VerificationQualificationAccess::mustRestrictToAssignedQualifications($user);
    }

    /**
     * Level 2 verification officers see qualification-centric dashboard metrics (not application totals).
     */
    private function isLevel2ScopedDashboard(User $user): bool
    {
        if (! $user->can('verification.level2.review')) {
            return false;
        }

        return ! $user->hasRole('Super Admin');
    }

    /**
     * Finance officers see payment, invoice, and revenue metrics only — not verification workload.
     */
    private function isFinanceScopedDashboard(User $user): bool
    {
        if (! ($user->can('admin.finance.view') || $user->can('finance.payment_proofs.view') || $user->can('finance.payments.view'))) {
            return false;
        }

        return ! $user->can('verification.pool.view')
            && ! $user->can('verification.level1.process')
            && ! $user->can('verification.level2.review')
            && ! $user->can('admin.applications.view');
    }

    /**
     * Qualifications on submitted applications in the active verification pool.
     *
     * @return Builder<Qualification>
     */
    private function level2WorkflowQualificationQuery(): Builder
    {
        return Qualification::query()->whereHas(
            'application',
            fn (Builder $aq) => $aq->whereIn('current_status', $this->poolStatuses())
                ->whereNotNull('submitted_at')
        );
    }

    /**
     * Non-terminal qualifications currently in the verification workflow (Level 2 visible scope).
     *
     * @return Builder<Qualification>
     */
    private function level2InWorkflowQualificationQuery(): Builder
    {
        return $this->level2WorkflowQualificationQuery()
            ->where(function (Builder $q) {
                $q->whereNull('qualifications.verification_state')
                    ->orWhereIn('qualifications.verification_state', [
                        VerificationState::AwaitingAutoVerification->value,
                        VerificationState::AwaitingAssignment->value,
                        VerificationState::AssignedToLevel1->value,
                        VerificationState::UnderLevel1Review->value,
                        VerificationState::UnderLevel2Review->value,
                        VerificationState::AutoVerifiedPendingLevel2->value,
                    ]);
            });
    }

    /**
     * Level 2 tasks owned by the logged-in officer (explicit owner or active auto-verify lock).
     *
     * @return Builder<Qualification>
     */
    private function level2AssignedToUserQuery(User $user): Builder
    {
        return $this->level2WorkflowQualificationQuery()
            ->where(function (Builder $q) use ($user) {
                $q->where(function (Builder $owned) use ($user) {
                    $owned->where('qualifications.verification_state', VerificationState::UnderLevel2Review->value)
                        ->where('qualifications.level2_review_owner_id', $user->id);
                })->orWhere(function (Builder $locked) use ($user) {
                    $locked->where('qualifications.verification_state', VerificationState::AutoVerifiedPendingLevel2->value)
                        ->where('qualifications.level2_review_locked_by', $user->id);
                });
            });
    }

    /**
     * Qualifications that completed Level 1 (or auto-verification) and await Level 2 review.
     *
     * @return Builder<Qualification>
     */
    private function level2ReadyForReviewQuery(): Builder
    {
        return $this->level2PendingQuery();
    }

    /**
     * @param  Builder<Qualification>  $query
     */
    private function applyQualificationOverdueFilter(Builder $query, Carbon $cutoff): void
    {
        $query->where(function (Builder $deadline) use ($cutoff) {
            $deadline
                ->where(function (Builder $direct) use ($cutoff) {
                    $direct->whereNotNull('qualifications.service_deadline_at')
                        ->where('qualifications.service_deadline_at', '<', $cutoff);
                })
                ->orWhere(function (Builder $fallback) use ($cutoff) {
                    $fallback->whereNull('qualifications.service_deadline_at')
                        ->whereHas('application', function (Builder $application) use ($cutoff) {
                            $application->whereNotNull('service_deadline_at')
                                ->where('service_deadline_at', '<', $cutoff);
                        });
                });
        });
    }

    /**
     * @param  Builder<Qualification>  $query
     */
    private function applyOpenQualificationSlaScope(Builder $query): void
    {
        $query
            ->where(function (Builder $states) {
                $states->whereNull('qualifications.verification_state')
                    ->orWhereNotIn('qualifications.verification_state', QualificationSlaService::CLOSED_QUALIFICATION_STATES);
            })
            ->whereHas('application', function (Builder $application) {
                $application->whereNotIn('current_status', QualificationSlaService::CLOSED_APPLICATION_STATUSES);
            });
    }

    /**
     * @return list<array{title: string, subtitle: string, href: string|null}>
     */
    private function recentLevel2QualificationsQueue(int $limit): array
    {
        $rows = $this->level2InWorkflowQualificationQuery()
            ->with('application:id,application_number,submitted_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'application_id', 'verification_state', 'title_of_qualification', 'updated_at']);

        $out = [];
        foreach ($rows as $row) {
            $title = $row->title_of_qualification ?: ($row->application?->application_number ?? ('Qualification #'.$row->id));
            $out[] = [
                'title' => $title,
                'subtitle' => ($row->application?->application_number ?? '—')
                    .' · '.($row->verification_state?->value ?? '—'),
                'href' => '/admin/verification/qualifications/'.$row->id,
            ];
        }

        return $out;
    }

    /**
     * Qualifications this Level 1 officer has ever been assigned (current or historical).
     *
     * @return Builder<Qualification>
     */
    private function qualificationsEverAssignedToQuery(User $user): Builder
    {
        $assignmentQualificationIds = QualificationAssignment::query()
            ->where('assigned_to_user_id', $user->id)
            ->select('qualification_id');

        return Qualification::query()->where(function (Builder $q) use ($user, $assignmentQualificationIds) {
            $q->where('qualifications.assigned_verifier_id', $user->id)
                ->orWhereIn('qualifications.id', $assignmentQualificationIds);
        });
    }

    /**
     * @return list<array{title: string, subtitle: string, href: string|null}>
     */
    private function recentAssignedQualificationsQueue(User $user, int $limit): array
    {
        $rows = $this->qualificationsEverAssignedToQuery($user)
            ->join('applications', 'applications.id', '=', 'qualifications.application_id')
            ->whereNotNull('applications.submitted_at')
            ->with('application:id,application_number,submitted_at')
            ->orderByDesc('applications.submitted_at')
            ->limit($limit)
            ->get(['qualifications.id', 'qualifications.application_id', 'qualifications.verification_state', 'qualifications.title_of_qualification']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'title' => $row->application?->application_number ?? ('Qualification #'.$row->id),
                'subtitle' => 'Submitted '.optional($row->application?->submitted_at)->toDateTimeString(),
                'href' => '/admin/verification/qualifications/'.$row->id,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{title: string, subtitle: string, href: string|null}>
     */
    private function assignedToUserQualificationsQueue(User $user, int $limit): array
    {
        $rows = Qualification::query()
            ->where('assigned_verifier_id', $user->id)
            ->whereHas('application', fn ($q) => $q->whereIn('current_status', $this->poolStatuses()))
            ->with('application:id,application_number,current_status')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'application_id', 'verification_state', 'updated_at']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'title' => $row->application?->application_number ?? ('Qualification #'.$row->id),
                'subtitle' => 'Status: '.($row->verification_state?->value ?? '—'),
                'href' => '/admin/verification/qualifications/'.$row->id,
            ];
        }

        return $out;
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
     * Recent applications where at least one qualification is assigned to this verifier (Level 1 scoped users).
     *
     * @return list<array{title: string, subtitle: string, href: string|null}>
     */
    private function recentAssignedQualificationApplicationsQueue(User $user, int $limit): array
    {
        $rows = Application::query()
            ->whereHas('qualifications', fn ($q) => $q->where('assigned_verifier_id', $user->id))
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
    private function assignedToUserQueue(User $user, int $limit): array
    {
        $query = Application::query()
            ->whereIn('current_status', $this->poolStatuses())
            ->orderByDesc('updated_at')
            ->limit($limit);

        if (VerificationQualificationAccess::mustRestrictToAssignedQualifications($user)) {
            $query->whereHas('qualifications', fn ($q) => $q->where('assigned_verifier_id', $user->id));
        } else {
            $query->where('assigned_level1_user_id', $user->id);
        }

        $rows = $query->get(['id', 'application_number', 'current_status', 'updated_at']);

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
