<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Enums\LearnerRecordSourceType;
use App\Enums\QualificationTitleSource;
use App\Models\AwardingInstitution;
use App\Models\LearnerRecord;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\QualificationTitle;
use App\Models\User;
use App\Support\Normalization\LearnerRecordNormalizer;
use Illuminate\Support\Facades\DB;

class VerifiedQualificationIngestionService
{
    public function __construct(
        private readonly QualificationTitleCatalogStatus $catalogStatus,
        private readonly AwardingInstitutionCatalogStatus $institutionCatalogStatus,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * After certificate issue: promote institution and title to catalogs and ensure a learner record exists.
     *
     * @return array{
     *     learner_record_id: int|null,
     *     learner_record_created: bool,
     *     qualification_title_id: int|null,
     *     qualification_title_created: bool,
     *     awarding_institution_id: int|null,
     *     awarding_institution_created: bool,
     * }
     */
    public function ingestFromIssuedCertificate(
        Qualification $qualification,
        QualificationCertificate $certificate,
        User $actor,
    ): array {
        return DB::transaction(function () use ($qualification, $certificate, $actor) {
            $qualification->refresh();
            $qualification->loadMissing('awardingInstitution');

            $hadInstitutionId = (bool) $qualification->awarding_institution_id;

            $institutionPromotion = $this->promoteInstitutionToCatalog($qualification);
            if ($institutionPromotion['awarding_institution_id'] && ! $qualification->awarding_institution_id) {
                $qualification->forceFill([
                    'awarding_institution_id' => $institutionPromotion['awarding_institution_id'],
                    'awarding_institution_name' => $institutionPromotion['awarding_institution_name'],
                    'awarding_institution_name_other' => null,
                ]);
                $qualification->loadMissing('awardingInstitution');
            }

            $titleText = $this->catalogStatus->resolveTitleText($qualification);
            $titlePromotion = $this->promoteTitleToCatalog($qualification, $titleText);
            $learnerRecordResult = $this->ensureLearnerRecord($qualification, $certificate, $titleText);

            $qualificationUpdates = [];
            if ($institutionPromotion['awarding_institution_id'] && ! $hadInstitutionId) {
                $qualificationUpdates['awarding_institution_id'] = $institutionPromotion['awarding_institution_id'];
                $qualificationUpdates['awarding_institution_name'] = $institutionPromotion['awarding_institution_name'];
                $qualificationUpdates['awarding_institution_name_other'] = null;
            }
            if ($titleText !== '' && trim((string) ($qualification->verified_qualification_title ?? '')) === '') {
                $qualificationUpdates['verified_qualification_title'] = $titleText;
            }
            if ($titlePromotion['qualification_title_id'] && ! $qualification->qualification_title_id) {
                $qualificationUpdates['qualification_title_id'] = $titlePromotion['qualification_title_id'];
            }
            if ($titlePromotion['qualification_title_created']
                && $qualification->qualification_title_source === QualificationTitleSource::Other) {
                $qualificationUpdates['qualification_title_source'] = QualificationTitleSource::VerifiedPromoted;
            }
            if ($learnerRecordResult['learner_record_id'] && ! $qualification->learner_record_id) {
                $qualificationUpdates['learner_record_id'] = $learnerRecordResult['learner_record_id'];
            }

            if ($qualificationUpdates !== []) {
                $before = $qualification->only(array_keys($qualificationUpdates));
                $qualification->forceFill($qualificationUpdates)->save();

                $this->audit->record(
                    eventType: 'verification.qualification_ingested',
                    module: 'Verification',
                    actionName: 'verified_qualification_ingested',
                    message: 'Verified qualification data ingested into learner records, title catalog, and awarding institutions.',
                    entityType: Qualification::class,
                    entityId: $qualification->id,
                    beforeState: $before,
                    afterState: $qualification->only(array_keys($qualificationUpdates)),
                    metadata: [
                        'certificate_id' => $certificate->id,
                        'learner_record_created' => $learnerRecordResult['learner_record_created'],
                        'qualification_title_created' => $titlePromotion['qualification_title_created'],
                        'awarding_institution_created' => $institutionPromotion['awarding_institution_created'],
                    ],
                    actor: $actor,
                );
            }

            return [
                'learner_record_id' => $learnerRecordResult['learner_record_id'],
                'learner_record_created' => $learnerRecordResult['learner_record_created'],
                'qualification_title_id' => $titlePromotion['qualification_title_id'],
                'qualification_title_created' => $titlePromotion['qualification_title_created'],
                'awarding_institution_id' => $institutionPromotion['awarding_institution_id'],
                'awarding_institution_created' => $institutionPromotion['awarding_institution_created'],
            ];
        });
    }

    /**
     * @return array{
     *     awarding_institution_id: int|null,
     *     awarding_institution_created: bool,
     *     awarding_institution_name: string|null,
     * }
     */
    private function promoteInstitutionToCatalog(Qualification $qualification): array
    {
        if ($qualification->awarding_institution_id) {
            return [
                'awarding_institution_id' => (int) $qualification->awarding_institution_id,
                'awarding_institution_created' => false,
                'awarding_institution_name' => $qualification->awardingInstitution?->name
                    ?? $qualification->awarding_institution_name,
            ];
        }

        $name = $this->institutionCatalogStatus->resolveInstitutionName($qualification);
        if ($name === '' || ! $qualification->country_id) {
            return [
                'awarding_institution_id' => null,
                'awarding_institution_created' => false,
                'awarding_institution_name' => null,
            ];
        }

        $normalized = AwardingInstitutionCatalogStatus::normalizeName($name);
        $existing = AwardingInstitution::query()
            ->where('country_id', (int) $qualification->country_id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->first();

        if ($existing instanceof AwardingInstitution) {
            return [
                'awarding_institution_id' => (int) $existing->id,
                'awarding_institution_created' => false,
                'awarding_institution_name' => $existing->name,
            ];
        }

        $created = AwardingInstitution::query()->create([
            'country_id' => (int) $qualification->country_id,
            'name' => $name,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return [
            'awarding_institution_id' => (int) $created->id,
            'awarding_institution_created' => true,
            'awarding_institution_name' => $created->name,
        ];
    }

    /**
     * @return array{qualification_title_id: int|null, qualification_title_created: bool}
     */
    private function promoteTitleToCatalog(Qualification $qualification, string $titleText): array
    {
        if ($titleText === '') {
            return ['qualification_title_id' => null, 'qualification_title_created' => false];
        }

        if ($qualification->qualification_title_id) {
            $this->linkInstitutionToTitle((int) $qualification->qualification_title_id, $qualification->awarding_institution_id);

            return [
                'qualification_title_id' => (int) $qualification->qualification_title_id,
                'qualification_title_created' => false,
            ];
        }

        $normalized = QualificationTitle::normalizeName($titleText);
        $existing = $normalized !== ''
            ? QualificationTitle::query()->where('name_normalized', $normalized)->first()
            : null;

        if ($existing instanceof QualificationTitle) {
            $this->linkInstitutionToTitle((int) $existing->id, $qualification->awarding_institution_id);

            return [
                'qualification_title_id' => (int) $existing->id,
                'qualification_title_created' => false,
            ];
        }

        $created = QualificationTitle::query()->create([
            'name' => $titleText,
            'qualification_type_id' => $qualification->qualification_type_id,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->linkInstitutionToTitle((int) $created->id, $qualification->awarding_institution_id);

        return [
            'qualification_title_id' => (int) $created->id,
            'qualification_title_created' => true,
        ];
    }

    /**
     * @return array{learner_record_id: int|null, learner_record_created: bool}
     */
    private function ensureLearnerRecord(
        Qualification $qualification,
        QualificationCertificate $certificate,
        string $titleText,
    ): array {
        if ($qualification->learner_record_id) {
            $record = LearnerRecord::query()->find($qualification->learner_record_id);
            if ($record instanceof LearnerRecord) {
                $this->enrichLearnerRecord($record, $qualification, $certificate, $titleText);

                return [
                    'learner_record_id' => (int) $record->id,
                    'learner_record_created' => false,
                ];
            }
        }

        $payload = $this->buildLearnerRecordPayload($qualification, $certificate, $titleText);
        if ($payload === null) {
            return ['learner_record_id' => null, 'learner_record_created' => false];
        }

        $hash = $payload['dedupe_hash'] ?? null;
        $existing = ($hash && is_string($hash))
            ? LearnerRecord::query()->where('dedupe_hash', $hash)->first()
            : null;

        if ($existing instanceof LearnerRecord) {
            $existing->fill($payload)->save();

            return [
                'learner_record_id' => (int) $existing->id,
                'learner_record_created' => false,
            ];
        }

        $record = LearnerRecord::query()->create($payload);

        return [
            'learner_record_id' => (int) $record->id,
            'learner_record_created' => true,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildLearnerRecordPayload(
        Qualification $qualification,
        QualificationCertificate $certificate,
        string $titleText,
    ): ?array {
        $institutionId = $qualification->awarding_institution_id ? (int) $qualification->awarding_institution_id : null;
        $studentId = $this->nullableString($qualification->student_number);
        $certificateNo = $this->nullableString($qualification->certificate_number);
        $holderId = $this->nullableString($qualification->nrc_passport_number);
        $awardDate = $qualification->award_date?->format('Y-m-d');
        $yearAwarded = $qualification->award_date ? (int) $qualification->award_date->format('Y') : null;

        [$firstName, $lastName, $otherNames] = $this->splitHolderName(
            (string) ($qualification->qualification_holder_name ?? ''),
        );

        $nrc = null;
        $passport = null;
        if ($holderId !== null) {
            if (LearnerRecordNormalizer::normalizeNrc($holderId)) {
                $nrc = $holderId;
            } elseif (LearnerRecordNormalizer::normalizePassport($holderId)) {
                $passport = $holderId;
            } else {
                $nrc = $holderId;
            }
        }

        $studentIdNorm = LearnerRecordNormalizer::normalizeStudentId($studentId);
        $certNorm = LearnerRecordNormalizer::normalizeCertificateNo($certificateNo);
        $nrcNorm = LearnerRecordNormalizer::normalizeNrc($nrc);
        $passportNorm = LearnerRecordNormalizer::normalizePassport($passport);
        $nameNorm = LearnerRecordNormalizer::normalizeNameParts($firstName, $otherNames, $lastName);
        $titleNorm = LearnerRecordNormalizer::normalizeProgramTitle($titleText !== '' ? $titleText : $qualification->title_of_qualification);

        if (! $studentIdNorm && ! $certNorm && ! $nrcNorm && ! $passportNorm) {
            return null;
        }

        $hash = LearnerRecordNormalizer::dedupeHash(
            awardingInstitutionId: $institutionId,
            certificateNoNormalized: $certNorm,
            studentIdNormalized: $studentIdNorm,
            yearAwarded: $yearAwarded,
        );

        return [
            'awarding_institution_id' => $institutionId,
            'import_id' => null,
            'institution_name_raw' => $institutionId ? null : $this->nullableString(
                $qualification->awarding_institution_name_other ?: $qualification->awarding_institution_name,
            ),
            'student_id' => $studentId,
            'certificate_no' => $certificateNo,
            'nrc_number' => $nrc,
            'passport_no' => $passport,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'other_names' => $otherNames,
            'gender' => null,
            'program_of_study' => $titleText !== '' ? $titleText : $qualification->title_of_qualification,
            'qualification_title_normalized' => $titleNorm,
            'year_awarded' => $yearAwarded,
            'award_date' => $awardDate,
            'source_type' => LearnerRecordSourceType::ZaqaVerification->value,
            'source_reference' => 'qualification:'.$qualification->id.'|certificate:'.$certificate->certificate_number,
            'raw_payload' => [
                'qualification_id' => $qualification->id,
                'application_id' => $qualification->application_id,
                'certificate_id' => $certificate->id,
            ],
            'nrc_normalized' => $nrcNorm,
            'passport_normalized' => $passportNorm,
            'name_normalized' => $nameNorm,
            'student_id_normalized' => $studentIdNorm,
            'certificate_no_normalized' => $certNorm,
            'dedupe_hash' => $hash,
            'is_active' => true,
            'verified_at' => now(),
        ];
    }

    private function enrichLearnerRecord(
        LearnerRecord $record,
        Qualification $qualification,
        QualificationCertificate $certificate,
        string $titleText,
    ): void {
        $updates = [];
        if ($titleText !== '' && trim((string) ($record->program_of_study ?? '')) === '') {
            $updates['program_of_study'] = $titleText;
            $updates['qualification_title_normalized'] = LearnerRecordNormalizer::normalizeProgramTitle($titleText);
        }
        if (! $record->verified_at) {
            $updates['verified_at'] = now();
        }
        if (! $record->source_reference) {
            $updates['source_reference'] = 'qualification:'.$qualification->id.'|certificate:'.$certificate->certificate_number;
        }

        if ($updates !== []) {
            $record->forceFill($updates)->save();
        }
    }

    private function linkInstitutionToTitle(int $titleId, mixed $institutionId): void
    {
        if (! is_numeric($institutionId) || (int) $institutionId < 1) {
            return;
        }

        $title = QualificationTitle::query()->find($titleId);
        if (! $title) {
            return;
        }

        $title->awardingInstitutions()->syncWithoutDetaching([(int) $institutionId]);
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function splitHolderName(string $fullName): array
    {
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName) ?? '');
        if ($fullName === '') {
            return [null, null, null];
        }

        $parts = explode(' ', $fullName);
        if (count($parts) === 1) {
            return [$parts[0], null, null];
        }

        $firstName = array_shift($parts);
        $lastName = array_pop($parts);
        $otherNames = $parts !== [] ? implode(' ', $parts) : null;

        return [$firstName, $lastName, $otherNames];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
