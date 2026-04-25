<?php

namespace App\Http\Controllers\Admin;

use App\Models\Application;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminApplicationsTrackController extends Controller
{
    public function index(Request $request): Response
    {
        $id = $request->query('application_id');
        $application = null;

        if (is_string($id) && $id !== '') {
            /** @var Application $application */
            $application = Application::query()
                ->with([
                    'applicant',
                    'qualification',
                    'lifecycleEvents.actor',
                    'statusHistories.changedBy',
                ])
                ->findOrFail((int) $id);
        }

        return Inertia::render('Admin/Applications/Track', [
            'selected' => $application
                ? [
                    'id' => $application->id,
                    'application_number' => $application->application_number,
                    'current_status' => $application->current_status?->value ?? (string) $application->current_status,
                    'verification_state' => $application->verification_state?->value ?? null,
                    'submitted_at' => optional($application->submitted_at)?->toIso8601String(),
                    'applicant_name' => $application->metadata['verification_subject']['full_name'] ?? $application->applicant?->name,
                    'nrc_passport_number' => $application->qualification?->nrc_passport_number,
                    'qualification_title' => $application->qualification?->title_of_qualification,
                ]
                : null,
            'timeline' => $application
                ? $application->lifecycleEvents
                    ->sortByDesc('occurred_at')
                    ->take(80)
                    ->values()
                    ->map(fn ($e) => [
                        'id' => $e->id,
                        'title' => $e->title,
                        'description' => $e->description,
                        'stage' => $e->stage?->value ?? (string) $e->stage,
                        'visibility' => $e->visibility?->value ?? (string) $e->visibility,
                        'actor_name' => $e->actor_name_snapshot,
                        'occurred_at' => optional($e->occurred_at)?->toIso8601String(),
                    ])
                : [],
            'statuses' => $application
                ? $application->statusHistories
                    ->sortByDesc('changed_at')
                    ->values()
                    ->map(fn ($h) => [
                        'id' => $h->id,
                        'from_status' => $h->from_status,
                        'to_status' => $h->to_status,
                        'comment' => $h->comment,
                        'changed_at' => optional($h->changed_at)?->toIso8601String(),
                        'changed_by' => $h->changedBy?->name,
                    ])
                : [],
            'filters' => [
                'application_id' => $request->query('application_id'),
            ],
            'can' => [
                'view_verification' => (bool) $request->user()?->can('verification.pool.view'),
            ],
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 3) {
            return response()->json(['data' => []]);
        }

        $like = '%'.$term.'%';

        $apps = Application::query()
            ->with(['applicant', 'qualification'])
            ->where(function ($q) use ($like) {
                $q->where('applications.application_number', 'like', $like)
                    ->orWhereHas('qualification', fn ($qq) => $qq->where('nrc_passport_number', 'like', $like))
                    ->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(applications.metadata, "$.verification_subject.nrc_number")) like ?', [$like])
                    ->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(applications.metadata, "$.verification_subject.passport_number")) like ?', [$like]);
            })
            ->orderByDesc('applications.id')
            ->limit(8)
            ->get(['applications.*']);

        $data = $apps->map(function (Application $a) {
            $subject = $a->metadata['verification_subject'] ?? null;
            $subjectNrc = is_array($subject) ? (($subject['nrc_number'] ?? '') ?: ($subject['passport_number'] ?? '')) : '';

            return [
                'id' => $a->id,
                'application_number' => $a->application_number,
                'status' => $a->current_status?->value ?? (string) $a->current_status,
                'name' => $a->metadata['verification_subject']['full_name'] ?? $a->applicant?->name,
                'nrc_passport' => $a->qualification?->nrc_passport_number ?: $subjectNrc,
                'qualification_title' => $a->qualification?->title_of_qualification,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }
}

