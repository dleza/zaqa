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
use App\Support\Applications\ApplicationSubmissionMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class InstitutionalMultipleApplicationDraftService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
        private readonly ReferenceNumberService $referenceNumbers,
    ) {}

    /**
     * @param  array{
     *   institution_reference?:string|null,
     *   notification_contact_mode?:string|null,
     *   notification_contact_email?:string|null,
     * }  $data
     */
    public function createDraft(User $user, array $data = []): Application
    {
        if ($user->applicant_type !== ApplicantType::Institution) {
            throw new RuntimeException('Institutional multiple applications require an institution account.');
        }

        return DB::transaction(function () use ($user, $data) {
            $metadata = [
                'submission_mode' => ApplicationSubmissionMode::INSTITUTIONAL_MULTIPLE,
            ];

            $institutionReference = trim((string) ($data['institution_reference'] ?? ''));
            if ($institutionReference !== '') {
                $metadata['institution_reference'] = $institutionReference;
            }

            $metadata = ApplicationNotificationContact::mergeIntoMetadata($metadata, $data, 'self');
            $metadata = $this->mergeBackupNotificationEmail($metadata, $data);

            $application = Application::create([
                'uuid' => (string) Str::uuid(),
                'application_number' => $this->referenceNumbers->generateApplicationNumber(),
                'applicant_user_id' => $user->id,
                'applicant_type' => ApplicantType::Institution,
                'service_type' => 'verification',
                'qualification_category' => 'institutional_multiple',
                'current_status' => ApplicationStatus::Draft,
                'is_foreign' => false,
                'metadata' => $metadata,
            ]);

            ApplicationStatusHistory::create([
                'application_id' => $application->id,
                'from_status' => null,
                'to_status' => ApplicationStatus::Draft->value,
                'changed_by_user_id' => $user->id,
                'comment' => 'Institutional multiple application draft created.',
                'changed_at' => now(),
                'metadata' => [
                    'source' => 'applicant_portal',
                    'submission_mode' => ApplicationSubmissionMode::INSTITUTIONAL_MULTIPLE,
                ],
            ]);

            $this->audit->record(
                eventType: 'applications.draft_created',
                module: 'Applications',
                actionName: 'institutional_multiple_draft_created',
                message: 'Institutional multiple application draft created.',
                entityType: Application::class,
                entityId: $application->id,
                afterState: [
                    'application_number' => $application->application_number,
                    'submission_mode' => ApplicationSubmissionMode::INSTITUTIONAL_MULTIPLE,
                ],
                actor: $user,
            );

            $this->lifecycle->milestone(
                application: $application,
                eventType: 'draft',
                eventCode: 'draft.institutional_multiple_created',
                stage: LifecycleStage::Draft,
                title: 'Institutional multiple draft created',
                description: 'Institutional multiple application draft was created.',
                visibility: LifecycleVisibility::Both,
                actor: $user,
                metadata: [
                    'submission_mode' => ApplicationSubmissionMode::INSTITUTIONAL_MULTIPLE,
                ],
                occurredAt: now(),
            );

            return $application;
        });
    }

    /**
     * @param  array{
     *   institution_reference?:string|null,
     *   notification_contact_mode?:string|null,
     *   notification_contact_email?:string|null,
     * }  $data
     */
    public function updateApplicationInfo(Application $application, array $data, User $actor): Application
    {
        if (! ApplicationSubmissionMode::isInstitutionalMultiple($application)) {
            throw new RuntimeException('Application is not an institutional multiple submission.');
        }

        return DB::transaction(function () use ($application, $data, $actor) {
            $meta = (array) ($application->metadata ?? []);

            $institutionReference = trim((string) ($data['institution_reference'] ?? ''));
            if ($institutionReference !== '') {
                $meta['institution_reference'] = $institutionReference;
            } elseif (array_key_exists('institution_reference', $data)) {
                unset($meta['institution_reference']);
            }

            $meta = ApplicationNotificationContact::mergeIntoMetadata($meta, $data, 'self');
            $meta = $this->mergeBackupNotificationEmail($meta, $data);
            $application->forceFill(['metadata' => $meta])->save();

            $this->audit->record(
                eventType: 'applications.updated',
                module: 'Applications',
                actionName: 'institutional_multiple_info_updated',
                message: 'Institutional multiple application information updated.',
                entityType: Application::class,
                entityId: $application->id,
                actor: $actor,
            );

            return $application->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeBackupNotificationEmail(array $metadata, array $data): array
    {
        $email = trim((string) ($data['notification_contact_email'] ?? ''));
        if ($email !== '') {
            $metadata['notification_contact_email'] = $email;
        } elseif (array_key_exists('notification_contact_email', $data)) {
            unset($metadata['notification_contact_email']);
        }

        return $metadata;
    }
}
