<?php

namespace App\Jobs\InstitutionApi;

use App\Domain\InstitutionApi\InstitutionLearnerRecordIngestionService;
use App\Enums\InstitutionApiBatchStatus;
use App\Models\InstitutionApiBatch;
use App\Models\InstitutionApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessInstitutionLearnerRecordBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const MAX_ERROR_ROWS_STORED = 200;

    public function __construct(public int $batchId)
    {
    }

    public function handle(InstitutionLearnerRecordIngestionService $ingestion): void
    {
        $batch = InstitutionApiBatch::query()->find($this->batchId);
        if (! $batch) {
            return;
        }

        DB::transaction(function () use ($batch) {
            $locked = InstitutionApiBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($locked->status?->isTerminal()) {
                return;
            }

            if ($locked->status === InstitutionApiBatchStatus::Pending) {
                $locked->forceFill([
                    'status' => InstitutionApiBatchStatus::Processing,
                    'started_at' => now(),
                ])->save();
            }
        });

        $batch->refresh();
        if ($batch->status?->isTerminal()) {
            return;
        }

        $client = InstitutionApiClient::query()->find($batch->institution_api_client_id);
        if (! $client || ! $client->is_active) {
            $this->failBatch($batch, 'Client is missing or disabled.');
            return;
        }

        $records = $this->decodeRecords($batch->records_json);
        if ($records === null) {
            $this->failBatch($batch, 'Batch payload is missing or invalid JSON.');
            return;
        }

        $inserted = 0;
        $updated = 0;
        $failed = 0;
        $processed = 0;
        $errors = [];

        foreach ($records as $idx => $payload) {
            $processed++;
            try {
                if (! is_array($payload)) {
                    $failed++;
                    $errors[] = ['row' => $idx + 1, 'message' => 'Invalid record object.'];
                    continue;
                }

                $res = $ingestion->upsertOne($client, $payload);
                $created = (bool) ($res['created'] ?? false);
                if ($created) {
                    $inserted++;
                } else {
                    $updated++;
                }
            } catch (\Throwable $e) {
                $failed++;
                if (count($errors) < self::MAX_ERROR_ROWS_STORED) {
                    $errors[] = ['row' => $idx + 1, 'message' => $e->getMessage()];
                }
            }

            if ($processed % 50 === 0) {
                $this->progress($batch->id, $processed, $inserted, $updated, $failed, $errors);
            }
        }

        $this->progress($batch->id, $processed, $inserted, $updated, $failed, $errors);

        DB::transaction(function () use ($batch) {
            $locked = InstitutionApiBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($locked->status?->isTerminal()) {
                return;
            }

            $status = ((int) $locked->failed_records) > 0
                ? InstitutionApiBatchStatus::CompletedWithErrors
                : InstitutionApiBatchStatus::Completed;

            $locked->forceFill([
                'status' => $status,
                'completed_at' => now(),
            ])->save();
        });
    }

    private function progress(int $batchId, int $processed, int $inserted, int $updated, int $failed, array $errors): void
    {
        DB::transaction(function () use ($batchId, $processed, $inserted, $updated, $failed, $errors) {
            $locked = InstitutionApiBatch::query()->lockForUpdate()->findOrFail($batchId);
            if ($locked->status?->isTerminal()) {
                return;
            }

            $locked->forceFill([
                'processed_records' => $processed,
                'inserted_records' => $inserted,
                'updated_records' => $updated,
                'failed_records' => $failed,
                'errors' => $errors !== [] ? $errors : null,
            ])->save();
        });
    }

    private function failBatch(InstitutionApiBatch $batch, string $message): void
    {
        DB::transaction(function () use ($batch, $message) {
            $locked = InstitutionApiBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($locked->status?->isTerminal()) {
                return;
            }

            $errors = is_array($locked->errors) ? $locked->errors : [];
            $errors[] = ['type' => 'error', 'message' => $message];

            $locked->forceFill([
                'status' => InstitutionApiBatchStatus::Failed,
                'errors' => $errors,
                'completed_at' => now(),
            ])->save();
        });
    }

    /**
     * @return array<int, mixed>|null
     */
    private function decodeRecords(?string $json): ?array
    {
        $json = is_string($json) ? trim($json) : '';
        if ($json === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? array_values($decoded) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

