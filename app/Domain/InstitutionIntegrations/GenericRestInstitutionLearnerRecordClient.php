<?php

namespace App\Domain\InstitutionIntegrations;

use App\Enums\InstitutionLearnerLookupStatus;
use App\Models\InstitutionIntegration;
use App\Models\Qualification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class GenericRestInstitutionLearnerRecordClient implements InstitutionLearnerRecordClientInterface
{
    public function lookup(InstitutionIntegration $integration, Qualification $qualification): InstitutionLearnerLookupResult
    {
        $url = trim((string) ($integration->lookup_url ?? ''));
        if ($url === '') {
            return new InstitutionLearnerLookupResult(
                found: false,
                status: InstitutionLearnerLookupStatus::Failed,
                errorMessage: 'Lookup URL not configured.',
            );
        }

        $timeout = (int) ($integration->timeout_seconds ?? 15);
        $method = strtoupper((string) ($integration->request_method ?? 'POST'));

        $payload = $this->buildQualificationPayload($qualification);

        try {
            $pending = Http::timeout($timeout)->connectTimeout(min(5, $timeout));
            $pending = $pending->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Request-Id' => (string) ($payload['correlation_id'] ?? ''),
            ]);

            if ($integration->auth_type === 'bearer_token') {
                $token = is_array($integration->credentials) ? ($integration->credentials['bearer_token'] ?? null) : null;
                if (is_string($token) && trim($token) !== '') {
                    $pending = $pending->withToken($token);
                }
            } elseif ($integration->auth_type === 'basic') {
                $u = is_array($integration->credentials) ? ($integration->credentials['basic_username'] ?? null) : null;
                $p = is_array($integration->credentials) ? ($integration->credentials['basic_password'] ?? null) : null;
                if (is_string($u) && is_string($p) && $u !== '' && $p !== '') {
                    $pending = $pending->withBasicAuth($u, $p);
                }
            }

            $response = match ($method) {
                'GET' => $pending->get($url, $payload),
                default => $pending->post($url, $payload),
            };
        } catch (ConnectionException $e) {
            return new InstitutionLearnerLookupResult(
                found: false,
                status: InstitutionLearnerLookupStatus::Timeout,
                errorMessage: 'Connection timeout / network error.',
            );
        } catch (\Throwable $e) {
            return new InstitutionLearnerLookupResult(
                found: false,
                status: InstitutionLearnerLookupStatus::Failed,
                errorMessage: $e->getMessage(),
            );
        }

        $httpStatus = (int) $response->status();

        if ($httpStatus === 404) {
            return new InstitutionLearnerLookupResult(
                found: false,
                status: InstitutionLearnerLookupStatus::NotFound,
                httpStatus: $httpStatus,
                rawResponse: $this->safeJson($response->body()),
            );
        }

        if (! $response->successful()) {
            return new InstitutionLearnerLookupResult(
                found: false,
                status: InstitutionLearnerLookupStatus::Failed,
                httpStatus: $httpStatus,
                rawResponse: $this->safeJson($response->body()),
                errorMessage: 'HTTP '.$httpStatus,
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            return new InstitutionLearnerLookupResult(
                found: false,
                status: InstitutionLearnerLookupStatus::InvalidResponse,
                httpStatus: $httpStatus,
                rawResponse: ['body' => $this->truncate((string) $response->body())],
                errorMessage: 'Expected JSON object response.',
            );
        }

        if (! array_key_exists('found', $json) || ! is_bool($json['found'])) {
            return new InstitutionLearnerLookupResult(
                found: false,
                status: InstitutionLearnerLookupStatus::InvalidResponse,
                httpStatus: $httpStatus,
                rawResponse: $this->sanitizeRaw($json),
                errorMessage: 'Missing or invalid "found" boolean.',
            );
        }

        $found = (bool) $json['found'];
        $sourceReference = array_key_exists('source_reference', $json) ? $json['source_reference'] : null;
        $confidenceHint = array_key_exists('confidence_hint', $json) ? $json['confidence_hint'] : null;

        if ($found) {
            if (! array_key_exists('source_reference', $json) || ! is_string($sourceReference) || trim($sourceReference) === '') {
                return new InstitutionLearnerLookupResult(
                    found: false,
                    status: InstitutionLearnerLookupStatus::InvalidResponse,
                    httpStatus: $httpStatus,
                    rawResponse: $this->sanitizeRaw($json),
                    errorMessage: 'Missing or invalid "source_reference" for found=true.',
                );
            }

            if (array_key_exists('confidence_hint', $json) && $confidenceHint !== null) {
                if (! is_numeric($confidenceHint)) {
                    return new InstitutionLearnerLookupResult(
                        found: false,
                        status: InstitutionLearnerLookupStatus::InvalidResponse,
                        httpStatus: $httpStatus,
                        rawResponse: $this->sanitizeRaw($json),
                        errorMessage: 'Invalid "confidence_hint" (expected integer 0-100 or null).',
                    );
                }
                $confidenceHint = max(0, min(100, (int) $confidenceHint));
            } else {
                $confidenceHint = null;
            }
        } else {
            // For not-found responses, enforce the standard contract shape: record/source_reference/confidence_hint must be null (or absent),
            // unless an explicit "error" object is provided.
            $hasError = array_key_exists('error', $json) && is_array($json['error']);
            if (! $hasError) {
                foreach (['record', 'source_reference', 'confidence_hint'] as $k) {
                    if (array_key_exists($k, $json) && $json[$k] !== null) {
                        return new InstitutionLearnerLookupResult(
                            found: false,
                            status: InstitutionLearnerLookupStatus::InvalidResponse,
                            httpStatus: $httpStatus,
                            rawResponse: $this->sanitizeRaw($json),
                            errorMessage: "Invalid \"{$k}\" for found=false (expected null).",
                        );
                    }
                }
            }

            $sourceReference = is_string($sourceReference) ? (string) $sourceReference : null;
            $confidenceHint = is_numeric($confidenceHint) ? max(0, min(100, (int) $confidenceHint)) : null;
        }

        if ($found === false) {
            $error = $json['error'] ?? null;
            if (array_key_exists('error', $json) && $error !== null && ! is_array($error)) {
                return new InstitutionLearnerLookupResult(
                    found: false,
                    status: InstitutionLearnerLookupStatus::InvalidResponse,
                    httpStatus: $httpStatus,
                    rawResponse: $this->sanitizeRaw($json),
                    errorMessage: 'Invalid "error" object (expected {code, message}).',
                );
            }
            if (is_array($error) && isset($error['code'], $error['message']) && is_string($error['code']) && is_string($error['message'])) {
                return new InstitutionLearnerLookupResult(
                    found: false,
                    status: InstitutionLearnerLookupStatus::Failed,
                    learnerRecordPayload: null,
                    confidenceHint: null,
                    sourceReference: $sourceReference,
                    rawResponse: $this->sanitizeRaw($json),
                    errorMessage: (string) $error['code'].': '.(string) $error['message'],
                    httpStatus: $httpStatus,
                );
            }

            return new InstitutionLearnerLookupResult(
                found: false,
                status: InstitutionLearnerLookupStatus::NotFound,
                learnerRecordPayload: null,
                confidenceHint: null,
                sourceReference: $sourceReference,
                rawResponse: $this->sanitizeRaw($json),
                errorMessage: null,
                httpStatus: $httpStatus,
            );
        }

        $record = $json['record'] ?? null;
        if (! is_array($record)) {
            return new InstitutionLearnerLookupResult(
                found: false,
                status: InstitutionLearnerLookupStatus::InvalidResponse,
                httpStatus: $httpStatus,
                rawResponse: $this->sanitizeRaw($json),
                errorMessage: 'Missing "record" object for found=true.',
            );
        }

        $validationError = $this->validateFoundRecord($record);
        if ($validationError !== null) {
            return new InstitutionLearnerLookupResult(
                found: false,
                status: InstitutionLearnerLookupStatus::InvalidResponse,
                httpStatus: $httpStatus,
                rawResponse: $this->sanitizeRaw($json),
                errorMessage: $validationError,
            );
        }

        return new InstitutionLearnerLookupResult(
            found: true,
            status: InstitutionLearnerLookupStatus::Found,
            learnerRecordPayload: $record,
            confidenceHint: $confidenceHint,
            sourceReference: $sourceReference,
            rawResponse: $this->sanitizeRaw($json),
            httpStatus: $httpStatus,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQualificationPayload(Qualification $qualification): array
    {
        $correlationId = $this->correlationId();

        $nrcOrPassport = trim((string) ($qualification->nrc_passport_number ?? ''));
        $nrcNumber = null;
        $passportNo = null;
        if ($nrcOrPassport !== '') {
            if (str_contains($nrcOrPassport, '/')) {
                $nrcNumber = $nrcOrPassport;
            } else {
                $passportNo = $nrcOrPassport;
            }
        }

        [$firstName, $lastName, $otherNames] = $this->splitName((string) ($qualification->qualification_holder_name ?? ''));

        $institutionName = $qualification->awardingInstitution?->name ?? null;

        return [
            'correlation_id' => $correlationId,
            'qualification_reference' => (string) ($qualification->verification_reference_number ?: ('qualification:'.(int) $qualification->id)),
            'student_id' => $qualification->student_number ?: null,
            'certificate_no' => $qualification->certificate_number ?: null,
            'nrc_number' => $nrcNumber,
            'passport_no' => $passportNo,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'other_names' => $otherNames,
            'program_of_study' => $qualification->title_of_qualification ?: null,
            'year_awarded' => $qualification->award_date ? (int) $qualification->award_date->format('Y') : null,
            'award_date' => $qualification->award_date ? $qualification->award_date->format('Y-m-d') : null,
            'awarding_institution_id' => $qualification->awarding_institution_id ? (int) $qualification->awarding_institution_id : null,
            'awarding_institution_name' => $institutionName,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeJson(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $this->sanitizeRaw($decoded) : null;
        } catch (\Throwable) {
            return ['body' => $this->truncate($body)];
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function sanitizeRaw(array $raw): array
    {
        array_walk_recursive($raw, function (&$v, $k) {
            if (! is_string($k)) {
                return;
            }
            if (in_array($k, ['nrc_number', 'passport_no'], true) && is_string($v)) {
                $v = $this->maskString($v);
            }
        });

        return $raw;
    }

    private function truncate(string $v): string
    {
        return strlen($v) > 2000 ? substr($v, 0, 2000).'…' : $v;
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

    private function correlationId(): string
    {
        try {
            $req = request();
            $attr = $req->attributes->get('correlation_id');
            if (is_string($attr) && $attr !== '') {
                return $attr;
            }
        } catch (\Throwable) {
            // ignore
        }

        return (string) Str::uuid();
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function splitName(string $fullName): array
    {
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName) ?? $fullName);
        if ($fullName === '') {
            return [null, null, null];
        }

        $parts = array_values(array_filter(explode(' ', $fullName), fn ($p) => trim($p) !== ''));
        if (count($parts) === 1) {
            return [$parts[0], null, null];
        }

        $first = $parts[0];
        $last = $parts[count($parts) - 1];
        $middle = array_slice($parts, 1, -1);

        return [$first ?: null, $last ?: null, $middle !== [] ? implode(' ', $middle) : null];
    }

    /**
     * Validate the standard ZAQA pull lookup "found=true" record shape.
     *
     * @param  array<string, mixed>  $record
     */
    private function validateFoundRecord(array $record): ?string
    {
        $requiredStrings = ['first_name', 'last_name', 'program_of_study'];
        foreach ($requiredStrings as $k) {
            $v = $record[$k] ?? null;
            if (! is_string($v) || trim($v) === '') {
                return "Missing required record field: {$k}.";
            }
        }

        $year = $record['year_awarded'] ?? null;
        if (! is_int($year) && ! (is_numeric($year) && (string) (int) $year === (string) $year)) {
            return 'Missing or invalid record field: year_awarded.';
        }

        $identifiers = [
            trim((string) ($record['student_id'] ?? '')),
            trim((string) ($record['certificate_no'] ?? '')),
            trim((string) ($record['nrc_number'] ?? '')),
            trim((string) ($record['passport_no'] ?? '')),
        ];
        $hasIdentifier = false;
        foreach ($identifiers as $id) {
            if ($id !== '') {
                $hasIdentifier = true;
                break;
            }
        }
        if (! $hasIdentifier) {
            return 'Record must include at least one identifier (student_id, certificate_no, nrc_number, passport_no).';
        }

        $awardDate = $record['award_date'] ?? null;
        if ($awardDate !== null && $awardDate !== '' && ! is_string($awardDate)) {
            return 'Invalid record field: award_date.';
        }

        return null;
    }
}
