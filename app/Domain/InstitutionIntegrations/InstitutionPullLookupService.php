<?php

namespace App\Domain\InstitutionIntegrations;

use App\Enums\InstitutionLearnerLookupStatus;
use App\Models\InstitutionIntegration;
use App\Models\InstitutionPullLookupLog;
use App\Models\Qualification;
use Illuminate\Support\Facades\DB;

class InstitutionPullLookupService
{
    public function __construct(
        private readonly InstitutionIntegrationLearnerRecordIngestionService $ingestion,
        private readonly GenericRestInstitutionLearnerRecordClient $genericRest,
    ) {
    }

    public function lookup(Qualification $qualification): InstitutionLearnerLookupResult
    {
        $qualification->loadMissing(['awardingInstitution.integration']);

        $integration = $qualification->awardingInstitution?->integration;
        if (! $integration instanceof InstitutionIntegration) {
            return new InstitutionLearnerLookupResult(
                found: false,
                status: InstitutionLearnerLookupStatus::Failed,
                errorMessage: 'No institution integration configured.',
            );
        }

        if (! $integration->is_active || ! $integration->supports_pull) {
            return new InstitutionLearnerLookupResult(
                found: false,
                status: InstitutionLearnerLookupStatus::Failed,
                errorMessage: 'Institution pull lookup is disabled.',
            );
        }

        $driver = $integration->driver ?: 'generic_rest';
        $client = match ($driver) {
            'generic_rest' => $this->genericRest,
            default => $this->genericRest,
        };

        $startedAt = microtime(true);
        $result = $client->lookup($integration, $qualification);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->logLookup($integration, $qualification, $result, $latencyMs);

        if ($result->isFound() && is_array($result->learnerRecordPayload)) {
            $this->ingestion->upsertFromLookup(
                awardingInstitutionId: (int) $integration->awarding_institution_id,
                payload: $result->learnerRecordPayload,
                sourceReference: $result->sourceReference,
            );
        }

        DB::transaction(function () use ($integration, $result) {
            $locked = InstitutionIntegration::query()->lockForUpdate()->findOrFail($integration->id);
            if ($result->status === InstitutionLearnerLookupStatus::Found) {
                $locked->forceFill(['last_success_at' => now()])->save();
            } elseif (in_array($result->status, [InstitutionLearnerLookupStatus::Failed, InstitutionLearnerLookupStatus::Timeout, InstitutionLearnerLookupStatus::InvalidResponse], true)) {
                $locked->forceFill(['last_failure_at' => now()])->save();
            }
        });

        return $result;
    }

    private function logLookup(InstitutionIntegration $integration, Qualification $qualification, InstitutionLearnerLookupResult $result, int $latencyMs): void
    {
        $correlationId = null;
        try {
            $req = request();
            $attr = $req->attributes->get('correlation_id');
            $correlationId = is_string($attr) ? $attr : null;
        } catch (\Throwable) {
            $correlationId = null;
        }

        try {
            InstitutionPullLookupLog::query()->create([
                'awarding_institution_id' => (int) $integration->awarding_institution_id,
                'institution_integration_id' => (int) $integration->id,
                'qualification_id' => (int) $qualification->id,
                'endpoint' => (string) ($integration->lookup_url ?? ''),
                'method' => strtoupper((string) ($integration->request_method ?? 'POST')),
                'correlation_id' => $correlationId,
                'status_code' => $result->httpStatus,
                'status' => $result->status->value,
                'request_payload' => $this->sanitizePayload($this->qualificationSummaryPayload($qualification)),
                'response_payload' => $this->sanitizePayload($result->rawResponse ?? []),
                'error_message' => $result->errorMessage,
                'latency_ms' => $latencyMs,
            ]);
        } catch (\Throwable) {
            // Never fail workflow due to logging.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function qualificationSummaryPayload(Qualification $qualification): array
    {
        return [
            'qualification_id' => (int) $qualification->id,
            'student_id' => $qualification->student_number,
            'certificate_no' => $qualification->certificate_number,
            'nrc_or_passport' => $qualification->nrc_passport_number,
            'holder_name' => $qualification->qualification_holder_name,
            'award_date' => $qualification->award_date ? $qualification->award_date->format('Y-m-d') : null,
            'qualification_title' => $qualification->title_of_qualification,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        foreach (['nrc_number', 'passport_no', 'nrc_or_passport'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $payload[$field] = $this->maskString($payload[$field]);
            }
        }

        return $payload;
    }

    private function maskString(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return $v;
        }
        $len = strlen($v);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($v, 0, 2).str_repeat('*', $len - 4).substr($v, -2);
    }
}

