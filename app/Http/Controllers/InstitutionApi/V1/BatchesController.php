<?php

namespace App\Http\Controllers\InstitutionApi\V1;

use App\Http\Controllers\Controller;
use App\Models\InstitutionApiBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchesController extends Controller
{
    public function show(Request $request, int $batchId): JsonResponse
    {
        $clientId = (int) $request->attributes->get('institution_api_client_id');

        $batch = InstitutionApiBatch::query()
            ->whereKey($batchId)
            ->where('institution_api_client_id', $clientId)
            ->first();

        if (! $batch) {
            return response()->json([
                'success' => false,
                'message' => 'Batch not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $batch->id,
                'status' => $batch->status?->value ?? 'unknown',
                'total' => (int) $batch->total_records,
                'processed' => (int) $batch->processed_records,
                'inserted' => (int) $batch->inserted_records,
                'updated' => (int) $batch->updated_records,
                'failed' => (int) $batch->failed_records,
                'errors' => $batch->errors,
                'started_at' => optional($batch->started_at)->toIso8601String(),
                'completed_at' => optional($batch->completed_at)->toIso8601String(),
                'created_at' => optional($batch->created_at)->toIso8601String(),
            ],
        ]);
    }
}

