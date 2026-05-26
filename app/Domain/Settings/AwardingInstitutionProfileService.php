<?php

namespace App\Domain\Settings;

use App\Enums\VerificationState;
use App\Models\AwardingInstitution;
use App\Models\InstitutionApiClient;
use App\Models\InstitutionIntegrationLog;
use App\Models\InstitutionPullLookupLog;
use App\Models\LearnerRecord;
use App\Models\LearnerRecordImport;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Support\CountryIso;
use App\Enums\LearnerRecordImportStatus;

class AwardingInstitutionProfileService
{
    /**
     * @return array<string, mixed>
     */
    public function build(AwardingInstitution $institution): array
    {
        $institution->loadMissing(['country', 'integration']);

        $countryIso = strtoupper((string) ($institution->country?->iso_code ?? ''));
        $isZambian = CountryIso::isZambia($countryIso);

        $qualificationCountsByState = $this->qualificationCountsByState((int) $institution->id);

        $pendingLevel2 = (int) ($qualificationCountsByState[VerificationState::AutoVerifiedPendingLevel2->value] ?? 0);
        $pendingLevel1 = (int) ($qualificationCountsByState[VerificationState::AwaitingAssignment->value] ?? 0)
            + (int) ($qualificationCountsByState[VerificationState::AssignedToLevel1->value] ?? 0)
            + (int) ($qualificationCountsByState[VerificationState::UnderLevel1Review->value] ?? 0);

        $verifiedApproved = (int) ($qualificationCountsByState[VerificationState::ApprovedForCertificate->value] ?? 0)
            + (int) ($qualificationCountsByState[VerificationState::CertificateIssued->value] ?? 0);

        $rejected = (int) ($qualificationCountsByState[VerificationState::Rejected->value] ?? 0);

        $learnerRecordsTotal = LearnerRecord::query()
            ->where('awarding_institution_id', (int) $institution->id)
            ->count();

        $qualificationsTotal = array_sum(array_map('intval', $qualificationCountsByState));

        $autoVerifiedTotal = Qualification::query()
            ->where('awarding_institution_id', (int) $institution->id)
            ->whereNotNull('auto_verified_at')
            ->count();

        $certificatesIssued = QualificationCertificate::query()
            ->whereIn('status', [QualificationCertificate::STATUS_ISSUED, QualificationCertificate::STATUS_REISSUED])
            ->whereHas('qualification', fn ($q) => $q->where('awarding_institution_id', (int) $institution->id))
            ->count();

        $activeApiClientsCount = InstitutionApiClient::query()
            ->where('awarding_institution_id', (int) $institution->id)
            ->where('is_active', true)
            ->count();

        $apiClientsTotal = InstitutionApiClient::query()
            ->where('awarding_institution_id', (int) $institution->id)
            ->count();

        $apiPushSuccessRequests = InstitutionIntegrationLog::query()
            ->where('awarding_institution_id', (int) $institution->id)
            ->where('status', 'success')
            ->whereIn('endpoint', [
                '/api/institution/v1/learner-records',
                '/api/institution/v1/learner-records/batch',
            ])
            ->count();

        $lastPushAt = InstitutionIntegrationLog::query()
            ->where('awarding_institution_id', (int) $institution->id)
            ->orderByDesc('id')
            ->first(['created_at'])
            ?->created_at;

        $pullAttempts = InstitutionPullLookupLog::query()
            ->where('awarding_institution_id', (int) $institution->id)
            ->count();

        $lastPull = InstitutionPullLookupLog::query()
            ->where('awarding_institution_id', (int) $institution->id)
            ->orderByDesc('id')
            ->first(['status', 'created_at']);

        $lastImportAt = LearnerRecordImport::query()
            ->where('awarding_institution_id', (int) $institution->id)
            ->whereIn('status', [LearnerRecordImportStatus::Completed, LearnerRecordImportStatus::CompletedWithErrors])
            ->orderByDesc('id')
            ->first(['completed_at'])
            ?->completed_at;

        $lastIntegrationActivityAt = $this->maxDateTime([$lastPushAt, $lastPull?->created_at]);

        $recentQualifications = Qualification::query()
            ->with(['application:id,application_number,submitted_at', 'assignedVerifier:id,name'])
            ->where('awarding_institution_id', (int) $institution->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Qualification $q) => [
                'id' => $q->id,
                'application_number' => $q->application?->application_number,
                'submitted_at' => optional($q->application?->submitted_at)->toIso8601String(),
                'holder_name' => $q->qualification_holder_name,
                'qualification_title' => $q->title_of_qualification,
                'verified_title' => $q->verified_qualification_title,
                'verification_state' => $q->verification_state?->value,
                'confidence' => $q->auto_verification_confidence,
                'assigned_verifier' => $q->assignedVerifier?->name,
                'updated_at' => optional($q->updated_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'institution' => [
                'id' => $institution->id,
                'name' => $institution->name,
                'country' => $institution->country ? [
                    'id' => $institution->country->id,
                    'name' => $institution->country->name,
                    'iso_code' => $institution->country->iso_code,
                ] : null,
                'is_active' => (bool) $institution->is_active,
                'is_zambian' => $isZambian,
                'is_foreign' => ! $isZambian,
                'has_consent_form' => (bool) $institution->has_consent_form,
                'consent_form_url' => $institution->consent_form_url,
                'created_at' => optional($institution->created_at)->toIso8601String(),
                'updated_at' => optional($institution->updated_at)->toIso8601String(),
            ],
            'stats' => [
                'learner_records_total' => (int) $learnerRecordsTotal,
                'qualifications_total' => (int) $qualificationsTotal,
                'auto_verified_total' => (int) $autoVerifiedTotal,
                'pending_level2_total' => (int) $pendingLevel2,
                'pending_level1_total' => (int) $pendingLevel1,
                'verified_approved_total' => (int) $verifiedApproved,
                'rejected_total' => (int) $rejected,
                'certificates_issued_total' => (int) $certificatesIssued,
                'api_push_success_requests' => (int) $apiPushSuccessRequests,
                'pull_lookup_attempts_total' => (int) $pullAttempts,
                'last_import_at' => optional($lastImportAt)?->toIso8601String(),
                'last_integration_activity_at' => optional($lastIntegrationActivityAt)?->toIso8601String(),
                'last_pull_lookup' => $lastPull ? [
                    'status' => (string) $lastPull->status,
                    'created_at' => optional($lastPull->created_at)->toIso8601String(),
                ] : null,
                'last_push_at' => optional($lastPushAt)?->toIso8601String(),
                'api_clients_total' => (int) $apiClientsTotal,
                'api_clients_active' => (int) $activeApiClientsCount,
                'pull_lookup_enabled' => (bool) ($institution->integration?->supports_pull && $institution->integration?->is_active),
                'push_enabled' => (bool) ($institution->integration?->supports_push && $institution->integration?->is_active),
            ],
            'qualification_counts_by_state' => $qualificationCountsByState,
            'recent_qualifications' => $recentQualifications,
            'links' => [
                'edit' => route('admin.settings.awarding_institutions.edit', ['awardingInstitution' => $institution->id]),
                'deactivate' => route('admin.settings.awarding_institutions.deactivate', ['awardingInstitution' => $institution->id]),
                'reactivate' => route('admin.settings.awarding_institutions.reactivate', ['awardingInstitution' => $institution->id]),
                'learner_records' => '/admin/learner-records?awarding_institution_id='.(int) $institution->id,
                'institution_api_clients' => '/admin/integrations/institution-api-clients?awarding_institution_id='.(int) $institution->id,
                'institution_integrations' => route('admin.integrations.institution_integrations.edit', ['awardingInstitution' => $institution->id]),
                'institution_api_logs' => '/admin/integrations/institution-api-logs?awarding_institution_id='.(int) $institution->id,
                'institution_pull_logs' => '/admin/integrations/institution-pull-lookup-logs?awarding_institution_id='.(int) $institution->id,
                'qualifications_pool' => '/admin/verification/pool?awarding_institution_id='.(int) $institution->id,
                'qualifications_auto_verified' => '/admin/verification/auto-verified?awarding_institution_id='.(int) $institution->id,
            ],
        ];
    }

    /**
     * @return array<string,int>
     */
    private function qualificationCountsByState(int $institutionId): array
    {
        /** @var array<string,int> $counts */
        $counts = Qualification::query()
            ->where('awarding_institution_id', $institutionId)
            ->selectRaw('verification_state, COUNT(*) as c')
            ->groupBy('verification_state')
            ->pluck('c', 'verification_state')
            ->map(fn ($v) => (int) $v)
            ->all();

        return $counts;
    }

    private function maxDateTime(array $values): ?\DateTimeInterface
    {
        $max = null;
        foreach ($values as $v) {
            if (! $v instanceof \DateTimeInterface) {
                continue;
            }
            if ($max === null || $v > $max) {
                $max = $v;
            }
        }

        return $max;
    }
}
