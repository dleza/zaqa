<?php

namespace App\Domain\Settings;

use App\Domain\Audit\AuditLogService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\AwardingInstitution;
use App\Models\Qualification;
use App\Models\User;

class AwardingInstitutionAccreditationStatementService
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_LEVEL1_SUBMISSION = 'level1_submission';

    public const SOURCE_LEVEL2_APPROVAL = 'level2_approval';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_SYSTEM = 'system';

    public const CERT_SOURCE_LEVEL2_CORRECTED = 'level2_corrected';

    public const CERT_SOURCE_LEVEL1_SUBMISSION = 'level1_submission';

    public const CERT_SOURCE_AWARDING_INSTITUTION = 'awarding_institution';

    public const CERT_SOURCE_CONFIG_FALLBACK = 'config_fallback';

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
    ) {}

    public function defaultForLevel1Prefill(Qualification $qualification): string
    {
        $existing = trim((string) ($qualification->level1_accreditation_statement ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        return $this->institutionStatement($qualification);
    }

    public function defaultForLevel2Prefill(Qualification $qualification): string
    {
        $existing = trim((string) ($qualification->level1_accreditation_statement ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        return $this->institutionStatement($qualification);
    }

    public function institutionHasStatement(Qualification $qualification): bool
    {
        return $this->institutionStatement($qualification) !== '';
    }

    /**
     * @return array{statement: string, source: string, awarding_institution_id: int|null, awarding_institution_name: string|null}
     */
    public function resolveForCertificate(Qualification $qualification): array
    {
        $qualification->loadMissing('awardingInstitution');

        $level1Statement = trim((string) ($qualification->level1_accreditation_statement ?? ''));
        if ($level1Statement !== '') {
            return [
                'statement' => $level1Statement,
                'source' => $this->level1StatementWasLevel2Corrected($qualification)
                    ? self::CERT_SOURCE_LEVEL2_CORRECTED
                    : self::CERT_SOURCE_LEVEL1_SUBMISSION,
                'awarding_institution_id' => $qualification->awarding_institution_id ? (int) $qualification->awarding_institution_id : null,
                'awarding_institution_name' => $this->resolveInstitutionName($qualification),
            ];
        }

        $institutionStatement = $this->institutionStatement($qualification);
        if ($institutionStatement !== '') {
            return [
                'statement' => $institutionStatement,
                'source' => self::CERT_SOURCE_AWARDING_INSTITUTION,
                'awarding_institution_id' => $qualification->awarding_institution_id ? (int) $qualification->awarding_institution_id : null,
                'awarding_institution_name' => $this->resolveInstitutionName($qualification),
            ];
        }

        return [
            'statement' => (string) config('certificates.recognition_act_clause'),
            'source' => self::CERT_SOURCE_CONFIG_FALLBACK,
            'awarding_institution_id' => $qualification->awarding_institution_id ? (int) $qualification->awarding_institution_id : null,
            'awarding_institution_name' => $this->resolveInstitutionName($qualification),
        ];
    }

    public function autoSaveFromLevel1IfInstitutionBlank(Qualification $qualification, User $actor, ?string $statement): void
    {
        $statement = $statement !== null ? trim($statement) : '';
        if ($statement === '') {
            return;
        }

        $this->autoSaveToInstitutionIfBlank(
            $qualification,
            $actor,
            $statement,
            self::SOURCE_LEVEL1_SUBMISSION,
            'awarding_institution.accreditation_statement_auto_saved_from_level1',
            'Accreditation statement saved to awarding institution from Level 1 submission.',
        );
    }

    public function autoSaveFromLevel2ApprovalIfInstitutionBlank(Qualification $qualification, User $actor, ?string $statement): void
    {
        $statement = $statement !== null ? trim($statement) : '';
        if ($statement === '') {
            return;
        }

        $this->autoSaveToInstitutionIfBlank(
            $qualification,
            $actor,
            $statement,
            self::SOURCE_LEVEL2_APPROVAL,
            'awarding_institution.accreditation_statement_auto_saved_from_level2',
            'Accreditation statement saved to awarding institution from Level 2 approval.',
        );
    }

    public function recordAdminUpdate(
        AwardingInstitution $institution,
        User $actor,
        ?string $before,
        ?string $after,
        string $source = self::SOURCE_MANUAL,
    ): void {
        $this->audit->record(
            eventType: 'awarding_institution.accreditation_statement_updated',
            module: 'Settings',
            actionName: 'accreditation_statement_updated',
            message: 'Awarding institution accreditation statement updated.',
            entityType: AwardingInstitution::class,
            entityId: $institution->id,
            beforeState: ['accreditation_statement' => $before],
            afterState: [
                'accreditation_statement' => $after,
                'accreditation_statement_source' => $source,
            ],
            metadata: [
                'statement_length' => $after !== null ? strlen($after) : 0,
            ],
            actor: $actor,
        );
    }

    private function autoSaveToInstitutionIfBlank(
        Qualification $qualification,
        User $actor,
        string $statement,
        string $source,
        string $auditEventType,
        string $lifecycleDescription,
    ): void {
        $institutionId = (int) ($qualification->awarding_institution_id ?? 0);
        if ($institutionId < 1) {
            return;
        }

        $institution = AwardingInstitution::query()->find($institutionId);
        if (! $institution instanceof AwardingInstitution) {
            return;
        }

        $existing = trim((string) ($institution->accreditation_statement ?? ''));
        if ($existing !== '') {
            if ($existing !== $statement) {
                $this->audit->record(
                    eventType: 'awarding_institution.accreditation_statement_mismatch',
                    module: 'Verification',
                    actionName: 'accreditation_statement_mismatch',
                    message: 'Submitted accreditation statement differed from saved institution statement; institution statement kept unchanged.',
                    entityType: AwardingInstitution::class,
                    entityId: $institution->id,
                    metadata: [
                        'qualification_id' => $qualification->id,
                        'application_id' => $qualification->application_id,
                        'submitted_length' => strlen($statement),
                        'institution_statement_length' => strlen($existing),
                    ],
                    actor: $actor,
                );
            }

            return;
        }

        $institution->forceFill([
            'accreditation_statement' => $statement,
            'accreditation_statement_source' => $source,
            'accreditation_statement_updated_by_user_id' => $actor->id,
            'accreditation_statement_updated_at' => now(),
        ])->save();

        $qualification->loadMissing('application');
        $application = $qualification->application;
        if ($application instanceof Application) {
            $this->lifecycle->event(
                application: $application,
                eventType: 'settings',
                eventCodeBase: 'settings.awarding_institution_accreditation_auto_saved.i'.$institution->id,
                stage: LifecycleStage::Review,
                title: 'Institution accreditation statement saved',
                description: $lifecycleDescription,
                visibility: LifecycleVisibility::Internal,
                actor: $actor,
                metadata: [
                    'awarding_institution_id' => $institution->id,
                    'qualification_id' => $qualification->id,
                    'statement_source' => $source,
                ],
                occurredAt: now(),
            );
        }

        $this->audit->record(
            eventType: $auditEventType,
            module: 'Settings',
            actionName: 'accreditation_statement_auto_saved',
            message: $lifecycleDescription,
            entityType: AwardingInstitution::class,
            entityId: $institution->id,
            afterState: [
                'accreditation_statement' => $statement,
                'accreditation_statement_source' => $source,
            ],
            metadata: [
                'qualification_id' => $qualification->id,
                'application_id' => $qualification->application_id,
                'statement_source' => $source,
                'statement_length' => strlen($statement),
            ],
            actor: $actor,
        );
    }

    private function institutionStatement(Qualification $qualification): string
    {
        $qualification->loadMissing('awardingInstitution');

        return trim((string) ($qualification->awardingInstitution?->accreditation_statement ?? ''));
    }

    private function resolveInstitutionName(Qualification $qualification): ?string
    {
        $name = trim((string) ($qualification->awardingInstitution?->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $other = trim((string) ($qualification->awarding_institution_name_other ?? ''));
        if ($other !== '') {
            return $other;
        }

        $legacy = trim((string) ($qualification->awarding_institution_name ?? ''));

        return $legacy !== '' ? $legacy : null;
    }

    private function level1StatementWasLevel2Corrected(Qualification $qualification): bool
    {
        return AuditLog::query()
            ->where('entity_type', Qualification::class)
            ->where('entity_id', $qualification->id)
            ->where('event_type', 'verification.level2_corrected_level1_submission')
            ->whereJsonContains('metadata->changed_fields', 'accreditation_statement')
            ->exists();
    }
}
