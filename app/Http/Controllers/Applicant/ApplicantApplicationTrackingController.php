<?php

namespace App\Http\Controllers\Applicant;

use App\Enums\LifecycleVisibility;
use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApplicantApplicationTrackingController extends Controller
{
    public function show(Request $request, Application $application): Response
    {
        $this->authorize('view', $application);

        $application->loadMissing([
            'lifecycleEvents.actor',
            'statusHistories',
            'invoice',
            'payments',
        ]);

        $events = $application->lifecycleEvents
            ->filter(fn ($e) => in_array($e->visibility, [LifecycleVisibility::Applicant, LifecycleVisibility::Both], true))
            ->sortByDesc('occurred_at')
            ->values()
            ->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'event_code' => $e->event_code,
                'stage' => $e->stage?->value ?? (string) $e->stage,
                'status_snapshot' => $e->status_snapshot,
                'title' => $e->title,
                'description' => $e->description,
                'comment' => $e->comment,
                'occurred_at' => optional($e->occurred_at)?->toIso8601String(),
                'actor_name' => $e->actor_name_snapshot,
                'visibility' => $e->visibility?->value ?? (string) $e->visibility,
                'metadata' => (array) ($e->metadata ?? []),
            ]);

        $statusHistoryFallback = $application->statusHistories
            ->sortByDesc('changed_at')
            ->values()
            ->map(fn ($h) => [
                'id' => $h->id,
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'comment' => $h->comment,
                'changed_at' => optional($h->changed_at)?->toIso8601String(),
            ]);

        $latestPayment = $application->payments->sortByDesc('id')->first();

        return Inertia::render('Applicant/Applications/Track', [
            'application' => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'current_status' => $application->current_status?->value ?? (string) $application->current_status,
                'status_label' => $application->applicantStatusLabel(),
                'is_foreign' => (bool) $application->is_foreign,
                'created_at' => optional($application->created_at)?->toIso8601String(),
                'submitted_at' => optional($application->submitted_at)?->toIso8601String(),
                'service_deadline_at' => optional($application->service_deadline_at)?->toIso8601String(),
                'invoice' => $application->invoice
                    ? [
                        'invoice_number' => $application->invoice->invoice_number,
                        'amount_cents' => $application->invoice->amount_cents,
                        'currency' => $application->invoice->currency,
                        'status' => $application->invoice->status?->value ?? (string) $application->invoice->status,
                        'issued_at' => optional($application->invoice->issued_at)?->toIso8601String(),
                        'paid_at' => optional($application->invoice->paid_at)?->toIso8601String(),
                      ]
                    : null,
                'payment' => $latestPayment
                    ? [
                        'method' => $latestPayment->method?->value ?? (string) $latestPayment->method,
                        'status' => $latestPayment->status?->value ?? (string) $latestPayment->status,
                        'confirmed_at' => optional($latestPayment->confirmed_at)?->toIso8601String(),
                      ]
                    : null,
            ],
            'events' => $events,
            'statusHistoryFallback' => $statusHistoryFallback,
        ]);
    }

    public function summary(Request $request, Application $application): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $application);

        $application->loadMissing(['lifecycleEvents.actor', 'statusHistories']);

        $events = $application->lifecycleEvents
            ->filter(fn ($e) => in_array($e->visibility, [LifecycleVisibility::Applicant, LifecycleVisibility::Both], true))
            ->sortByDesc('occurred_at')
            ->take(12)
            ->values()
            ->map(fn ($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'description' => $e->description,
                'comment' => $e->comment,
                'occurred_at' => optional($e->occurred_at)?->toIso8601String(),
                'event_code' => $e->event_code,
            ]);

        if ($events->count() === 0) {
            $events = $application->statusHistories
                ->sortByDesc('changed_at')
                ->take(12)
                ->values()
                ->map(fn ($h) => [
                    'id' => 'status-'.$h->id,
                    'title' => $h->from_status ? "{$h->from_status} → {$h->to_status}" : "Status: {$h->to_status}",
                    'description' => $h->comment,
                    'comment' => null,
                    'occurred_at' => optional($h->changed_at)?->toIso8601String(),
                    'event_code' => 'status.fallback',
                ]);
        }

        return response()->json([
            'application' => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'status_label' => $application->applicantStatusLabel(),
                'current_status' => $application->current_status?->value ?? (string) $application->current_status,
            ],
            'events' => $events,
        ]);
    }
}

