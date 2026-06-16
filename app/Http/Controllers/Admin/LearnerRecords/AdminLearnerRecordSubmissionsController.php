<?php

namespace App\Http\Controllers\Admin\LearnerRecords;

use App\Domain\LearnerRecords\LearnerRecordSubmissionReviewLockService;
use App\Domain\LearnerRecords\LearnerRecordSubmissionReviewService;
use App\Http\Controllers\Controller;
use App\Models\AwardingInstitution;
use App\Models\LearnerRecordSubmission;
use App\Models\LearnerRecordSubmissionBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminLearnerRecordSubmissionsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LearnerRecordSubmission::class);

        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $sourceType = $request->query('source_type');
        $institutionId = $request->query('source_institution_id');
        $receivedFrom = $request->query('received_from');
        $receivedTo = $request->query('received_to');

        $submissions = LearnerRecordSubmission::query()
            ->with(['sourceInstitution:id,name', 'batch:id,reference'])
            ->when(is_string($status) && $status !== '', fn ($qq) => $qq->where('status', $status))
            ->when(is_string($sourceType) && $sourceType !== '', fn ($qq) => $qq->where('source_type', $sourceType))
            ->when($institutionId, fn ($qq) => $qq->where('source_institution_id', (int) $institutionId))
            ->when(is_string($receivedFrom) && $receivedFrom !== '', fn ($qq) => $qq->whereDate('received_at', '>=', $receivedFrom))
            ->when(is_string($receivedTo) && $receivedTo !== '', fn ($qq) => $qq->whereDate('received_at', '<=', $receivedTo))
            ->search($q)
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (LearnerRecordSubmission $s) => [
                'id' => $s->id,
                'batch_reference' => $s->batch?->reference,
                'source_type' => $s->source_type?->value,
                'source_institution' => $s->sourceInstitution ? ['id' => $s->sourceInstitution->id, 'name' => $s->sourceInstitution->name] : null,
                'display_name' => $s->displayName(),
                'student_id' => $s->student_id,
                'certificate_no' => $s->certificate_no,
                'program_of_study' => $s->program_of_study,
                'year_awarded' => $s->year_awarded,
                'status' => $s->status?->value,
                'duplicate_candidate_count' => $s->duplicateCandidateCount(),
                'received_at' => optional($s->received_at)->toIso8601String(),
            ]);

        $institutions = AwardingInstitution::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AwardingInstitution $i) => ['id' => $i->id, 'name' => $i->name])
            ->values();

        return Inertia::render('Admin/LearnerRecords/Submissions/Index', [
            'submissions' => $submissions,
            'institutions' => $institutions,
            'filters' => [
                'q' => $q,
                'status' => is_string($status) ? $status : null,
                'source_type' => is_string($sourceType) ? $sourceType : null,
                'source_institution_id' => is_string($institutionId) ? $institutionId : null,
                'received_from' => is_string($receivedFrom) ? $receivedFrom : null,
                'received_to' => is_string($receivedTo) ? $receivedTo : null,
            ],
            'can' => [
                'review' => (bool) $request->user()?->can('learner_record_submissions.review'),
                'approve' => (bool) $request->user()?->can('learner_record_submissions.approve'),
                'reject' => (bool) $request->user()?->can('learner_record_submissions.reject'),
            ],
        ]);
    }

    public function show(Request $request, LearnerRecordSubmission $submission, LearnerRecordSubmissionReviewLockService $locks): Response
    {
        $this->authorize('view', $submission);

        $submission->loadMissing([
            'sourceInstitution:id,name',
            'batch:id,reference,status,total_records,pending_count',
            'reviewedBy:id,name,email',
            'reviewLockedBy:id,name,email',
            'approvedLearnerRecord:id,student_id,certificate_no,first_name,last_name,program_of_study,year_awarded',
            'targetLearnerRecord:id,student_id,certificate_no,first_name,last_name,program_of_study,year_awarded',
        ]);

        $nextSubmissionId = $locks->nextPendingSubmissionId((int) $submission->id);
        $user = $request->user();

        return Inertia::render('Admin/LearnerRecords/Submissions/Show', [
            'submission' => array_merge($this->serializeSubmission($submission), [
                'review_lock' => $locks->serializeLock($submission),
                'next_submission_id' => $nextSubmissionId,
                'start_review_url' => route('admin.learner_records.submissions.start_review', $submission),
                'release_review_url' => route('admin.learner_records.submissions.release_review', $submission),
            ]),
            'viewer_user_id' => $user?->id,
            'can' => [
                'review' => (bool) $user?->can('review', $submission),
                'approve' => (bool) $user?->can('approve', $submission),
                'reject' => (bool) $user?->can('reject', $submission),
                'is_super_admin' => (bool) $user?->hasRole('Super Admin'),
            ],
        ]);
    }

    public function showBatch(Request $request, LearnerRecordSubmissionBatch $batch): Response
    {
        $this->authorize('viewAny', LearnerRecordSubmission::class);

        $batch->loadMissing(['sourceInstitution:id,name']);

        $submissions = LearnerRecordSubmission::query()
            ->with(['sourceInstitution:id,name'])
            ->where('batch_id', $batch->id)
            ->orderBy('row_number')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (LearnerRecordSubmission $s) => [
                'id' => $s->id,
                'row_number' => $s->row_number,
                'display_name' => $s->displayName(),
                'student_id' => $s->student_id,
                'certificate_no' => $s->certificate_no,
                'status' => $s->status?->value,
                'duplicate_candidate_count' => $s->duplicateCandidateCount(),
            ]);

        return Inertia::render('Admin/LearnerRecords/Submissions/Batch', [
            'batch' => [
                'id' => $batch->id,
                'reference' => $batch->reference,
                'source_type' => $batch->source_type?->value,
                'status' => $batch->status?->value,
                'source_institution' => $batch->sourceInstitution ? ['id' => $batch->sourceInstitution->id, 'name' => $batch->sourceInstitution->name] : null,
                'total_records' => $batch->total_records,
                'pending_count' => $batch->pending_count,
                'approved_count' => $batch->approved_count,
                'rejected_count' => $batch->rejected_count,
                'duplicate_count' => $batch->duplicate_count,
                'failed_validation_count' => $batch->failed_validation_count,
                'received_at' => optional($batch->received_at)->toIso8601String(),
            ],
            'submissions' => $submissions,
        ]);
    }

    public function approve(Request $request, LearnerRecordSubmission $submission, LearnerRecordSubmissionReviewService $reviews): RedirectResponse
    {
        $this->authorize('approve', $submission);

        $validated = $request->validate([
            'decision' => ['required', 'in:approve_new,approve_update'],
            'target_learner_record_id' => ['nullable', 'integer', 'exists:learner_records,id'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'allow_overwrite' => ['nullable', 'boolean'],
        ]);

        $notes = $validated['review_notes'] ?? null;

        if ($validated['decision'] === 'approve_update') {
            if (empty($validated['target_learner_record_id'])) {
                return back()->withErrors(['target_learner_record_id' => 'Target learner record is required for update approval.']);
            }

            $reviews->approveAsUpdate(
                submission: $submission,
                actor: $request->user(),
                targetLearnerRecordId: (int) $validated['target_learner_record_id'],
                notes: $notes,
                allowOverwrite: (bool) ($validated['allow_overwrite'] ?? false),
            );
        } else {
            $reviews->approveAsNew($submission, $request->user(), $notes);
        }

        return redirect()
            ->route('admin.learner_records.submissions.show', $submission)
            ->with('flash.success', 'Submission approved and promoted to learner records.');
    }

    public function reject(Request $request, LearnerRecordSubmission $submission, LearnerRecordSubmissionReviewService $reviews): RedirectResponse
    {
        $this->authorize('reject', $submission);

        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
        ]);

        $reviews->reject($submission, $request->user(), $validated['review_notes']);

        return redirect()
            ->route('admin.learner_records.submissions.show', $submission)
            ->with('flash.success', 'Submission rejected.');
    }

    public function markDuplicate(Request $request, LearnerRecordSubmission $submission, LearnerRecordSubmissionReviewService $reviews): RedirectResponse
    {
        $this->authorize('reject', $submission);

        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
            'target_learner_record_id' => ['nullable', 'integer', 'exists:learner_records,id'],
        ]);

        $reviews->markDuplicate(
            submission: $submission,
            actor: $request->user(),
            reason: $validated['review_notes'],
            targetLearnerRecordId: isset($validated['target_learner_record_id']) ? (int) $validated['target_learner_record_id'] : null,
        );

        return redirect()
            ->route('admin.learner_records.submissions.show', $submission)
            ->with('flash.success', 'Submission marked as duplicate.');
    }

    public function startReview(Request $request, LearnerRecordSubmission $submission, LearnerRecordSubmissionReviewLockService $locks): RedirectResponse
    {
        $this->authorize('review', $submission);

        $locks->lock($submission, $request->user());

        return redirect()
            ->route('admin.learner_records.submissions.show', $submission)
            ->with('flash.success', 'Review started. This submission is locked to you for 30 minutes.');
    }

    public function releaseReview(Request $request, LearnerRecordSubmission $submission, LearnerRecordSubmissionReviewLockService $locks): RedirectResponse
    {
        $this->authorize('review', $submission);

        $locks->unlock($submission, $request->user());

        return redirect()
            ->route('admin.learner_records.submissions.show', $submission)
            ->with('flash.success', 'Review lock released.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSubmission(LearnerRecordSubmission $submission): array
    {
        return [
            'id' => $submission->id,
            'batch' => $submission->batch ? [
                'id' => $submission->batch->id,
                'reference' => $submission->batch->reference,
                'status' => $submission->batch->status?->value,
                'total_records' => $submission->batch->total_records,
                'pending_count' => $submission->batch->pending_count,
            ] : null,
            'source_type' => $submission->source_type?->value,
            'source_institution' => $submission->sourceInstitution ? ['id' => $submission->sourceInstitution->id, 'name' => $submission->sourceInstitution->name] : null,
            'source_reference' => $submission->source_reference,
            'status' => $submission->status?->value,
            'student_id' => $submission->student_id,
            'certificate_no' => $submission->certificate_no,
            'nrc_number' => $submission->nrc_number,
            'passport_no' => $submission->passport_no,
            'first_name' => $submission->first_name,
            'last_name' => $submission->last_name,
            'other_names' => $submission->other_names,
            'gender' => $submission->gender,
            'program_of_study' => $submission->program_of_study,
            'year_awarded' => $submission->year_awarded,
            'award_date' => optional($submission->award_date)->format('Y-m-d'),
            'classification' => $submission->classification,
            'examination_number' => $submission->examination_number,
            'risk_flags' => $submission->risk_flags ?? [],
            'duplicate_candidates' => $submission->duplicate_candidates ?? [],
            'payload_summary' => $this->payloadSummary($submission->payload_json),
            'validation_errors' => $submission->validation_errors,
            'review_decision' => $submission->review_decision?->value,
            'review_notes' => $submission->review_notes,
            'reviewed_by' => $submission->reviewedBy ? ['id' => $submission->reviewedBy->id, 'name' => $submission->reviewedBy->name] : null,
            'reviewed_at' => optional($submission->reviewed_at)->toIso8601String(),
            'approved_learner_record' => $submission->approvedLearnerRecord ? [
                'id' => $submission->approvedLearnerRecord->id,
                'student_id' => $submission->approvedLearnerRecord->student_id,
                'certificate_no' => $submission->approvedLearnerRecord->certificate_no,
                'first_name' => $submission->approvedLearnerRecord->first_name,
                'last_name' => $submission->approvedLearnerRecord->last_name,
                'program_of_study' => $submission->approvedLearnerRecord->program_of_study,
                'year_awarded' => $submission->approvedLearnerRecord->year_awarded,
            ] : null,
            'target_learner_record' => $submission->targetLearnerRecord ? [
                'id' => $submission->targetLearnerRecord->id,
                'student_id' => $submission->targetLearnerRecord->student_id,
                'certificate_no' => $submission->targetLearnerRecord->certificate_no,
                'first_name' => $submission->targetLearnerRecord->first_name,
                'last_name' => $submission->targetLearnerRecord->last_name,
                'program_of_study' => $submission->targetLearnerRecord->program_of_study,
                'year_awarded' => $submission->targetLearnerRecord->year_awarded,
            ] : null,
            'received_at' => optional($submission->received_at)->toIso8601String(),
            'created_at' => optional($submission->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function payloadSummary(?array $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $keys = [
            'student_id', 'certificate_no', 'first_name', 'last_name', 'other_names',
            'program_of_study', 'year_awarded', 'award_date', 'source_reference',
        ];

        $summary = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                $summary[$key] = $payload[$key];
            }
        }

        return $summary;
    }
}
