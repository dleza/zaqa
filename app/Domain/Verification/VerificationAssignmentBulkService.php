<?php

namespace App\Domain\Verification;

use App\Models\Qualification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class VerificationAssignmentBulkService
{
    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly QualificationsPoolService $pool,
    ) {}

    /**
     * @param  list<int>  $qualificationIds
     * @return array{assigned: int, skipped: int, errors: list<array{id: int, message: string}>}
     */
    public function bulkAssignLevel1(User $actor, User $assignee, array $qualificationIds, ?string $comment = null): array
    {
        $eligibleIds = collect($this->pool->filterQualificationIdsInAwaitingLevel1Assignment($qualificationIds))
            ->flip();

        return $this->processBulk(
            $qualificationIds,
            $eligibleIds,
            function (Qualification $qualification) use ($actor, $assignee, $comment) {
                $this->assignments->assign($qualification, $actor, $assignee, $comment);
            },
        );
    }

    /**
     * @param  list<int>  $qualificationIds
     * @return array{assigned: int, skipped: int, errors: list<array{id: int, message: string}>}
     */
    public function bulkAssignLevel2(User $actor, User $assignee, array $qualificationIds, ?string $comment = null): array
    {
        $eligibleIds = collect($this->pool->filterQualificationIdsEligibleForLevel2OwnerAssignment($qualificationIds))
            ->flip();

        return $this->processBulk(
            $qualificationIds,
            $eligibleIds,
            function (Qualification $qualification) use ($actor, $assignee, $comment) {
                $this->assignments->assignLevel2ReviewOwnerWithContext(
                    $qualification,
                    $actor,
                    $assignee,
                    [
                        'source' => 'manual',
                        'reason' => $comment,
                    ],
                );
            },
        );
    }

    /**
     * @param  list<int>  $qualificationIds
     * @param  Collection<int, int>  $eligibleIds
     * @return array{assigned: int, skipped: int, errors: list<array{id: int, message: string}>}
     */
    private function processBulk(array $qualificationIds, Collection $eligibleIds, callable $assign): array
    {
        $assigned = 0;
        $skipped = 0;
        $errors = [];

        foreach ($qualificationIds as $rawId) {
            $id = (int) $rawId;
            if ($id < 1) {
                $skipped++;

                continue;
            }

            if (! $eligibleIds->has($id)) {
                $skipped++;

                continue;
            }

            /** @var Qualification|null $qualification */
            $qualification = Qualification::query()->find($id);
            if (! $qualification) {
                $skipped++;

                continue;
            }

            try {
                $assign($qualification);
                $assigned++;
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first();
                $errors[] = [
                    'id' => $id,
                    'message' => is_string($message) ? $message : 'Assignment failed.',
                ];
            } catch (\Throwable $exception) {
                $errors[] = [
                    'id' => $id,
                    'message' => $exception->getMessage() !== '' ? $exception->getMessage() : 'Assignment failed.',
                ];
            }
        }

        return [
            'assigned' => $assigned,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
