<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Qualification;
use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminApplicationsController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $terminalQualificationStates = $this->terminalQualificationStates();
        $finalStatuses = $this->finalApplicationStatuses();

        $applications = Application::query()
            ->with([
                'applicant:id,name',
                'qualifications' => fn ($query) => $query
                    ->select('id', 'application_id', 'qualification_holder_name', 'title_of_qualification', 'verification_state')
                    ->orderBy('id'),
                'invoice',
                'payments',
            ])
            ->withCount([
                'qualifications',
                'qualifications as terminal_qualifications_count' => fn ($query) => $query->whereIn('verification_state', $terminalQualificationStates),
                'qualifications as approved_qualifications_count' => fn ($query) => $query->whereIn('verification_state', [
                    VerificationState::ApprovedForCertificate->value,
                    VerificationState::CertificateIssued->value,
                    VerificationState::Closed->value,
                ]),
                'qualifications as rejected_qualifications_count' => fn ($query) => $query->where('verification_state', VerificationState::Rejected->value),
            ])
            ->whereNotNull('submitted_at')
            ->whereHas('qualifications')
            ->whereDoesntHave('qualifications', function ($query) use ($terminalQualificationStates) {
                $query->whereNull('verification_state')
                    ->orWhereNotIn('verification_state', $terminalQualificationStates);
            })
            ->when($status !== '', function ($query) use ($status, $finalStatuses) {
                $allowed = array_map(fn (ApplicationStatus $appStatus) => $appStatus->value, $finalStatuses);
                if (in_array($status, $allowed, true)) {
                    $query->where('current_status', $status);
                }
            })
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner
                        ->where('application_number', 'like', '%'.$q.'%')
                        ->orWhere('metadata->verification_subject->full_name', 'like', '%'.$q.'%')
                        ->orWhereHas('qualification', function ($qq) use ($q) {
                            $qq->where('qualification_holder_name', 'like', '%'.$q.'%')
                                ->orWhere('title_of_qualification', 'like', '%'.$q.'%')
                                ->orWhere('certificate_number', 'like', '%'.$q.'%')
                                ->orWhere('student_number', 'like', '%'.$q.'%')
                                ->orWhere('examination_number', 'like', '%'.$q.'%');
                        })
                        ->orWhereHas('invoice', fn ($inv) => $inv->where('invoice_number', 'like', '%'.$q.'%'));
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (Application $a) {
                $paymentsSorted = $a->payments->sortByDesc('id');
                $displayPayment = $paymentsSorted->first(fn ($p) => $p->status === PaymentStatus::Confirmed)
                    ?? $paymentsSorted->first();

                $holderNames = $a->qualifications
                    ->pluck('qualification_holder_name')
                    ->filter(fn ($name) => is_string($name) && trim($name) !== '')
                    ->map(fn ($name) => trim((string) $name))
                    ->unique()
                    ->values();

                $qualificationTitles = $a->qualifications
                    ->pluck('title_of_qualification')
                    ->filter(fn ($title) => is_string($title) && trim($title) !== '')
                    ->map(fn ($title) => trim((string) $title))
                    ->unique()
                    ->values();

                return [
                    'id' => $a->id,
                    'application_number' => $a->application_number,
                    'current_status' => $a->current_status?->value ?? (string) $a->current_status,
                    'verification_state' => $a->verification_state?->value ?? null,
                    'submitted_at' => optional($a->submitted_at)?->toIso8601String(),
                    'updated_at' => optional($a->updated_at)?->toIso8601String(),
                    'applicant_name' => $a->metadata['verification_subject']['full_name'] ?? $a->applicant?->name,
                    'qualification_count' => (int) ($a->qualifications_count ?? $a->qualifications->count()),
                    'terminal_qualification_count' => (int) ($a->terminal_qualifications_count ?? 0),
                    'approved_qualification_count' => (int) ($a->approved_qualifications_count ?? 0),
                    'rejected_qualification_count' => (int) ($a->rejected_qualifications_count ?? 0),
                    'holder_names' => $holderNames->take(3)->all(),
                    'holder_names_more_count' => max(0, $holderNames->count() - 3),
                    'qualification_titles' => $qualificationTitles->take(3)->all(),
                    'qualification_titles_more_count' => max(0, $qualificationTitles->count() - 3),
                    'invoice' => $a->invoice
                        ? [
                            'invoice_number' => $a->invoice->invoice_number,
                            'currency' => $a->invoice->currency,
                            'amount_cents' => $a->invoice->amount_cents,
                            'status' => $a->invoice->status?->value ?? (string) $a->invoice->status,
                          ]
                        : null,
                    'latest_payment' => $displayPayment
                        ? [
                            'method' => $displayPayment->method?->value ?? (string) $displayPayment->method,
                            'status' => $displayPayment->status?->value ?? (string) $displayPayment->status,
                            'currency' => $displayPayment->currency,
                            'amount_cents' => $displayPayment->amount_cents,
                          ]
                        : null,
                ];
            });

        return Inertia::render('Admin/Applications/Index', [
            'applications' => $applications,
            'filters' => [
                'q' => $q,
                'status' => $status !== '' ? $status : null,
            ],
            'can' => [
                'finance_view' => (bool) $request->user()?->can('admin.finance.view'),
            ],
        ]);
    }

    public function qualifications(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $terminalQualificationStates = $this->terminalQualificationStates();

        $qualifications = Qualification::query()
            ->with([
                'application:id,application_number,current_status,submitted_at',
                'awardingInstitution:id,name',
                'qualificationTypeMaster:id,name',
            ])
            ->whereIn('verification_state', $terminalQualificationStates)
            ->whereHas('application', fn ($query) => $query->whereNotNull('submitted_at'))
            ->when($status !== '', function ($query) use ($status, $terminalQualificationStates) {
                if (in_array($status, $terminalQualificationStates, true)) {
                    $query->where('verification_state', $status);
                }
            })
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';

                $query->where(function ($inner) use ($like) {
                    $inner
                        ->where('verification_reference_number', 'like', $like)
                        ->orWhere('qualification_holder_name', 'like', $like)
                        ->orWhere('title_of_qualification', 'like', $like)
                        ->orWhere('certificate_number', 'like', $like)
                        ->orWhere('student_number', 'like', $like)
                        ->orWhere('examination_number', 'like', $like)
                        ->orWhereHas('application', fn ($app) => $app->where('application_number', 'like', $like))
                        ->orWhereHas('awardingInstitution', fn ($institution) => $institution->where('name', 'like', $like));
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (Qualification $qualification) {
                $application = $qualification->application;

                return [
                    'id' => $qualification->id,
                    'application_id' => $application?->id,
                    'application_number' => $application?->application_number,
                    'application_status' => $application?->current_status?->value ?? ($application?->current_status ? (string) $application->current_status : null),
                    'verification_reference_number' => $qualification->verification_reference_number,
                    'holder_name' => $qualification->qualification_holder_name,
                    'title' => $qualification->title_of_qualification,
                    'qualification_type' => $qualification->qualificationTypeMaster?->name ?: ($qualification->qualification_type ?: null),
                    'awarding_institution' => $qualification->awardingInstitution?->name
                        ?: $qualification->awarding_institution_name_other
                        ?: $qualification->awarding_institution_name,
                    'verification_state' => $qualification->verification_state?->value ?? null,
                    'award_date' => optional($qualification->award_date)?->toDateString(),
                    'updated_at' => optional($qualification->updated_at)?->toIso8601String(),
                ];
            });

        return Inertia::render('Admin/Applications/Qualifications', [
            'qualifications' => $qualifications,
            'filters' => [
                'q' => $q,
                'status' => $status !== '' ? $status : null,
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function terminalQualificationStates(): array
    {
        return [
            VerificationState::ApprovedForCertificate->value,
            VerificationState::Rejected->value,
            VerificationState::CertificateIssued->value,
            VerificationState::Closed->value,
        ];
    }

    /**
     * @return list<ApplicationStatus>
     */
    private function finalApplicationStatuses(): array
    {
        return [
            ApplicationStatus::Approved,
            ApplicationStatus::Rejected,
            ApplicationStatus::CertificateReady,
            ApplicationStatus::Completed,
        ];
    }
}
