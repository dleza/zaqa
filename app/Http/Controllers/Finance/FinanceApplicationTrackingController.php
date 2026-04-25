<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceApplicationTrackingController extends Controller
{
    public function show(Request $request, Application $application): Response
    {
        $user = $request->user();
        if (! $user || ! $user->can('admin.finance.view')) {
            abort(403);
        }

        $application->loadMissing([
            'lifecycleEvents.actor',
            'statusHistories.changedBy',
            'invoice',
            'payments',
            'qualification.qualificationTypeMaster',
        ]);

        $events = $application->lifecycleEvents
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
                'actor_user_id' => $e->actor_user_id,
                'visibility' => $e->visibility?->value ?? (string) $e->visibility,
                'metadata' => (array) ($e->metadata ?? []),
            ]);

        $statusHistory = $application->statusHistories
            ->sortByDesc('changed_at')
            ->values()
            ->map(fn ($h) => [
                'id' => $h->id,
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'comment' => $h->comment,
                'changed_at' => optional($h->changed_at)?->toIso8601String(),
                'actor_name' => $h->changedBy?->name,
            ]);

        return Inertia::render('Finance/Applications/Track', [
            'application' => [
                'id' => $application->id,
                'application_number' => $application->application_number,
                'current_status' => $application->current_status?->value ?? (string) $application->current_status,
                'status_label' => $application->applicantStatusLabel(),
                'is_foreign' => (bool) $application->is_foreign,
                'qualification_type' => $application->qualification?->qualificationTypeMaster
                    ? [
                        'level_label' => $application->qualification->qualificationTypeMaster->level_label,
                        'name' => $application->qualification->qualificationTypeMaster->name,
                      ]
                    : null,
            ],
            'events' => $events,
            'statusHistories' => $statusHistory,
        ]);
    }
}

