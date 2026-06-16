<?php

namespace App\Domain\LearnerRecords;

use App\Models\LearnerRecordSubmissionBatch;
use Illuminate\Support\Facades\DB;

class LearnerRecordSubmissionBatchReferenceGenerator
{
    public function generate(): string
    {
        return DB::transaction(function () {
            $year = (int) now()->format('Y');
            $prefix = "LRB-{$year}-";

            $lastReference = LearnerRecordSubmissionBatch::query()
                ->where('reference', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('reference');

            $next = 1;
            if (is_string($lastReference) && preg_match('/^LRB-\d{4}-(\d+)$/', $lastReference, $matches)) {
                $next = ((int) $matches[1]) + 1;
            }

            return sprintf('%s%04d', $prefix, $next);
        });
    }
}
