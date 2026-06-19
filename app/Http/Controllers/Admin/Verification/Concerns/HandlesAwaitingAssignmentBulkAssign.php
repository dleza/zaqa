<?php

namespace App\Http\Controllers\Admin\Verification\Concerns;

use App\Domain\Verification\VerificationAssignmentBulkService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait HandlesAwaitingAssignmentBulkAssign
{
    /**
     * @param  callable(User, User, list<int>, ?string): array{assigned: int, skipped: int, errors: list<array{id: int, message: string}>}  $assign
     */
    protected function bulkAssignFromQueue(Request $request, callable $assign): RedirectResponse
    {
        abort_unless((bool) $request->user()?->can('verification.assign'), 403);

        /** @var User $assignee */
        $assignee = User::query()->findOrFail((int) $request->validated('officer_id'));

        $result = $assign(
            $request->user(),
            $assignee,
            array_map('intval', $request->validated('qualification_ids')),
            $request->validated('comment'),
        );

        $message = $this->formatBulkAssignFlashMessage($result);

        return back()
            ->with('success', $message)
            ->with('bulk_assign_summary', $result);
    }

    /**
     * @param  array{assigned: int, skipped: int, errors: list<array{id: int, message: string}>}  $result
     */
    private function formatBulkAssignFlashMessage(array $result): string
    {
        $parts = [];

        if ($result['assigned'] > 0) {
            $parts[] = $result['assigned'].' qualification'.($result['assigned'] === 1 ? '' : 's').' assigned successfully.';
        }

        if ($result['skipped'] > 0) {
            $parts[] = $result['skipped'].' skipped (no longer awaiting assignment or not eligible).';
        }

        if ($result['errors'] !== []) {
            $parts[] = count($result['errors']).' failed.';
        }

        if ($parts === []) {
            return 'No qualifications were assigned.';
        }

        return implode(' ', $parts);
    }
}
