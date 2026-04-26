<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Enums\ApplicationStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminApplicationsController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $finalStatuses = [
            ApplicationStatus::Approved,
            ApplicationStatus::Rejected,
            ApplicationStatus::CertificateReady,
            ApplicationStatus::Completed,
        ];

        $applications = Application::query()
            ->with([
                'applicant',
                'qualification',
                'invoice',
                'payments',
            ])
            ->whereIn('current_status', $finalStatuses)
            ->when($status !== '', function ($query) use ($status) {
                $allowed = [
                    ApplicationStatus::Approved->value,
                    ApplicationStatus::Rejected->value,
                    ApplicationStatus::CertificateReady->value,
                    ApplicationStatus::Completed->value,
                ];
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
                $latestPayment = $a->payments->sortByDesc('id')->first();

                return [
                    'id' => $a->id,
                    'application_number' => $a->application_number,
                    'current_status' => $a->current_status?->value ?? (string) $a->current_status,
                    'verification_state' => $a->verification_state?->value ?? null,
                    'updated_at' => optional($a->updated_at)?->toIso8601String(),
                    'applicant_name' => $a->metadata['verification_subject']['full_name'] ?? $a->applicant?->name,
                    'qualification' => $a->qualification
                        ? [
                            'holder_name' => $a->qualification->qualification_holder_name
                                ?: ($a->metadata['verification_subject']['full_name'] ?? null),
                            'holder_nrc_passport' => $a->qualification->nrc_passport_number
                                ?: (function () use ($a) {
                                    $subject = $a->metadata['verification_subject'] ?? null;
                                    if (! is_array($subject)) {
                                        return null;
                                    }

                                    return ($subject['nrc_number'] ?? null) ?: ($subject['passport_number'] ?? null);
                                })(),
                            'title' => $a->qualification->title_of_qualification,
                            'certificate_number' => $a->qualification->certificate_number,
                          ]
                        : null,
                    'invoice' => $a->invoice
                        ? [
                            'invoice_number' => $a->invoice->invoice_number,
                            'currency' => $a->invoice->currency,
                            'amount_cents' => $a->invoice->amount_cents,
                            'status' => $a->invoice->status?->value ?? (string) $a->invoice->status,
                          ]
                        : null,
                    'latest_payment' => $latestPayment
                        ? [
                            'method' => $latestPayment->method?->value ?? (string) $latestPayment->method,
                            'status' => $latestPayment->status?->value ?? (string) $latestPayment->status,
                            'currency' => $latestPayment->currency,
                            'amount_cents' => $latestPayment->amount_cents,
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
}

