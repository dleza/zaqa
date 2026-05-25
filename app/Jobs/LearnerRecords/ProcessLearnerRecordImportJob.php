<?php

namespace App\Jobs\LearnerRecords;

use App\Domain\LearnerRecords\LearnerRecordExcelImportProcessor;
use App\Enums\LearnerRecordImportStatus;
use App\Models\LearnerRecordImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessLearnerRecordImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $importId,
    ) {
    }

    public function handle(LearnerRecordExcelImportProcessor $processor): void
    {
        $import = LearnerRecordImport::query()->find($this->importId);
        if (! $import) {
            return;
        }

        if ($import->status?->isTerminal()) {
            return;
        }

        DB::transaction(function () use ($import) {
            $locked = LearnerRecordImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($locked->status?->isTerminal()) {
                return;
            }

            $locked->forceFill([
                'status' => LearnerRecordImportStatus::Processing,
                'started_at' => $locked->started_at ?? now(),
            ])->save();
        });

        try {
            $processor->process($import->fresh());
        } catch (\Throwable $e) {
            DB::transaction(function () use ($import, $e) {
                $locked = LearnerRecordImport::query()->lockForUpdate()->findOrFail($import->id);
                if ($locked->status?->isTerminal()) {
                    return;
                }

                $errors = is_array($locked->errors) ? $locked->errors : [];
                $errors[] = [
                    'type' => 'exception',
                    'message' => 'Import failed: '.$e->getMessage(),
                ];

                $locked->forceFill([
                    'status' => LearnerRecordImportStatus::Failed,
                    'errors' => $errors,
                    'completed_at' => now(),
                ])->save();
            });

            throw $e;
        }
    }
}

