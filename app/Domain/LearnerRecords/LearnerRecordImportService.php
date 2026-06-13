<?php

namespace App\Domain\LearnerRecords;

use App\Enums\LearnerRecordImportStatus;
use App\Jobs\LearnerRecords\ProcessLearnerRecordImportJob;
use App\Models\LearnerRecordImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LearnerRecordImportService
{
    public function createAndDispatch(UploadedFile $file, User $actor, int $awardingInstitutionId): LearnerRecordImport
    {
        $disk = config('filesystems.default', 'local');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'xlsx');
        $storedName = 'learner_records_'.now()->format('Ymd_His').'_'.Str::random(12).'.'.$ext;
        $directory = 'private/learner-record-imports/uploads/'.now()->format('Y/m');
        $path = $file->storeAs($directory, $storedName, ['disk' => $disk]);

        $import = DB::transaction(function () use ($actor, $awardingInstitutionId, $path, $file) {
            return LearnerRecordImport::query()->create([
                'uploaded_by_user_id' => $actor->id,
                'awarding_institution_id' => $awardingInstitutionId,
                'file_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'status' => LearnerRecordImportStatus::Pending,
                'total_rows' => null,
                'processed_rows' => 0,
                'inserted_rows' => 0,
                'updated_rows' => 0,
                'failed_rows' => 0,
                'errors' => null,
                'started_at' => null,
                'completed_at' => null,
            ]);
        });

        ProcessLearnerRecordImportJob::dispatch((int) $import->id);

        return $import;
    }

    public function deleteFileIfExists(LearnerRecordImport $import): void
    {
        $disk = config('filesystems.default', 'local');
        $path = (string) ($import->file_path ?? '');
        if ($path !== '' && Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }
}

