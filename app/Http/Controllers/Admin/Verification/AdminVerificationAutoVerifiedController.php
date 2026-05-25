<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Verification\QualificationLevel2ReviewLockService;
use App\Enums\VerificationState;
use App\Http\Controllers\Controller;
use App\Models\AwardingInstitution;
use App\Models\Qualification;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminVerificationAutoVerifiedController extends Controller
{
    public function index(Request $request, QualificationLevel2ReviewLockService $locks): Response
    {
        abort_unless((bool) $request->user()?->can('verification.level2.review'), 403);

        $q = trim((string) $request->query('q', ''));
        $institutionId = $request->query('awarding_institution_id');
        $source = trim((string) $request->query('verification_source', ''));
        $confidenceMin = $request->query('confidence_min');
        $confidenceMax = $request->query('confidence_max');
        $locked = $request->query('locked');
        $submittedFrom = trim((string) $request->query('submitted_from', ''));
        $submittedTo = trim((string) $request->query('submitted_to', ''));

        $query = Qualification::query()
            ->with([
                'application.applicant',
                'awardingInstitution',
                'learnerRecord',
                'level2ReviewLockedBy',
            ])
            ->where('verification_state', VerificationState::AutoVerifiedPendingLevel2->value);

        if (is_string($institutionId) && $institutionId !== '') {
            $query->where('awarding_institution_id', (int) $institutionId);
        }

        if ($source !== '') {
            $query->where('verification_source', $source);
        }

        if (is_string($confidenceMin) && $confidenceMin !== '') {
            $query->where('auto_verification_confidence', '>=', max(0, min(100, (int) $confidenceMin)));
        }
        if (is_string($confidenceMax) && $confidenceMax !== '') {
            $query->where('auto_verification_confidence', '<=', max(0, min(100, (int) $confidenceMax)));
        }

        if ($submittedFrom !== '' || $submittedTo !== '') {
            $query->whereHas('application', function ($aq) use ($submittedFrom, $submittedTo) {
                if ($submittedFrom !== '') {
                    $aq->whereDate('submitted_at', '>=', $submittedFrom);
                }
                if ($submittedTo !== '') {
                    $aq->whereDate('submitted_at', '<=', $submittedTo);
                }
            });
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('qualification_holder_name', 'like', '%'.$q.'%')
                    ->orWhere('nrc_passport_number', 'like', '%'.$q.'%')
                    ->orWhere('student_number', 'like', '%'.$q.'%')
                    ->orWhere('certificate_number', 'like', '%'.$q.'%')
                    ->orWhere('title_of_qualification', 'like', '%'.$q.'%')
                    ->orWhereHas('application', function ($aq) use ($q) {
                        $aq->where('application_number', 'like', '%'.$q.'%')
                            ->orWhere('metadata->verification_subject->full_name', 'like', '%'.$q.'%')
                            ->orWhereHas('applicant', fn ($uq) => $uq->where('name', 'like', '%'.$q.'%'));
                    });
            });
        }

        if ($locked === '1') {
            $threshold = now()->subMinutes($locks->ttlMinutes());
            $query->whereNotNull('level2_review_locked_by')
                ->whereNotNull('level2_review_locked_at')
                ->where('level2_review_locked_at', '>=', $threshold);
        } elseif ($locked === '0') {
            $threshold = now()->subMinutes($locks->ttlMinutes());
            $query->where(function ($w) use ($threshold) {
                $w->whereNull('level2_review_locked_by')
                    ->orWhereNull('level2_review_locked_at')
                    ->orWhere('level2_review_locked_at', '<', $threshold);
            });
        }

        $rows = $query
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (Qualification $qual) use ($locks) {
                $lockExpired = $locks->isExpired($qual->level2_review_locked_at);
                $isLocked = (bool) $qual->level2_review_locked_by && ! $lockExpired;
                $expiresAt = $qual->level2_review_locked_at ? $qual->level2_review_locked_at->copy()->addMinutes($locks->ttlMinutes()) : null;

                $holderName = $qual->qualification_holder_name ?: ($qual->application?->metadata['verification_subject']['full_name'] ?? null);
                $yearAwarded = $qual->award_date ? (int) $qual->award_date->format('Y') : null;

                return [
                    'id' => $qual->id,
                    'verification_state' => $qual->verification_state?->value ?? (string) ($qual->verification_state ?? ''),
                    'application' => [
                        'id' => $qual->application?->id,
                        'application_number' => $qual->application?->application_number,
                        'submitted_at' => optional($qual->application?->submitted_at)?->toIso8601String(),
                    ],
                    'holder_name' => $holderName,
                    'qualification_title' => $qual->title_of_qualification,
                    'verified_title' => $qual->verified_qualification_title ?: ($qual->learnerRecord?->program_of_study ?? null),
                    'awarding_institution' => $qual->awardingInstitution?->name ?? $qual->awarding_institution_name_other ?? $qual->awarding_institution_name,
                    'year_awarded' => $yearAwarded,
                    'confidence' => $qual->auto_verification_confidence !== null ? min(100, (int) $qual->auto_verification_confidence) : null,
                    'verification_source' => $qual->verification_source,
                    'learner_record' => $qual->learnerRecord
                        ? [
                            'id' => $qual->learnerRecord->id,
                            'program_of_study' => $qual->learnerRecord->program_of_study,
                            'student_id' => $qual->learnerRecord->student_id,
                            'certificate_no' => $qual->learnerRecord->certificate_no,
                            'year_awarded' => $qual->learnerRecord->year_awarded,
                        ]
                        : null,
                    'lock' => [
                        'is_locked' => $isLocked,
                        'locked_by_user_id' => $isLocked ? (int) $qual->level2_review_locked_by : null,
                        'locked_by_name' => $isLocked ? ($qual->level2ReviewLockedBy?->name ?? null) : null,
                        'locked_at' => $isLocked ? optional($qual->level2_review_locked_at)?->toIso8601String() : null,
                        'expires_at' => $isLocked ? optional($expiresAt)?->toIso8601String() : null,
                    ],
                    'review_url' => route('admin.verification.qualifications.show', ['qualification' => $qual->id]),
                ];
            });

        $institutions = AwardingInstitution::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AwardingInstitution $i) => ['id' => $i->id, 'name' => $i->name])
            ->values();

        return Inertia::render('Admin/Verification/AutoVerified/Index', [
            'qualifications' => $rows,
            'institutions' => $institutions,
            'filters' => [
                'q' => $q,
                'awarding_institution_id' => is_string($institutionId) ? $institutionId : null,
                'verification_source' => $source !== '' ? $source : null,
                'confidence_min' => is_string($confidenceMin) ? $confidenceMin : null,
                'confidence_max' => is_string($confidenceMax) ? $confidenceMax : null,
                'locked' => is_string($locked) ? $locked : null,
                'submitted_from' => $submittedFrom !== '' ? $submittedFrom : null,
                'submitted_to' => $submittedTo !== '' ? $submittedTo : null,
            ],
            'lock_ttl_minutes' => $locks->ttlMinutes(),
        ]);
    }
}
