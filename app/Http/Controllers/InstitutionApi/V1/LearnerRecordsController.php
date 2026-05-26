<?php

namespace App\Http\Controllers\InstitutionApi\V1;

use App\Domain\InstitutionApi\InstitutionLearnerRecordIngestionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\InstitutionApi\V1\BatchInstitutionLearnerRecordsRequest;
use App\Http\Requests\InstitutionApi\V1\SearchInstitutionLearnerRecordsRequest;
use App\Http\Requests\InstitutionApi\V1\StoreInstitutionLearnerRecordRequest;
use App\Jobs\InstitutionApi\ProcessInstitutionLearnerRecordBatchJob;
use App\Models\InstitutionApiBatch;
use App\Models\InstitutionApiClient;
use App\Models\LearnerRecord;
use App\Support\Normalization\LearnerRecordNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LearnerRecordsController extends Controller
{
    public function store(
        StoreInstitutionLearnerRecordRequest $request,
        InstitutionLearnerRecordIngestionService $ingestion
    ): JsonResponse {
        /** @var InstitutionApiClient $client */
        $client = $request->user();

        $res = $ingestion->upsertOne($client, $request->validated());
        $record = $res['record'];

        return response()->json([
            'success' => true,
            'data' => [
                'learner_record_id' => (int) $record->id,
                'created' => (bool) ($res['created'] ?? false),
            ],
        ], 201);
    }

    public function batch(
        BatchInstitutionLearnerRecordsRequest $request
    ): JsonResponse {
        /** @var InstitutionApiClient $client */
        $client = $request->user();

        $records = $request->validated('records');
        $records = is_array($records) ? array_values($records) : [];

        $max = (int) (config('institution_api.max_batch_size', 500) ?: 500);
        if (count($records) > $max) {
            return response()->json([
                'success' => false,
                'message' => 'Batch too large.',
            ], 422);
        }

        $syncThreshold = (int) (config('institution_api.batch_sync_threshold', 50) ?: 50);

        $batch = InstitutionApiBatch::query()->create([
            'institution_api_client_id' => (int) $client->id,
            'awarding_institution_id' => (int) $client->awarding_institution_id,
            'status' => 'pending',
            'records_json' => json_encode($records, JSON_UNESCAPED_UNICODE),
            'total_records' => count($records),
        ]);

        if (count($records) <= $syncThreshold) {
            // Process quickly inline for very small batches.
            ProcessInstitutionLearnerRecordBatchJob::dispatchSync((int) $batch->id);
            $batch->refresh();

            return response()->json([
                'success' => true,
                'data' => [
                    'batch_id' => (int) $batch->id,
                    'status' => $batch->status?->value ?? 'unknown',
                    'total' => (int) $batch->total_records,
                    'processed' => (int) $batch->processed_records,
                    'inserted' => (int) $batch->inserted_records,
                    'updated' => (int) $batch->updated_records,
                    'failed' => (int) $batch->failed_records,
                ],
            ], 200);
        }

        ProcessInstitutionLearnerRecordBatchJob::dispatch((int) $batch->id);

        return response()->json([
            'success' => true,
            'data' => [
                'batch_id' => (int) $batch->id,
                'status' => $batch->status?->value ?? 'pending',
            ],
        ], 202);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $awardingInstitutionId = (int) $request->attributes->get('awarding_institution_id');

        $record = LearnerRecord::query()
            ->whereKey($id)
            ->where('awarding_institution_id', $awardingInstitutionId)
            ->first();

        if (! $record) {
            return response()->json([
                'success' => false,
                'message' => 'Learner record not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $record->id,
                'student_id' => $record->student_id,
                'certificate_no' => $record->certificate_no,
                'first_name' => $record->first_name,
                'last_name' => $record->last_name,
                'other_names' => $record->other_names,
                'gender' => $record->gender,
                'nrc_number' => $record->nrc_number,
                'passport_no' => $record->passport_no,
                'program_of_study' => $record->program_of_study,
                'year_awarded' => $record->year_awarded,
                'award_date' => optional($record->award_date)->format('Y-m-d'),
                'source_type' => $record->source_type?->value,
                'source_reference' => $record->source_reference,
                'created_at' => optional($record->created_at)->toIso8601String(),
                'updated_at' => optional($record->updated_at)->toIso8601String(),
            ],
        ]);
    }

    public function search(SearchInstitutionLearnerRecordsRequest $request): JsonResponse
    {
        $awardingInstitutionId = (int) $request->attributes->get('awarding_institution_id');
        $validated = $request->validated();

        $studentIdNorm = LearnerRecordNormalizer::normalizeStudentId($validated['student_id'] ?? null);
        $certNorm = LearnerRecordNormalizer::normalizeCertificateNo($validated['certificate_no'] ?? null);
        $nrcNorm = LearnerRecordNormalizer::normalizeNrc($validated['nrc_number'] ?? null);
        $passportNorm = LearnerRecordNormalizer::normalizePassport($validated['passport_no'] ?? null);
        $titleNorm = LearnerRecordNormalizer::normalizeProgramTitle($validated['program_of_study'] ?? null);
        $year = isset($validated['year_awarded']) ? (int) $validated['year_awarded'] : null;

        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 25;

        $query = LearnerRecord::query()
            ->where('awarding_institution_id', $awardingInstitutionId)
            ->when($studentIdNorm, fn ($q) => $q->where('student_id_normalized', $studentIdNorm))
            ->when($certNorm, fn ($q) => $q->where('certificate_no_normalized', $certNorm))
            ->when($nrcNorm, fn ($q) => $q->where('nrc_normalized', $nrcNorm))
            ->when($passportNorm, fn ($q) => $q->where('passport_normalized', $passportNorm))
            ->when($titleNorm, fn ($q) => $q->where('qualification_title_normalized', $titleNorm))
            ->when($year, fn ($q) => $q->where('year_awarded', $year))
            ->orderByDesc('id');

        $records = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $records->getCollection()->map(fn (LearnerRecord $r) => [
                    'id' => (int) $r->id,
                    'student_id' => $r->student_id,
                    'certificate_no' => $r->certificate_no,
                    'first_name' => $r->first_name,
                    'last_name' => $r->last_name,
                    'other_names' => $r->other_names,
                    'program_of_study' => $r->program_of_study,
                    'year_awarded' => $r->year_awarded,
                    'award_date' => optional($r->award_date)->format('Y-m-d'),
                    'source_type' => $r->source_type?->value,
                ])->values(),
                'pagination' => [
                    'current_page' => $records->currentPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'last_page' => $records->lastPage(),
                ],
            ],
        ]);
    }
}

