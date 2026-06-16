<?php

namespace App\Domain\Applicant;

use App\Enums\ApplicationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ApplicantDashboardService
{
    /**
     * @return array{
     *   counts: array<string,int>,
     *   continue_draft: array<string,mixed>|null,
     *   applications: array<int,array<string,mixed>>,
     *   activity: array<int,array<string,mixed>>,
     *   alerts: array<int,array{type:string,title:string,message:string,application_id?:int|null,application_number?:string|null,href?:string|null}>,
     *   returned_qualifications: array<int,array{qualification_id:int,application_id:int,application_number:string|null,title_of_qualification:string|null,returned_to_applicant_at:string|null,href:string}>,
     *   returned_qualifications_count: int
     * }
     */
    public function build(User $user): array
    {
        $userId = $user->id;

        $countsByStatus = Application::query()
            ->where('applicant_user_id', $userId)
            ->select('current_status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('current_status')
            ->pluck('aggregate', 'current_status');

        $counts = [
            'total' => (int) $countsByStatus->sum(),
            'draft' => (int) ($countsByStatus[ApplicationStatus::Draft->value] ?? 0),
            'submitted' => (int) ($countsByStatus[ApplicationStatus::Submitted->value] ?? 0),
            'sent_back' => (int) ($countsByStatus[ApplicationStatus::SentBack->value] ?? 0),
            'approved' => (int) ($countsByStatus[ApplicationStatus::Approved->value] ?? 0),
            'rejected' => (int) ($countsByStatus[ApplicationStatus::Rejected->value] ?? 0),
        ];

        $continueDraft = Application::query()
            ->where('applicant_user_id', $userId)
            ->where('current_status', ApplicationStatus::Draft)
            ->latest('id')
            ->first();

        $appsForAlerts = Application::query()
            ->where('applicant_user_id', $userId)
            ->with([
                'qualifications:id,application_id,verification_state,returned_to_applicant_at',
                'invoice:id,application_id,invoice_number,amount_cents,currency,status',
                'payments:id,application_id,method,status,confirmed_at',
            ])
            ->latest('id')
            ->limit(12)
            ->get();

        $alerts = $this->alertsFor($appsForAlerts);
        $activity = $this->activityFor($user, $appsForAlerts);

        $returnedQualificationsCount = Qualification::query()
            ->where('verification_state', VerificationState::ReturnedToApplicant)
            ->whereHas('application', fn (Builder $q) => $q->where('applicant_user_id', $userId))
            ->count();

        $returnedQualifications = Qualification::query()
            ->where('verification_state', VerificationState::ReturnedToApplicant)
            ->whereHas('application', fn (Builder $q) => $q->where('applicant_user_id', $userId))
            ->with(['application:id,application_number'])
            ->orderByDesc('returned_to_applicant_at')
            ->limit(10)
            ->get()
            ->map(fn (Qualification $q) => [
                'qualification_id' => (int) $q->id,
                'application_id' => (int) $q->application_id,
                'application_number' => $q->application?->application_number,
                'title_of_qualification' => $q->title_of_qualification,
                'returned_to_applicant_at' => optional($q->returned_to_applicant_at)?->toIso8601String(),
                'href' => route('applicant.applications.qualifications.amend', ['application' => $q->application_id, 'qualification' => $q->id]),
            ])
            ->values()
            ->all();

        $recentApps = Application::query()
            ->where('applicant_user_id', $userId)
            ->with([
                'invoice',
                'payments',
            ])
            ->withCount('qualifications')
            ->withCount([
                'qualifications as returned_qualifications_count' => fn (Builder $q) => $q->where('verification_state', VerificationState::ReturnedToApplicant),
            ])
            ->latest('id')
            ->limit(5)
            ->get();

        $applications = $recentApps->map(function (Application $a) {
            $paymentsSorted = $a->payments->sortByDesc('id');
            $displayPayment = $paymentsSorted->first(fn ($p) => $p->status === PaymentStatus::Confirmed)
                ?? $paymentsSorted->first();

            $returnedCount = (int) ($a->returned_qualifications_count ?? 0);
            $amendAction = null;
            if ($returnedCount > 0) {
                $firstReturnedId = $a->qualifications()
                    ->where('verification_state', VerificationState::ReturnedToApplicant)
                    ->orderByDesc('returned_to_applicant_at')
                    ->value('id');

                if (is_int($firstReturnedId) || (is_string($firstReturnedId) && $firstReturnedId !== '')) {
                    $amendAction = [
                        'label' => $returnedCount === 1 ? 'Update qualification' : "Update {$returnedCount} qualifications",
                        'href' => route('applicant.applications.qualifications.amend', ['application' => $a->id, 'qualification' => $firstReturnedId]),
                        'kind' => 'amend',
                    ];
                }
            }

            return [
                'id' => $a->id,
                'application_number' => $a->application_number,
                'current_status' => $a->current_status?->value ?? (string) $a->current_status,
                'status_label' => $a->applicantStatusLabel(),
                'display_status_label' => $a->applicantDisplayStatusLabel(),
                'correction_required' => $returnedCount > 0,
                'is_foreign' => (bool) $a->is_foreign,
                'service_type' => $a->service_type?->value ?? (string) $a->service_type,
                'updated_at' => optional($a->updated_at)?->toIso8601String(),
                'created_at' => optional($a->created_at)?->toIso8601String(),
                'submitted_at' => optional($a->submitted_at)?->toIso8601String(),
                'qualification_count' => (int) ($a->qualifications_count ?? 0),
                'invoice' => $a->invoice
                    ? [
                        'invoice_number' => $a->invoice->invoice_number,
                        'amount_cents' => $a->invoice->amount_cents,
                        'currency' => $a->invoice->currency,
                        'status' => $a->invoice->status?->value ?? (string) $a->invoice->status,
                      ]
                    : null,
                'payment' => $displayPayment
                    ? [
                        'method' => $displayPayment->method?->value ?? (string) $displayPayment->method,
                        'status' => $displayPayment->status?->value ?? (string) $displayPayment->status,
                        'confirmed_at' => optional($displayPayment->confirmed_at)?->toIso8601String(),
                      ]
                    : null,
                'primary_action' => $this->primaryActionFor($a),
                'amend_action' => $amendAction,
                'returned_qualifications_count' => $returnedCount,
            ];
        })->values()->all();

        return [
            'counts' => $counts,
            'continue_draft' => $continueDraft ? $this->primaryActionFor($continueDraft) : null,
            'applications' => $applications,
            'activity' => $activity,
            'alerts' => $alerts,
            'returned_qualifications' => $returnedQualifications,
            'returned_qualifications_count' => $returnedQualificationsCount,
        ];
    }

    /**
     * @return array{label:string,href:string,kind:string}
     */
    private function primaryActionFor(Application $application): array
    {
        $status = $application->current_status?->value ?? (string) $application->current_status;

        if ($status === ApplicationStatus::Draft->value || $status === ApplicationStatus::SentBack->value) {
            return [
                'label' => $status === ApplicationStatus::SentBack->value ? 'Continue (sent back)' : 'Continue draft',
                'href' => route('applicant.applications.edit', $application),
                'kind' => 'continue',
            ];
        }

        return [
            'label' => 'View',
            'href' => route('applicant.applications.show', $application),
            'kind' => 'view',
        ];
    }

    /**
     * @param Collection<int,Application> $apps
     * @return array<int,array{type:string,title:string,message:string,application_id?:int|null,application_number?:string|null,href?:string|null}>
     */
    private function alertsFor(Collection $apps): array
    {
        $alerts = [];

        /** @var Application $app */
        foreach ($apps->take(12) as $app) {
            $status = $app->current_status?->value ?? (string) $app->current_status;

            $returned = $app->qualifications
                ->filter(fn ($q) => ($q->verification_state?->value ?? (string) $q->verification_state) === VerificationState::ReturnedToApplicant->value)
                ->sortByDesc(fn ($q) => optional($q->returned_to_applicant_at)?->getTimestamp() ?? 0)
                ->values();
            $returnedCount = $returned->count();

            if ($returnedCount > 0) {
                $firstReturned = $returned->first();

                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'Qualification update required',
                    'message' => $returnedCount === 1
                        ? "A qualification in application {$app->application_number} was returned for amendment."
                        : "{$returnedCount} qualifications in application {$app->application_number} were returned for amendment.",
                    'application_id' => $app->id,
                    'application_number' => $app->application_number,
                    'href' => $firstReturned
                        ? route('applicant.applications.qualifications.amend', ['application' => $app->id, 'qualification' => $firstReturned->id])
                        : route('applicant.applications.show', $app),
                ];
                continue;
            }

            if ($status === ApplicationStatus::SentBack->value) {
                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'Action required',
                    'message' => "Application {$app->application_number} was sent back for correction.",
                    'application_id' => $app->id,
                    'application_number' => $app->application_number,
                    'href' => route('applicant.applications.edit', $app),
                ];
                continue;
            }

            if ($status === ApplicationStatus::Draft->value) {
                $alerts[] = [
                    'type' => 'info',
                    'title' => 'Draft pending',
                    'message' => "Continue your draft application {$app->application_number}.",
                    'application_id' => $app->id,
                    'application_number' => $app->application_number,
                    'href' => route('applicant.applications.edit', $app),
                ];
                continue;
            }

            $invoiceStatus = $app->invoice?->status?->value ?? (string) ($app->invoice?->status ?? '');
            $paymentConfirmed = $invoiceStatus === InvoiceStatus::Paid->value
                || $app->payments->contains(fn ($p) => $p->status === PaymentStatus::Confirmed);

            if ($invoiceStatus !== '' && ! $paymentConfirmed) {
                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'Payment pending',
                    'message' => "Payment is not confirmed for {$app->application_number}.",
                    'application_id' => $app->id,
                    'application_number' => $app->application_number,
                    'href' => route('applicant.applications.edit', $app),
                ];
            }
        }

        return array_values(array_slice($alerts, 0, 5));
    }

    /**
     * @param Collection<int,Application> $apps
     * @return array<int,array<string,mixed>>
     */
    private function activityFor(User $user, Collection $apps): array
    {
        $ids = $apps->pluck('id')->values()->all();
        if (count($ids) === 0) {
            return [];
        }

        return ApplicationStatusHistory::query()
            ->whereIn('application_id', $ids, 'and', false)
            ->latest('changed_at')
            ->limit(12)
            ->get()
            ->map(function (ApplicationStatusHistory $h) {
                return [
                    'id' => $h->id,
                    'application_id' => $h->application_id,
                    'from_status' => $h->from_status,
                    'to_status' => $h->to_status,
                    'comment' => $h->comment,
                    'changed_at' => optional($h->changed_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
