<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Domain\Verification\QualificationsPoolService;
use App\Domain\Verification\VerificationAssignmentBulkService;
use App\Http\Controllers\Admin\Verification\Concerns\HandlesAwaitingAssignmentBulkAssign;
use App\Http\Controllers\Admin\Verification\Concerns\MapsVerificationAssignmentQueueRows;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Verification\BulkAssignAwaitingLevel1Request;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminVerificationAwaitingLevel1AssignmentController extends Controller
{
    use HandlesAwaitingAssignmentBulkAssign;
    use MapsVerificationAssignmentQueueRows;

    public function index(Request $request, QualificationsPoolService $pool): Response
    {
        abort_unless((bool) $request->user()?->can('verification.assign'), 403);

        $rows = $pool->awaitingLevel1Assignment($request);

        return Inertia::render('Admin/Verification/AwaitingAssignment/Index', [
            'pageVariant' => 'level1',
            'qualifications' => $rows->through(fn (Qualification $q) => $this->mapVerificationAssignmentQueueRow($q)),
            'filters' => $this->filtersPayload($request),
            'level1Users' => $this->level1Users(),
            'level2Users' => [],
            'can' => [
                'assign_level1' => true,
                'assign_level2' => false,
            ],
        ]);
    }

    public function bulkAssign(
        BulkAssignAwaitingLevel1Request $request,
        VerificationAssignmentBulkService $bulk,
    ): RedirectResponse {
        return $this->bulkAssignFromQueue(
            $request,
            fn (User $actor, User $assignee, array $ids, ?string $comment) => $bulk->bulkAssignLevel1($actor, $assignee, $ids, $comment),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function filtersPayload(Request $request): array
    {
        return [
            'q' => (string) $request->query('q', ''),
            'overdue' => $request->query('overdue'),
            'overdue_days' => $request->query('overdue_days'),
            'submitted_from' => $request->query('submitted_from'),
            'submitted_to' => $request->query('submitted_to'),
            'qualification_q' => $request->query('qualification_q'),
            'foreign' => $request->query('foreign'),
            'qualification_type_id' => $request->query('qualification_type_id'),
            'awarding_institution_id' => $request->query('awarding_institution_id'),
            'country_id' => $request->query('country_id'),
            'sort' => (string) $request->query('sort', 'deadline'),
            'direction' => (string) $request->query('direction', 'asc'),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, email: string}>
     */
    private function level1Users()
    {
        return User::query()
            ->whereNull('applicant_type')
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Verification Officer Level 1'))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->values();
    }
}
