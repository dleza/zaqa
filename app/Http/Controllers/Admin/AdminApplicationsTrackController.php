<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VerificationState;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Qualification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
                    'qualifications.assignedVerifier',
                    'qualifications.awardingInstitution',
                    'qualifications.qualificationTypeMaster',
                    'lifecycleEvents.actor',
                    'statusHistories.changedBy',
                ])
                ->findOrFail((int) $id);
        }

        $canViewVerification = (bool) $request->user()?->can('verification.pool.view');

        $timeline = $application
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
            : collect();

        $statuses = $application
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
            : collect();

        $activityFeed = $application ? $this->buildActivityFeed($timeline, $statuses) : [];

        $qualifications = $application
            ? $application->qualifications
                ->sortByDesc(fn (Qualification $q) => $q->updated_at?->timestamp ?? 0)
                ->values()
                ->map(function (Qualification $q) use ($canViewVerification) {
                    $state = $q->verification_state;

                    return [
                        'id' => $q->id,
                        'verification_reference_number' => $q->verification_reference_number,
                        'title_of_qualification' => $q->title_of_qualification,
                        'verification_state' => $state?->value,
                        'verification_state_label' => $this->verificationStateLabel($state),
                        'assigned_verifier_name' => $q->assignedVerifier?->name,
                        'qualification_type_label' => $q->qualificationTypeMaster?->name ?? ($q->qualification_type !== null && $q->qualification_type !== '' ? (string) $q->qualification_type : null),
                        'awarding_label' => $q->awardingInstitution?->name
                            ?? $q->awarding_institution_name_other
                            ?? $q->awarding_institution_name,
                        'updated_at' => optional($q->updated_at)?->toIso8601String(),
                        'verification_url' => $canViewVerification
                            ? route('admin.verification.qualifications.edit', $q)
                            : null,
                    ];
                })
                ->all()
            : [];

        $lastActivityAt = $this->resolveLastActivityAt($activityFeed, $application);

        return Inertia::render('Admin/Applications/Track', [
            'selected' => $application
                ? [
                    'id' => $application->id,
                    'application_number' => $application->application_number,
                    'current_status' => $application->current_status?->value ?? (string) $application->current_status,
                    'current_status_label' => $this->applicationStatusLabel($application->current_status?->value ?? (string) $application->current_status),
                    'verification_state' => $application->verification_state?->value ?? null,
                    'verification_state_label' => $this->verificationStateLabel($application->verification_state),
                    'submitted_at' => optional($application->submitted_at)?->toIso8601String(),
                    'paid_at' => optional($application->paid_at)?->toIso8601String(),
                    'completed_at' => optional($application->completed_at)?->toIso8601String(),
                    'service_deadline_at' => optional($application->service_deadline_at)?->toIso8601String(),
                    'created_at' => optional($application->created_at)?->toIso8601String(),
                    'updated_at' => optional($application->updated_at)?->toIso8601String(),
                    'applicant_name' => $application->metadata['verification_subject']['full_name'] ?? $application->applicant?->name,
                    'nrc_passport_number' => $application->qualification?->nrc_passport_number,
                    'qualification_title' => $application->qualification?->title_of_qualification,
                    'qualification_count' => $application->qualifications->count(),
                    'last_activity_at' => $lastActivityAt,
                ]
                : null,
            'statuses' => $statuses,
            'activity_feed' => $activityFeed,
            'qualifications' => $qualifications,
            'filters' => [
                'application_id' => $request->query('application_id'),
            ],
            'can' => [
                'view_verification' => $canViewVerification,
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

    /**
     * @param  Collection<int, array<string, mixed>>  $timeline
     * @param  Collection<int, array<string, mixed>>  $statuses
     * @return list<array{kind: string, id: string, at: string, title: string, body: string|null, meta: string}>
     */
    private function buildActivityFeed(Collection $timeline, Collection $statuses): array
    {
        $rows = collect();

        foreach ($timeline as $e) {
            $at = $e['occurred_at'] ?? null;
            if (! is_string($at) || $at === '') {
                continue;
            }
            $rows->push([
                'kind' => 'lifecycle',
                'id' => 'le-'.((string) ($e['id'] ?? '0')),
                'at' => $at,
                'title' => (string) ($e['title'] ?? 'Milestone'),
                'body' => isset($e['description']) && is_string($e['description']) && $e['description'] !== '' ? $e['description'] : null,
                'meta' => $this->formatLifecycleMeta($e),
            ]);
        }

        foreach ($statuses as $h) {
            $at = $h['changed_at'] ?? null;
            if (! is_string($at) || $at === '') {
                continue;
            }
            $from = isset($h['from_status']) && is_string($h['from_status']) && $h['from_status'] !== '' ? $this->applicationStatusLabel($h['from_status']) : '—';
            $to = isset($h['to_status']) && is_string($h['to_status']) && $h['to_status'] !== '' ? $this->applicationStatusLabel($h['to_status']) : '—';
            $actor = isset($h['changed_by']) && is_string($h['changed_by']) && $h['changed_by'] !== '' ? $h['changed_by'] : 'System';

            $rows->push([
                'kind' => 'status',
                'id' => 'sh-'.((string) ($h['id'] ?? '0')),
                'at' => $at,
                'title' => 'Application status',
                'body' => isset($h['comment']) && is_string($h['comment']) && $h['comment'] !== '' ? $h['comment'] : null,
                'meta' => "{$from} → {$to} · {$actor}",
            ]);
        }

        return $rows->sortByDesc('at')->values()->take(120)->values()->all();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function formatLifecycleMeta(array $event): string
    {
        $parts = [];
        $stage = $event['stage'] ?? null;
        if (is_string($stage) && $stage !== '') {
            $parts[] = str_replace('_', ' ', $stage);
        }
        $visibility = $event['visibility'] ?? null;
        if (is_string($visibility) && $visibility !== '') {
            $parts[] = $visibility;
        }
        $actor = $event['actor_name'] ?? null;
        if (is_string($actor) && $actor !== '') {
            $parts[] = $actor;
        }

        return $parts !== [] ? implode(' · ', $parts) : 'Recorded';
    }

    /**
     * @param  list<array{at: string}>  $feed
     */
    private function resolveLastActivityAt(array $feed, ?Application $application): ?string
    {
        if ($feed !== []) {
            return $feed[0]['at'] ?? null;
        }

        return $application ? optional($application->updated_at)?->toIso8601String() : null;
    }

    private function verificationStateLabel(?VerificationState $state): string
    {
        if ($state === null) {
            return '—';
        }

        return match ($state) {
            VerificationState::AwaitingAssignment => 'Awaiting assignment',
            VerificationState::AssignedToLevel1 => 'Assigned to Level 1',
            VerificationState::UnderLevel1Review => 'Under Level 1 review',
            VerificationState::UnderLevel2Review => 'Under Level 2 review',
            VerificationState::ReturnedToApplicant => 'Returned to applicant',
            VerificationState::ApprovedForCertificate => 'Approved for certificate',
            VerificationState::Rejected => 'Rejected',
            VerificationState::CertificateIssued => 'Certificate issued',
            VerificationState::Closed => 'Closed',
        };
    }

    private function applicationStatusLabel(string $value): string
    {
        if ($value === '') {
            return '—';
        }

        return match ($value) {
            'draft' => 'Draft',
            'pending_payment' => 'Pending payment',
            'submitted' => 'Submitted',
            'in_progress' => 'In progress',
            'sent_back' => 'Sent back',
            'resubmitted' => 'Resubmitted',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'certificate_ready' => 'Certificate ready',
            'completed' => 'Completed',
            default => ucfirst(str_replace('_', ' ', $value)),
        };
    }
}
