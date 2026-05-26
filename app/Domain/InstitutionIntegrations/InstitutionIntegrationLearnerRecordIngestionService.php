<?php

namespace App\Domain\InstitutionIntegrations;

use App\Enums\LearnerRecordSourceType;
use App\Models\LearnerRecord;
use App\Support\Normalization\LearnerRecordNormalizer;

class InstitutionIntegrationLearnerRecordIngestionService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{record: LearnerRecord, created: bool}
     */
    public function upsertFromLookup(int $awardingInstitutionId, array $payload, ?string $sourceReference = null): array
    {
        $studentId = $this->stringOrNull($payload['student_id'] ?? null);
        $certificateNo = $this->stringOrNull($payload['certificate_no'] ?? null);
        $nrc = $this->stringOrNull($payload['nrc_number'] ?? null);
        $passport = $this->stringOrNull($payload['passport_no'] ?? null);
        $firstName = $this->stringOrNull($payload['first_name'] ?? null);
        $lastName = $this->stringOrNull($payload['last_name'] ?? null);
        $otherNames = $this->stringOrNull($payload['other_names'] ?? null);
        $gender = $this->stringOrNull($payload['gender'] ?? null);
        $program = $this->stringOrNull($payload['program_of_study'] ?? null);
        $yearAwarded = isset($payload['year_awarded']) ? (int) $payload['year_awarded'] : null;
        $awardDate = $this->stringOrNull($payload['award_date'] ?? null);

        $studentIdNorm = LearnerRecordNormalizer::normalizeStudentId($studentId);
        $certNorm = LearnerRecordNormalizer::normalizeCertificateNo($certificateNo);
        $nrcNorm = LearnerRecordNormalizer::normalizeNrc($nrc);
        $passportNorm = LearnerRecordNormalizer::normalizePassport($passport);
        $nameNorm = LearnerRecordNormalizer::normalizeNameParts($firstName, $otherNames, $lastName);
        $titleNorm = LearnerRecordNormalizer::normalizeProgramTitle($program);

        $hash = LearnerRecordNormalizer::dedupeHash(
            awardingInstitutionId: $awardingInstitutionId,
            certificateNoNormalized: $certNorm,
            studentIdNormalized: $studentIdNorm,
            yearAwarded: $yearAwarded,
        );

        $data = [
            'awarding_institution_id' => $awardingInstitutionId,
            'import_id' => null,
            'institution_name_raw' => null,
            'student_id' => $studentId,
            'certificate_no' => $certificateNo,
            'nrc_number' => $nrc,
            'passport_no' => $passport,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'other_names' => $otherNames,
            'gender' => $gender,
            'program_of_study' => $program,
            'qualification_title_normalized' => $titleNorm,
            'year_awarded' => $yearAwarded,
            'award_date' => $awardDate,
            'source_type' => LearnerRecordSourceType::InstitutionApi->value,
            'source_reference' => $sourceReference,
            'raw_payload' => $this->safeRawPayload($payload),
            'nrc_normalized' => $nrcNorm,
            'passport_normalized' => $passportNorm,
            'name_normalized' => $nameNorm,
            'student_id_normalized' => $studentIdNorm,
            'certificate_no_normalized' => $certNorm,
            'dedupe_hash' => $hash,
            'is_active' => true,
            'verified_at' => now(),
        ];

        $existing = $hash ? LearnerRecord::query()->where('dedupe_hash', $hash)->first() : null;
        if ($existing instanceof LearnerRecord) {
            $existing->fill($data)->save();
            return ['record' => $existing, 'created' => false];
        }

        $record = LearnerRecord::query()->create($data);

        return ['record' => $record, 'created' => true];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safeRawPayload(array $payload): array
    {
        unset($payload['authorization'], $payload['token']);
        return $payload;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        return $s === '' ? null : $s;
    }
}

