<?php

use App\Http\Controllers\InstitutionApi\V1\BatchesController;
use App\Http\Controllers\InstitutionApi\V1\LearnerRecordsController;
use App\Http\Controllers\InstitutionApi\V1\VerificationRecordsController;
use App\Http\Middleware\EnsureInstitutionApiClient;
use App\Http\Middleware\ForceSanctumBearerToken;
use App\Http\Middleware\LogInstitutionApiTraffic;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| This application primarily serves web (Inertia) routes. The API route file
| exists to provide isolated, token-authenticated endpoints for integrations.
|
*/

Route::prefix('institution/v1')
    ->middleware([
        ForceSanctumBearerToken::class,
        'auth:institution-api',
        EnsureInstitutionApiClient::class,
        LogInstitutionApiTraffic::class,
        'throttle:institution-api',
    ])
    ->group(function () {
        Route::post('/learner-records', [LearnerRecordsController::class, 'store'])
            ->middleware('abilities:learner-records:write');

        Route::post('/learner-records/batch', [LearnerRecordsController::class, 'batch'])
            ->middleware('abilities:learner-records:batch');

        Route::get('/learner-records/search', [LearnerRecordsController::class, 'search'])
            ->middleware('abilities:learner-records:lookup');

        Route::get('/verification-records/lookup', [VerificationRecordsController::class, 'lookup'])
            ->middleware('abilities:verification-records:lookup');

        Route::get('/learner-records/{id}', [LearnerRecordsController::class, 'show'])
            ->whereNumber('id')
            ->middleware('abilities:learner-records:read');

        Route::get('/batches/{batchId}', [BatchesController::class, 'show'])
            ->whereNumber('batchId')
            ->middleware('abilities:learner-records:status');
    });
