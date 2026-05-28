<?php

namespace App\Jobs\InstitutionIntegrations;

use App\Domain\Audit\AuditLogService;
use App\Domain\InstitutionIntegrations\InstitutionPullLookupService;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class PerformInstitutionPullLookupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $qualificationId,
        public readonly bool $manualRecheck = false,
        public readonly ?string $resumeState = null,
        public readonly ?int $resumeAssigneeId = null,
    ) {}

    public function handle(InstitutionPullLookupService $lookup, AuditLogService $audit): void
    {
        $qualification = Qualification::query()
            ->with(['awardingInstitution.integration', 'application'])
            ->find($this->qualificationId);

        if (! $qualification) {
            return;
        }

        if (! $this->manualRecheck && $qualification->verification_state !== VerificationState::AwaitingAutoVerification) {
            return;
        }

        $result = $lookup->lookup($qualification);

        DB::transaction(function () use ($qualification, $result) {
            $locked = Qualification::query()->lockForUpdate()->findOrFail($qualification->id);
            if (! $this->manualRecheck && $locked->verification_state !== VerificationState::AwaitingAutoVerification) {
                return;
            }

            $locked->forceFill([
                'institution_pull_lookup_attempted_at' => now(),
                'institution_pull_lookup_status' => $result->status->value,
                'institution_pull_lookup_last_error' => $result->errorMessage,
            ])->save();
        });

        $audit->record(
            eventType: 'institution_pull.lookup',
            module: 'Integrations',
            actionName: 'institution_pull_lookup',
            message: $result->found ? 'Institution pull lookup returned a record.' : 'Institution pull lookup did not return a record.',
            entityType: Qualification::class,
            entityId: (int) $qualification->id,
            metadata: [
                'application_id' => (int) $qualification->application_id,
                'awarding_institution_id' => (int) $qualification->awarding_institution_id,
                'status' => $result->status->value,
                'http_status' => $result->httpStatus,
            ],
        );

        // Always re-run auto-verification after pull attempt; it will either match using newly ingested records
        // or fall back to Level 1 when pull didn't help (and will update application state accordingly).
        \App\Jobs\Verification\ProcessQualificationAutoVerificationJob::dispatch(
            (int) $qualification->id,
            $this->manualRecheck,
            $this->resumeState,
            $this->resumeAssigneeId,
        );
    }
}
