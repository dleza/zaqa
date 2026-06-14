<?php

namespace App\Domain\InstitutionIntegrations;

use App\Models\AwardingInstitution;
use App\Models\InstitutionIntegration;
use Illuminate\Support\Str;

class InstitutionPullLookupPreviewService
{
    public function __construct(
        private readonly GenericRestInstitutionLearnerRecordClient $client,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(AwardingInstitution $institution, array $input): array
    {
        $institution->loadMissing('integration');
        $integration = $institution->integration;

        if (! $integration instanceof InstitutionIntegration) {
            return $this->error('No pull integration is configured for this institution.');
        }

        if (! $integration->is_active || ! $integration->supports_pull) {
            return $this->error('Pull lookup is disabled for this institution.');
        }

        if (! is_string($integration->lookup_url) || trim($integration->lookup_url) === '') {
            return $this->error('Lookup URL is not configured.');
        }

        if (! is_array($integration->credentials) || $integration->credentials === []) {
            return $this->error('Pull lookup credentials are not configured.');
        }

        $payload = $this->buildPayload($institution, $input);
        $startedAt = microtime(true);
        $result = $this->client->lookupWithPayload($integration, $payload);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'ok' => true,
            'found' => $result->isFound(),
            'status' => $result->status->value,
            'http_status' => $result->httpStatus,
            'latency_ms' => $latencyMs,
            'source_reference' => $result->sourceReference,
            'confidence_hint' => $result->confidenceHint,
            'record' => $result->learnerRecordPayload,
            'error_message' => $result->errorMessage,
            'request_payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function buildPayload(AwardingInstitution $institution, array $input): array
    {
        $payload = [
            'correlation_id' => (string) Str::uuid(),
            'student_id' => $this->nullableString($input['student_id'] ?? null),
            'examination_number' => $this->nullableString($input['examination_number'] ?? null),
            'certificate_no' => $this->nullableString($input['certificate_no'] ?? null),
            'nrc_number' => $this->nullableString($input['nrc_number'] ?? null),
            'passport_no' => $this->nullableString($input['passport_no'] ?? null),
            'first_name' => $this->nullableString($input['first_name'] ?? null),
            'last_name' => $this->nullableString($input['last_name'] ?? null),
            'other_names' => $this->nullableString($input['other_names'] ?? null),
            'program_of_study' => $this->nullableString($input['program_of_study'] ?? null),
            'year_awarded' => $this->nullableYear($input['year_awarded'] ?? null),
            'award_date' => $this->nullableString($input['award_date'] ?? null),
            'awarding_institution_id' => (int) $institution->id,
            'awarding_institution_name' => $institution->name,
        ];

        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @return array{ok: false, error: string}
     */
    private function error(string $message): array
    {
        return [
            'ok' => false,
            'error' => $message,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableYear(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit(trim($value))) {
            return (int) trim($value);
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
