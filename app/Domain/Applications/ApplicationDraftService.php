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
use App\Models\ApplicantProfile;
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
        private readonly ReferenceNumberService $referenceNumbers,
    )
    {
    }

    /**
     * @param array{
     *   service_type:string,
     *   qualification_category:string,
     *   is_foreign:bool,
     *   submitting_for?:string,
     *   subject_first_name?:string,
     *   subject_other_names?:string,
     *   subject_last_name?:string,
     *   subject_email?:string,
     *   subject_phone?:string,
     *   gender?:string,
     *   identity_type?:string,
     *   identity_number?:string
     * } $data
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

            $identityTypeIn = $this->normalizeIdentityType($data['identity_type'] ?? null);
            $identityNumberIn = trim((string) ($data['identity_number'] ?? ''));
            $genderIn = $this->normalizeGender($data['gender'] ?? null);

            $metadata['verification_subject'] = $submittingFor === 'other'
                ? array_filter($this->buildOtherVerificationSubject(
                    firstName: $data['subject_first_name'] ?? null,
                    otherNames: $data['subject_other_names'] ?? null,
                    lastName: $data['subject_last_name'] ?? null,
                    gender: $genderIn,
                    identityType: $identityTypeIn,
                    identityNumber: $identityNumberIn,
                ), fn ($v) => $v !== null && $v !== '')
                : array_filter($this->buildSelfVerificationSubject(
                    user: $user,
                    genderOverride: $genderIn,
                    identityTypeOverride: $identityTypeIn,
                    identityNumberOverride: $identityNumberIn,
                ), fn ($v) => $v !== null && $v !== '');
            $metadata['submitting_for'] = $submittingFor;
            $metadata = ApplicationNotificationContact::mergeIntoMetadata($metadata, $data, $submittingFor);

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

            $identityTypeIn = $this->normalizeIdentityType($data['identity_type'] ?? null);
            $identityNumberIn = trim((string) ($data['identity_number'] ?? ''));
            $genderIn = $this->normalizeGender($data['gender'] ?? null);

            $meta['verification_subject'] = $submittingFor === 'other'
                ? array_filter($this->buildOtherVerificationSubject(
                    firstName: $data['subject_first_name'] ?? null,
                    otherNames: $data['subject_other_names'] ?? null,
                    lastName: $data['subject_last_name'] ?? null,
                    gender: $genderIn,
                    identityType: $identityTypeIn,
                    identityNumber: $identityNumberIn,
                ), fn ($v) => $v !== null && $v !== '')
                : array_filter($this->buildSelfVerificationSubject(
                    user: $actor,
                    genderOverride: $genderIn,
                    identityTypeOverride: $identityTypeIn,
                    identityNumberOverride: $identityNumberIn,
                ), fn ($v) => $v !== null && $v !== '');

            $meta = ApplicationNotificationContact::mergeIntoMetadata($meta, $data, $submittingFor);

            $application->metadata = $meta;
        }

        $application->save();

        // Keep qualification holder details aligned with the verification subject for every qualification row.
        if (array_key_exists('submitting_for', $data)) {
            $application->loadMissing('qualifications');
            $subject = $application->metadata['verification_subject'] ?? null;
            $holderName = '';
            if (is_array($subject)) {
                $holderName = trim((string) ($subject['full_name'] ?? ''));
                if ($holderName === '') {
                    $holderName = trim((string) implode(' ', array_filter([
                        (string) ($subject['first_name'] ?? ''),
                        (string) ($subject['other_names'] ?? ''),
                        (string) ($subject['last_name'] ?? ''),
                    ], fn ($v) => trim((string) $v) !== '')));
                }
            }

            $holderId = '';
            if (is_array($subject)) {
                $identityType = $this->normalizeIdentityType($subject['identity_type'] ?? null);
                $holderId = $identityType === 'passport'
                    ? trim((string) ($subject['passport_number'] ?? ''))
                    : trim((string) ($subject['nrc_number'] ?? ''));

                if ($holderId === '') {
                    $holderId = trim((string) (($subject['nrc_number'] ?? '') ?: ($subject['passport_number'] ?? '')));
                }
            }

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

            $attributes['application_number'] = $this->referenceNumbers->generateApplicationNumber();

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

        $genderIn = $this->normalizeGender($data['gender'] ?? null);
        $identityType = $this->normalizeIdentityType($data['identity_type'] ?? null);
        $identityNumber = trim((string) ($data['identity_number'] ?? ''));

        if ($genderIn === null && $identityType === null && $identityNumber === '') {
            return;
        }

        $profile = $user->applicantProfile ?: new ApplicantProfile(['user_id' => $user->id]);
        $profile->user_id = $user->id;

        $dirty = false;

        if ($genderIn !== null && $genderIn !== '' && $profile->gender !== $genderIn) {
            $profile->gender = $genderIn;
            $dirty = true;
        }

        if ($identityType !== null && $identityType !== '' && $profile->identity_type !== $identityType) {
            $profile->identity_type = $identityType;
            $dirty = true;
        }

        if ($identityType !== null && $identityType !== '' && $identityNumber !== '') {
            if ($identityType === 'passport') {
                if ($profile->passport_number !== $identityNumber) {
                    $profile->passport_number = $identityNumber;
                    $dirty = true;
                }
                if ($profile->nrc_number !== null) {
                    $profile->nrc_number = null;
                    $dirty = true;
                }
            } else {
                if ($profile->nrc_number !== $identityNumber) {
                    $profile->nrc_number = $identityNumber;
                    $dirty = true;
                }
                if ($profile->passport_number !== null) {
                    $profile->passport_number = null;
                    $dirty = true;
                }
            }
        }

        if (! $dirty) {
            return;
        }

        $profile->save();
    }

    public function saveWizardDeclarations(Application $application, User $actor): Application
    {
        return DB::transaction(function () use ($application, $actor) {
            $application->refresh();

            $meta = (array) ($application->metadata ?? []);
            $now = now()->toIso8601String();
            $meta['wizard_declarations'] = array_merge(
                (array) ($meta['wizard_declarations'] ?? []),
                [
                    'terms_accepted_at' => $now,
                    'information_confirmed_at' => $now,
                ],
            );
            $application->metadata = $meta;
            $application->save();

            $this->audit->record(
                eventType: 'applications.wizard_declarations_saved',
                module: 'Applications',
                actionName: 'wizard_declarations_saved',
                message: 'Applicant saved wizard declarations (terms and accuracy confirmation).',
                entityType: Application::class,
                entityId: $application->id,
                afterState: [
                    'wizard_declarations' => $meta['wizard_declarations'],
                ],
                actor: $actor,
            );

            $this->lifecycle->milestone(
                application: $application,
                eventType: 'wizard',
                eventCode: 'wizard.step3.declarations_saved',
                stage: LifecycleStage::Wizard,
                title: 'Declarations saved',
                description: 'Terms acceptance and accuracy confirmation were recorded.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                metadata: [],
                occurredAt: now(),
            );

            return $application;
        });
    }

    private function normalizeGender(mixed $value): ?string
    {
        $v = strtolower(trim((string) ($value ?? '')));
        if ($v === '') {
            return null;
        }
        if (in_array($v, ['m', 'male'], true)) {
            return 'male';
        }
        if (in_array($v, ['f', 'female'], true)) {
            return 'female';
        }

        return null;
    }

    private function normalizeIdentityType(mixed $value): ?string
    {
        $v = strtolower(trim((string) ($value ?? '')));
        if ($v === '') {
            return null;
        }
        if (in_array($v, ['nrc'], true)) {
            return 'nrc';
        }
        if (in_array($v, ['passport'], true)) {
            return 'passport';
        }

        return null;
    }

    /**
     * @return array<string, string|null>
     */
    private function buildOtherVerificationSubject(
        mixed $firstName,
        mixed $otherNames,
        mixed $lastName,
        ?string $gender,
        ?string $identityType,
        string $identityNumber,
    ): array
    {
        $first = trim((string) ($firstName ?? ''));
        $other = trim((string) ($otherNames ?? ''));
        $last = trim((string) ($lastName ?? ''));

        $fullName = trim((string) implode(' ', array_filter([$first, $other, $last], fn ($v) => trim((string) $v) !== '')));

        return [
            'first_name' => $first !== '' ? $first : null,
            'other_names' => $other !== '' ? $other : null,
            'last_name' => $last !== '' ? $last : null,
            'full_name' => $fullName !== '' ? $fullName : null,
            'gender' => $gender,
            'identity_type' => $identityType,
            'nrc_number' => $identityType === 'nrc' && $identityNumber !== '' ? $identityNumber : null,
            'passport_number' => $identityType === 'passport' && $identityNumber !== '' ? $identityNumber : null,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function buildSelfVerificationSubject(
        User $user,
        ?string $genderOverride,
        ?string $identityTypeOverride,
        string $identityNumberOverride,
    ): array
    {
        $profile = $user->applicantProfile;

        $first = trim((string) ($profile?->first_name ?? ''));
        $other = trim((string) ($profile?->middle_name ?? ''));
        $last = trim((string) ($profile?->surname ?? ''));
        $fullName = $profile
            ? trim((string) implode(' ', array_filter([$first, $other, $last], fn ($v) => trim((string) $v) !== '')))
            : trim((string) ($user->institutionProfile?->institution_name ?? $user->name));

        $gender = $genderOverride ?: $this->normalizeGender($profile?->gender ?? null);

        $identityType = $identityTypeOverride ?: $this->normalizeIdentityType($profile?->identity_type ?? null);
        if ($identityType === null) {
            $hasNrc = trim((string) ($profile?->nrc_number ?? '')) !== '';
            $hasPassport = trim((string) ($profile?->passport_number ?? '')) !== '';
            $identityType = $hasNrc ? 'nrc' : ($hasPassport ? 'passport' : null);
        }

        $idNumber = '';
        if ($identityType === 'passport') {
            $idNumber = $identityNumberOverride !== '' ? $identityNumberOverride : trim((string) ($profile?->passport_number ?? ''));
        } elseif ($identityType === 'nrc') {
            $idNumber = $identityNumberOverride !== '' ? $identityNumberOverride : trim((string) ($profile?->nrc_number ?? ''));
        }

        return [
            'first_name' => $first !== '' ? $first : null,
            'other_names' => $other !== '' ? $other : null,
            'last_name' => $last !== '' ? $last : null,
            'full_name' => $fullName !== '' ? $fullName : null,
            'gender' => $gender,
            'identity_type' => $identityType,
            'email' => trim((string) ($user->email ?? '')) !== '' ? (string) $user->email : null,
            'phone' => trim((string) ($user->phone_primary ?? '')) !== '' ? (string) $user->phone_primary : null,
            'nrc_number' => $identityType === 'nrc' && $idNumber !== '' ? $idNumber : null,
            'passport_number' => $identityType === 'passport' && $idNumber !== '' ? $idNumber : null,
        ];
    }
}
