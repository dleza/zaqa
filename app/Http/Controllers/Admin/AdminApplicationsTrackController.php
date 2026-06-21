<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VerificationState;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Qualification;
use App\Support\Search\ReferenceSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AdminApplicationsTrackController extends Controller
{
    public function index(Request $request): Response
    {
        $applicationReference = (string) $request->query('application_reference', '');
        $qualificationReference = (string) $request->query('qualification_reference', '');
        $id = $request->query('application_id');
        $application = null;

        $appRef = ReferenceSearch::normalize($applicationReference);
        $qualRef = ReferenceSearch::normalize($qualificationReference);
        $canViewVerification = (bool) $request->user()?->can('verification.pool.view');

        $searchPerformed = false;
        $searchResults = [];
        $searchError = null;

        if ($id === null || $id === '') {
            if ($applicationReference !== '' || $qualificationReference !== '') {
                $searchPerformed = true;

                if (! ReferenceSearch::isUsablePrefix($appRef) && ! ReferenceSearch::isUsablePrefix($qualRef)) {
                    $searchError = 'Enter at least three characters in the application reference or qualification reference field.';
                } else {
                    $searchResults = $this->searchByReferences($appRef, $qualRef, $canViewVerification);
                }
            }
        }

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
                        'names_as_on_qualification_document' => $q->names_as_on_qualification_document,
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
            'search' => [
                'performed' => $searchPerformed,
                'results' => $searchResults,
                'error' => $searchError,
            ],
            'filters' => [
                'application_id' => $request->query('application_id'),
                'application_reference' => $applicationReference,
                'qualification_reference' => $qualificationReference,
            ],
            'can' => [
                'view_verification' => $canViewVerification,
            ],
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $appRef = ReferenceSearch::normalize((string) $request->query('application_reference', ''));
        $qualRef = ReferenceSearch::normalize((string) $request->query('qualification_reference', ''));

        if (! ReferenceSearch::isUsablePrefix($appRef) && ! ReferenceSearch::isUsablePrefix($qualRef)) {
            return response()->json(['data' => []]);
        }

        $canViewVerification = (bool) $request->user()?->can('verification.pool.view');

        return response()->json([
            'data' => $this->searchByReferences($appRef, $qualRef, $canViewVerification),
        ]);
    }

    /**
     * @return list<array{
     *   id: int,
     *   application_number: string,
     *   status: string,
     *   status_label: string,
     *   applicant_name: string|null,
     *   submitted_at: string|null,
     *   qualification_count: int,
     *   matched_qualification_id: int|null,
     *   matched_qualification_reference: string|null,
     *   matched_qualification_title: string|null,
     *   view_url: string|null,
     *   view_label: string
     * }>
     */
    private function searchByReferences(?string $appRef, ?string $qualRef, bool $canViewVerification): array
    {
        $query = Application::query()
            ->with([
                'applicant:id,name',
                'qualification:id,application_id,verification_reference_number,title_of_qualification',
                'qualifications:id,application_id,verification_reference_number,title_of_qualification',
            ]);

        if (ReferenceSearch::isUsablePrefix($appRef)) {
            ReferenceSearch::applyApplicationReference($query, $appRef);
        }

        if (ReferenceSearch::isUsablePrefix($qualRef)) {
            $query->where(function ($inner) use ($qualRef) {
                $inner->whereHas('qualifications', fn ($qq) => ReferenceSearch::applyQualificationReference($qq, $qualRef))
                    ->orWhereHas('qualification', fn ($qq) => ReferenceSearch::applyQualificationReference($qq, $qualRef));
            });
        }

        return $query
            ->orderByDesc('applications.id')
            ->limit(25)
            ->get(['applications.*'])
            ->map(fn (Application $application) => $this->mapSearchResultRow($application, $qualRef, $canViewVerification))
            ->values()
            ->all();
    }

    private function mapSearchResultRow(Application $application, ?string $qualRef, bool $canViewVerification): array
    {
        $matchedQualification = null;
        if (ReferenceSearch::isUsablePrefix($qualRef)) {
            $matchedQualification = $application->qualifications
                ->first(fn (Qualification $qualification) => $this->qualificationMatchesReference($qualification, $qualRef))
                ?? ($application->qualification && $this->qualificationMatchesReference($application->qualification, $qualRef)
                    ? $application->qualification
                    : null);
        }

        $status = $application->current_status?->value ?? (string) $application->current_status;

        return [
            'id' => $application->id,
            'application_number' => $application->application_number,
            'status' => $status,
            'status_label' => $this->applicationStatusLabel($status),
            'applicant_name' => $application->metadata['verification_subject']['full_name'] ?? $application->applicant?->name,
            'submitted_at' => optional($application->submitted_at)?->toIso8601String(),
            'qualification_count' => $application->qualifications->count(),
            'matched_qualification_id' => $matchedQualification?->id,
            'matched_qualification_reference' => $matchedQualification?->verification_reference_number,
            'matched_qualification_title' => $matchedQualification?->title_of_qualification,
            'view_url' => $this->resolveSearchResultViewUrl($application, $matchedQualification, $qualRef, $canViewVerification),
            'view_label' => $this->resolveSearchResultViewLabel($matchedQualification, $qualRef),
        ];
    }

    private function resolveSearchResultViewUrl(
        Application $application,
        ?Qualification $matchedQualification,
        ?string $qualRef,
        bool $canViewVerification,
    ): ?string {
        if (! $canViewVerification) {
            return null;
        }

        if (ReferenceSearch::isUsablePrefix($qualRef) && $matchedQualification !== null) {
            return route('admin.verification.qualifications.show', $matchedQualification);
        }

        return route('admin.verification.applications.show', $application);
    }

    private function resolveSearchResultViewLabel(?Qualification $matchedQualification, ?string $qualRef): string
    {
        if (ReferenceSearch::isUsablePrefix($qualRef) && $matchedQualification !== null) {
            return 'Open qualification';
        }

        return 'Open application';
    }

    private function qualificationMatchesReference(Qualification $qualification, ?string $qualRef): bool
    {
        if (! ReferenceSearch::isUsablePrefix($qualRef)) {
            return false;
        }

        $reference = trim((string) ($qualification->verification_reference_number ?? ''));

        return $reference !== '' && str_starts_with(strtoupper($reference), strtoupper($qualRef));
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
            VerificationState::AwaitingAutoVerification => 'Awaiting auto-verification',
            VerificationState::AwaitingAssignment => 'Awaiting assignment',
            VerificationState::AssignedToLevel1 => 'Assigned to Level 1',
            VerificationState::UnderLevel1Review => 'Under Level 1 review',
            VerificationState::UnderLevel2Review => 'Under Level 2 review',
            VerificationState::ReturnedToApplicant => 'Returned to applicant',
            VerificationState::AutoVerifiedPendingLevel2 => 'Auto-verified (Level 2 review)',
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
