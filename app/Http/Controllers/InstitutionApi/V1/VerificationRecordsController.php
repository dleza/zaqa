<?php

namespace App\Http\Controllers\InstitutionApi\V1;

use App\Domain\Audit\AuditLogService;
use App\Domain\Verification\VerificationReferenceLookupService;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerificationReferenceLookupRequest;
use App\Models\InstitutionApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationRecordsController extends Controller
{
    public function lookup(
        VerificationReferenceLookupRequest $request,
        VerificationReferenceLookupService $lookup,
        AuditLogService $audit,
    ): JsonResponse {
        /** @var InstitutionApiClient $client */
        $client = $request->user();

        $applicationReference = (string) $request->query('application_reference', '');
        $qualificationReference = (string) $request->query('qualification_reference', '');

        $result = $lookup->lookup(
            $applicationReference,
            $qualificationReference,
            (int) $client->awarding_institution_id,
        );

        $audit->record(
            eventType: 'integrated_verification_lookup.performed',
            module: 'Verification',
            actionName: 'integrated_verification_lookup',
            message: 'Integrated awarding institution performed a verification reference lookup.',
            entityType: InstitutionApiClient::class,
            entityId: (int) $client->id,
            metadata: [
                'awarding_institution_id' => (int) $client->awarding_institution_id,
                'searched_by' => $result['searched_by'] ?? null,
                'found' => (bool) ($result['found'] ?? false),
                'application_reference' => $applicationReference !== '' ? $applicationReference : null,
                'qualification_reference' => $qualificationReference !== '' ? $qualificationReference : null,
                'status' => $result['status'] ?? null,
            ],
            actor: null,
        );

        $payload = $lookup->apiPayload($result);

        return response()->json($payload, ($payload['found'] ?? false) ? 200 : 404);
    }
}
