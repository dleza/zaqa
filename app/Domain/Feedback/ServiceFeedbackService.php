<?php

namespace App\Domain\Feedback;

use App\Domain\Audit\AuditLogService;
use App\Models\Application;
use App\Models\ServiceFeedback;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceFeedbackService
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param array{rating_value:int, rating_label?:string|null, feedback_text?:string|null, source?:string|null, source_step?:string|null, metadata?:array<string,mixed>|null} $data
     */
    public function submit(Application $application, User $actor, array $data): ServiceFeedback
    {
        return DB::transaction(function () use ($application, $actor, $data) {
            $existing = ServiceFeedback::query()
                ->where('application_id', $application->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->audit->record(
                    'feedback.duplicate_prevented',
                    'Feedback',
                    'duplicate_prevented',
                    'Duplicate feedback prevented for application.',
                    ServiceFeedback::class,
                    $existing->id,
                    null,
                    null,
                    [
                        'application_id' => $application->id,
                    ],
                    $actor,
                );

                throw ValidationException::withMessages([
                    'feedback' => 'Feedback has already been submitted for this application.',
                ]);
            }

            $feedback = ServiceFeedback::create([
                'application_id' => $application->id,
                'applicant_user_id' => $actor->id,
                'rating_value' => (int) $data['rating_value'],
                'rating_label' => $data['rating_label'] ?? null,
                'feedback_text' => $data['feedback_text'] ?? null,
                'source' => $data['source'] ?? 'applicant_submission_flow',
                'source_step' => $data['source_step'] ?? 'review_and_submit',
                'metadata' => $data['metadata'] ?? null,
                'submitted_at' => now(),
            ]);

            $this->audit->record(
                'feedback.submitted',
                'Feedback',
                'submitted',
                'Service feedback submitted.',
                ServiceFeedback::class,
                $feedback->id,
                null,
                null,
                [
                    'application_id' => $application->id,
                    'rating_value' => $feedback->rating_value,
                    'source' => $feedback->source,
                    'source_step' => $feedback->source_step,
                ],
                $actor,
            );

            return $feedback;
        });
    }

    public function recordPromptShown(Application $application, User $actor): void
    {
        $this->audit->record(
            'feedback.prompt_shown',
            'Feedback',
            'prompt_shown',
            'Service feedback prompt shown.',
            Application::class,
            $application->id,
            null,
            null,
            [
                'source' => 'applicant_submission_flow',
                'source_step' => 'review_and_submit',
            ],
            $actor,
        );
    }

    public function recordSkipped(Application $application, User $actor): void
    {
        $this->audit->record(
            'feedback.skipped',
            'Feedback',
            'skipped',
            'Service feedback skipped.',
            Application::class,
            $application->id,
            null,
            null,
            [
                'source' => 'applicant_submission_flow',
                'source_step' => 'review_and_submit',
            ],
            $actor,
        );
    }
}

