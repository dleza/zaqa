<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ApplicationDraftService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
    )
    {
    }

    /**
     * @param array{service_type:string, qualification_category:string, is_foreign:bool, submitting_for?:string, subject_full_name?:string, subject_email?:string, subject_phone?:string, subject_nrc_number?:string, subject_passport_number?:string, profile_nrc_number?:string, profile_passport_number?:string} $data
     */
    public function createDraft(User $user, array $data): Application
    {
        if (! $user->applicant_type) {
            throw new RuntimeException('Applicant type is required to create an application.');
        }

        return DB::transaction(function () use ($user, $data) {
            $metadata = [];

            $submittingFor = (string) ($data['submitting_for'] ?? 'self');
            $user->loadMissing(['applicantProfile', 'institutionProfile']);
            $this->persistApplicantIdentityFromWizard($user, $data);
            $user->load(['applicantProfile', 'institutionProfile']);

            $metadata['verification_subject'] = $submittingFor === 'other'
                ? array_filter([
                    'full_name' => $data['subject_full_name'] ?? null,
                    'email' => $data['subject_email'] ?? null,
                    'phone' => $data['subject_phone'] ?? null,
                    'nrc_number' => $data['subject_nrc_number'] ?? null,
                    'passport_number' => $data['subject_passport_number'] ?? null,
                ], fn ($v) => $v !== null && $v !== '')
                : array_filter([
                    'full_name' => ($user->applicantProfile
                        ? trim((string) implode(' ', array_filter([
                            $user->applicantProfile->first_name,
                            $user->applicantProfile->middle_name,
                            $user->applicantProfile->surname,
                        ], fn ($v) => is_string($v) && trim($v) !== '')))
                        : ($user->institutionProfile?->institution_name ?? $user->name)),
                    'email' => $user->email,
                    'phone' => $user->phone_primary,
                    'nrc_number' => $user->applicantProfile?->nrc_number ?? null,
                    'passport_number' => $user->applicantProfile?->passport_number ?? null,
                ], fn ($v) => $v !== null && $v !== '');
            $metadata['submitting_for'] = $submittingFor;

            $application = $this->createWithUniqueNumber([
                'uuid' => (string) Str::uuid(),
                'application_number' => null,
                'applicant_user_id' => $user->id,
                'applicant_type' => $user->applicant_type,
                'service_type' => $data['service_type'],
                'qualification_category' => $data['qualification_category'],
                'current_status' => ApplicationStatus::Draft,
                'is_foreign' => (bool) $data['is_foreign'],
                'country_id' => null,
                'awarding_body_id' => null,
                'assigned_level1_user_id' => null,
                'assigned_by_level2_user_id' => null,
                'submitted_at' => null,
                'paid_at' => null,
                'completed_at' => null,
                'service_deadline_at' => null,
                'sent_back_at' => null,
                'approved_at' => null,
                'rejected_at' => null,
                'metadata' => $metadata,
            ]);

            ApplicationStatusHistory::create([
                'application_id' => $application->id,
                'from_status' => null,
                'to_status' => ApplicationStatus::Draft->value,
                'changed_by_user_id' => $user->id,
                'comment' => 'Draft created.',
                'changed_at' => now(),
                'metadata' => [
                    'source' => 'applicant_portal',
                ],
            ]);

            $this->audit->record(
                eventType: 'applications.draft_created',
                module: 'Applications',
                actionName: 'draft_created',
                message: 'Application draft created.',
                entityType: Application::class,
                entityId: $application->id,
                afterState: [
                    'application_number' => $application->application_number,
                    'service_type' => $application->service_type?->value ?? (string) $application->service_type,
                    'qualification_category' => $application->qualification_category,
                    'is_foreign' => (bool) $application->is_foreign,
                    'current_status' => $application->current_status?->value ?? (string) $application->current_status,
                ],
                metadata: [
                    'uuid' => $application->uuid,
                ],
                actor: $user,
            );

            $this->lifecycle->milestone(
                application: $application,
                eventType: 'draft',
                eventCode: 'draft.created',
                stage: LifecycleStage::Draft,
                title: 'Draft created',
                description: 'Draft application was created.',
                visibility: LifecycleVisibility::Both,
                actor: $user,
                metadata: [
                    'submitting_for' => $metadata['submitting_for'] ?? null,
                ],
                occurredAt: now(),
            );

            return $application;
        });
    }

    /**
     * @param array{service_type?:string, qualification_category?:string, is_foreign?:bool} $data
     */
    public function updateDraft(Application $application, User $actor, array $data): Application
    {
        $this->persistApplicantIdentityFromWizard($actor, $data);
        $actor->load(['applicantProfile', 'institutionProfile']);

        $before = [
            'service_type' => $application->service_type?->value ?? (string) $application->service_type,
            'qualification_category' => $application->qualification_category,
            'is_foreign' => (bool) $application->is_foreign,
            'metadata' => (array) ($application->metadata ?? []),
        ];

        $application->fill([
            'service_type' => $data['service_type'] ?? $application->service_type,
            'qualification_category' => $data['qualification_category'] ?? $application->qualification_category,
            'is_foreign' => array_key_exists('is_foreign', $data) ? (bool) $data['is_foreign'] : (bool) $application->is_foreign,
        ]);

        if (array_key_exists('submitting_for', $data)) {
            $meta = (array) ($application->metadata ?? []);
            $submittingFor = (string) ($data['submitting_for'] ?? 'self');
            $meta['submitting_for'] = $submittingFor;

            $actor->loadMissing(['applicantProfile', 'institutionProfile']);

            $meta['verification_subject'] = $submittingFor === 'other'
                ? array_filter([
                    'full_name' => $data['subject_full_name'] ?? null,
                    'email' => $data['subject_email'] ?? null,
                    'phone' => $data['subject_phone'] ?? null,
                    'nrc_number' => $data['subject_nrc_number'] ?? null,
                    'passport_number' => $data['subject_passport_number'] ?? null,
                ], fn ($v) => $v !== null && $v !== '')
                : array_filter([
                    'full_name' => ($actor->applicantProfile
                        ? trim((string) implode(' ', array_filter([
                            $actor->applicantProfile->first_name,
                            $actor->applicantProfile->middle_name,
                            $actor->applicantProfile->surname,
                        ], fn ($v) => is_string($v) && trim($v) !== '')))
                        : ($actor->institutionProfile?->institution_name ?? $actor->name)),
                    'email' => $actor->email,
                    'phone' => $actor->phone_primary,
                    'nrc_number' => $actor->applicantProfile?->nrc_number ?? null,
                    'passport_number' => $actor->applicantProfile?->passport_number ?? null,
                ], fn ($v) => $v !== null && $v !== '');

            $application->metadata = $meta;
        }

        $application->save();

        // Keep qualification holder details aligned with the verification subject for every qualification row.
        if (array_key_exists('submitting_for', $data)) {
            $application->loadMissing('qualifications');
            $subject = $application->metadata['verification_subject'] ?? null;
            $holderName = is_array($subject) ? trim((string) ($subject['full_name'] ?? '')) : '';
            $holderId = is_array($subject)
                ? trim((string) (($subject['nrc_number'] ?? '') ?: ($subject['passport_number'] ?? '')))
                : '';

            foreach ($application->qualifications as $qualification) {
                if ($holderName !== '') {
                    $qualification->qualification_holder_name = $holderName;
                }
                if ($holderId !== '') {
                    $qualification->nrc_passport_number = $holderId;
                }
                if ($holderName !== '' || $holderId !== '') {
                    $qualification->save();
                }
            }
        }

        $after = [
            'service_type' => $application->service_type?->value ?? (string) $application->service_type,
            'qualification_category' => $application->qualification_category,
            'is_foreign' => (bool) $application->is_foreign,
            'metadata' => (array) ($application->metadata ?? []),
        ];

        $this->audit->record(
            eventType: 'applications.draft_updated',
            module: 'Applications',
            actionName: 'draft_updated',
            message: 'Application draft updated.',
            entityType: Application::class,
            entityId: $application->id,
            beforeState: $before,
            afterState: $after,
            actor: $actor,
        );

        $this->lifecycle->event(
            application: $application,
            eventType: 'draft',
            eventCodeBase: 'draft.updated',
            stage: LifecycleStage::Wizard,
            title: 'Draft updated',
            description: 'Draft details were updated.',
            visibility: LifecycleVisibility::Internal,
            actor: $actor,
            metadata: [
                'changed_fields' => array_keys($data),
            ],
            occurredAt: now(),
        );

        if (array_key_exists('submitting_for', $data)) {
            $submittingFor = (string) ($data['submitting_for'] ?? 'self');
            $this->lifecycle->milestone(
                application: $application,
                eventType: 'wizard',
                eventCode: 'wizard.step1.subject_saved',
                stage: LifecycleStage::Wizard,
                title: 'Verification subject saved',
                description: $submittingFor === 'other'
                    ? 'Applicant saved the verification subject biodata (on behalf).'
                    : 'Applicant confirmed this application is for themselves.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                metadata: [
                    'submitting_for' => $submittingFor,
                ],
                occurredAt: now(),
            );
        }

        return $application;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createWithUniqueNumber(array $attributes): Application
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < 5) {
            $attempts++;

            $attributes['application_number'] = $this->generateApplicationNumber();

            try {
                /** @var Application $created */
                $created = Application::create($attributes);
                return $created;
            } catch (QueryException $e) {
                $lastException = $e;

                if (! $this->isUniqueConstraintViolation($e)) {
                    throw $e;
                }
            }
        }

        throw $lastException ?? new RuntimeException('Unable to generate a unique application number.');
    }

    private function generateApplicationNumber(): string
    {
        return 'ZAQA-'.now()->format('Y').'-'.strtoupper(Str::random(10));
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23000' && in_array((int) $driverCode, [1062], true);
    }

    /**
     * When an individual applicant submits as "self", merge optional inline NRC/passport into their profile
     * so they do not need a separate profile edit first.
     *
     * @param array<string, mixed> $data
     */
    private function persistApplicantIdentityFromWizard(User $user, array $data): void
    {
        if (($user->applicant_type?->value ?? null) !== ApplicantType::Individual->value) {
            return;
        }

        $submittingFor = (string) ($data['submitting_for'] ?? '');
        if ($submittingFor === 'other') {
            return;
        }

        $nrcIn = trim((string) ($data['profile_nrc_number'] ?? ''));
        $passIn = trim((string) ($data['profile_passport_number'] ?? ''));
        if ($nrcIn === '' && $passIn === '') {
            return;
        }

        $profile = $user->applicantProfile;
        if (! $profile) {
            return;
        }

        if ($nrcIn !== '') {
            $profile->nrc_number = $nrcIn;
        }
        if ($passIn !== '') {
            $profile->passport_number = $passIn;
        }

        $profile->save();
    }
}

