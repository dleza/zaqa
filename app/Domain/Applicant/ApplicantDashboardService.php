<?php

namespace App\Domain\Applicant;

use App\Enums\ApplicationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\User;
use Illuminate\Support\Collection;

class ApplicantDashboardService
{
    /**
     * @return array{
     *   counts: array<string,int>,
     *   continue_draft: array<string,mixed>|null,
     *   applications: array<int,array<string,mixed>>,
     *   activity: array<int,array<string,mixed>>,
     *   alerts: array<int,array{type:string,title:string,message:string,application_id?:int|null,application_number?:string|null,href?:string|null}>
     * }
     */
    public function build(User $user): array
    {
        $apps = Application::query()
            ->where('applicant_user_id', $user->id)
            ->with([
                'qualifications.qualificationTypeMaster',
                'invoice',
                'payments',
            ])
            ->latest('id')
            ->get();

        $counts = [
            'total' => $apps->count(),
            'draft' => $apps->where('current_status', ApplicationStatus::Draft)->count(),
            'submitted' => $apps->where('current_status', ApplicationStatus::Submitted)->count(),
            'sent_back' => $apps->where('current_status', ApplicationStatus::SentBack)->count(),
            'approved' => $apps->where('current_status', ApplicationStatus::Approved)->count(),
            'rejected' => $apps->where('current_status', ApplicationStatus::Rejected)->count(),
        ];

        $applications = $apps->map(function (Application $a) {
            $paymentsSorted = $a->payments->sortByDesc('id');
            $displayPayment = $paymentsSorted->first(fn ($p) => $p->status === PaymentStatus::Confirmed)
                ?? $paymentsSorted->first();

            $quals = $a->qualifications
                ->sortBy('id')
                ->values()
                ->map(fn ($q) => [
                    'id' => $q->id,
                    'title_of_qualification' => $q->title_of_qualification,
                    'verification_state' => $q->verification_state?->value ?? (string) $q->verification_state,
                    'is_foreign_qualification' => (bool) ($q->is_foreign_qualification ?? false),
                    'qualification_type' => $q->qualificationTypeMaster
                        ? [
                            'level_label' => $q->qualificationTypeMaster->level_label,
                            'name' => $q->qualificationTypeMaster->name,
                          ]
                        : null,
                ])
                ->all();

            $firstQualificationType = $a->qualifications->first()?->qualificationTypeMaster;

            return [
                'id' => $a->id,
                'application_number' => $a->application_number,
                'current_status' => $a->current_status?->value ?? (string) $a->current_status,
                'status_label' => $a->applicantStatusLabel(),
                'is_foreign' => (bool) $a->is_foreign,
                'service_type' => $a->service_type?->value ?? (string) $a->service_type,
                'updated_at' => optional($a->updated_at)?->toIso8601String(),
                'created_at' => optional($a->created_at)?->toIso8601String(),
                'submitted_at' => optional($a->submitted_at)?->toIso8601String(),
                'qualification_type' => $firstQualificationType
                    ? ['level_label' => $firstQualificationType->level_label, 'name' => $firstQualificationType->name]
                    : null,
                'qualifications' => $quals,
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
            ];
        })->values()->all();

        $continueDraft = $apps
            ->first(fn (Application $a) => ($a->current_status?->value ?? (string) $a->current_status) === ApplicationStatus::Draft->value);

        $alerts = $this->alertsFor($apps);

        $activity = $this->activityFor($user, $apps);

        return [
            'counts' => $counts,
            'continue_draft' => $continueDraft ? $this->primaryActionFor($continueDraft) : null,
            'applications' => $applications,
            'activity' => $activity,
            'alerts' => $alerts,
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
