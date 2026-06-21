<?php

namespace Tests\Feature;

use App\Domain\Verification\AssignmentService;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Qualification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class VerificationAssignmentQueuesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-06-16 10:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inertiaProps($response): array
    {
        $page = $response->viewData('page');

        return json_decode(json_encode($page), true)['props'];
    }

    private function makeLevel1Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 1');

        return $user;
    }

    private function makeLevel2Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 2');

        return $user;
    }

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Super Admin');

        return $user;
    }

    private function makeFinanceOfficer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Finance Officer');

        return $user;
    }

    private function makeSubmittedApplication(array $overrides = []): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-Q-'.Str::upper(Str::random(6)),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now()->subDays(3),
            'service_deadline_at' => now()->addDays(7),
        ], $overrides));
    }

    private function makeQualification(Application $app, array $overrides = []): Qualification
    {
        return Qualification::query()->create(array_merge([
            'application_id' => $app->id,
            'verification_reference_number' => 'ZAQA-VR-'.Str::upper(Str::random(8)),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Holder Name',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => Str::random(6).'/11/1',
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'service_deadline_at' => now()->addDays(5),
        ], $overrides));
    }

    /** @return list<int> */
    private function queueIds(string $routeName, User $actor): array
    {
        $response = $this->actingAs($actor)->get(route($routeName));
        $response->assertOk();

        return collect($this->inertiaProps($response)['qualifications']['data'] ?? [])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function test_level2_and_super_admin_can_access_assignment_queues(): void
    {
        $l2 = $this->makeLevel2Officer();
        $super = $this->makeSuperAdmin();

        $this->actingAs($l2)->get(route('admin.verification.awaiting_level1_assignment'))->assertOk();
        $this->actingAs($l2)->get(route('admin.verification.awaiting_level2_assignment'))->assertOk();
        $this->actingAs($super)->get(route('admin.verification.awaiting_level1_assignment'))->assertOk();
        $this->actingAs($super)->get(route('admin.verification.awaiting_level2_assignment'))->assertOk();
    }

    public function test_level1_and_finance_cannot_access_assignment_queues(): void
    {
        $l1 = $this->makeLevel1Officer();
        $finance = $this->makeFinanceOfficer();

        $this->actingAs($l1)->get(route('admin.verification.awaiting_level1_assignment'))->assertForbidden();
        $this->actingAs($l1)->get(route('admin.verification.awaiting_level2_assignment'))->assertForbidden();
        $this->actingAs($finance)->get(route('admin.verification.awaiting_level1_assignment'))->assertForbidden();
        $this->actingAs($finance)->get(route('admin.verification.awaiting_level2_assignment'))->assertForbidden();
    }

    public function test_awaiting_level1_queue_includes_unassigned_records_and_excludes_assigned_and_level2(): void
    {
        $l2 = $this->makeLevel2Officer();
        $l1 = $this->makeLevel1Officer();

        $awaiting = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);
        $unassignedState = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel1Review,
            'assigned_verifier_id' => null,
        ]);
        $assigned = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel1Review,
            'assigned_verifier_id' => $l1->id,
        ]);
        $level2 = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel2Review,
        ]);
        $terminal = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::ApprovedForCertificate,
        ]);

        $ids = $this->queueIds('admin.verification.awaiting_level1_assignment', $l2);

        $this->assertContains($awaiting->id, $ids);
        $this->assertContains($unassignedState->id, $ids);
        $this->assertNotContains($assigned->id, $ids);
        $this->assertNotContains($level2->id, $ids);
        $this->assertNotContains($terminal->id, $ids);
    }

    public function test_awaiting_level2_queue_includes_unowned_manual_and_unlocked_auto_verified(): void
    {
        $l2 = $this->makeLevel2Officer();
        $otherL2 = $this->makeLevel2Officer();

        $manual = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => null,
        ]);
        $owned = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => $otherL2->id,
        ]);
        $autoUnlocked = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
            'level2_review_locked_by' => null,
        ]);
        $autoLocked = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
            'level2_review_locked_by' => $otherL2->id,
            'level2_review_locked_at' => now(),
        ]);
        $level1 = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel1Review,
            'assigned_verifier_id' => $this->makeLevel1Officer()->id,
        ]);

        $ids = $this->queueIds('admin.verification.awaiting_level2_assignment', $l2);

        $this->assertContains($manual->id, $ids);
        $this->assertContains($autoUnlocked->id, $ids);
        $this->assertNotContains($owned->id, $ids);
        $this->assertNotContains($autoLocked->id, $ids);
        $this->assertNotContains($level1->id, $ids);
    }

    public function test_awaiting_level1_queue_supports_submitted_date_sort(): void
    {
        $l2 = $this->makeLevel2Officer();

        $older = $this->makeQualification($this->makeSubmittedApplication(['submitted_at' => now()->subDays(10)]), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);
        $newer = $this->makeQualification($this->makeSubmittedApplication(['submitted_at' => now()->subDay()]), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        $response = $this->actingAs($l2)->get(route('admin.verification.awaiting_level1_assignment', [
            'sort' => 'submitted',
            'direction' => 'asc',
        ]));
        $response->assertOk();
        $ids = collect($this->inertiaProps($response)['qualifications']['data'])->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $this->assertSame([$older->id, $newer->id], array_values(array_intersect($ids, [$older->id, $newer->id])));
    }

    public function test_level2_officer_can_assign_level1_from_queue(): void
    {
        $l2 = $this->makeLevel2Officer();
        $l1 = $this->makeLevel1Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.assign', ['qualification' => $qualification->id]), [
                'assigned_to_user_id' => $l1->id,
                'comment' => 'Queue assignment',
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame($l1->id, (int) $qualification->assigned_verifier_id);
        $this->assertNotContains($qualification->id, $this->queueIds('admin.verification.awaiting_level1_assignment', $l2));
    }

    public function test_level2_officer_can_assign_level2_owner_from_queue(): void
    {
        $l2 = $this->makeLevel2Officer();
        $otherL2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => null,
        ]);

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.assign_level2', ['qualification' => $qualification->id]), [
                'assigned_to_user_id' => $otherL2->id,
                'comment' => 'Manual L2 assignment',
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame($otherL2->id, (int) $qualification->level2_review_owner_id);
        $this->assertNotContains($qualification->id, $this->queueIds('admin.verification.awaiting_level2_assignment', $l2));

        $this->assertDatabaseHas('audit_logs', [
            'action_name' => 'level2_assigned',
            'entity_type' => Qualification::class,
            'entity_id' => $qualification->id,
        ]);
    }

    public function test_existing_verification_pool_still_works(): void
    {
        $l2 = $this->makeLevel2Officer();
        $this->makeQualification($this->makeSubmittedApplication());

        $this->actingAs($l2)->get(route('admin.verification.pool.index'))->assertOk();
        $this->actingAs($l2)->get(route('admin.verification.assigned_to_me'))->assertOk();
    }

    public function test_awaiting_level1_page_includes_all_filter_keys(): void
    {
        $l2 = $this->makeLevel2Officer();

        $response = $this->actingAs($l2)->get(route('admin.verification.awaiting_level1_assignment', [
            'application_reference' => 'ZAQA',
            'qualification_reference' => '2026-000245-01',
            'submitted_from' => '2026-01-01',
            'submitted_to' => '2026-06-01',
            'foreign' => '0',
            'overdue' => '1',
            'sort' => 'submitted',
            'direction' => 'desc',
        ]));

        $response->assertOk();
        $filters = $this->inertiaProps($response)['filters'];

        $this->assertSame('ZAQA', $filters['application_reference']);
        $this->assertSame('2026-000245-01', $filters['qualification_reference']);
        $this->assertSame('2026-01-01', $filters['submitted_from']);
        $this->assertSame('2026-06-01', $filters['submitted_to']);
        $this->assertSame('0', $filters['foreign']);
        $this->assertSame('1', $filters['overdue']);
        $this->assertSame('submitted', $filters['sort']);
        $this->assertSame('desc', $filters['direction']);
        $this->assertArrayHasKey('overdue_days', $filters);
        $this->assertArrayHasKey('qualification_type_id', $filters);
        $this->assertArrayHasKey('awarding_institution_id', $filters);
        $this->assertArrayHasKey('country_id', $filters);
    }

    public function test_super_admin_can_bulk_assign_awaiting_level1_qualifications(): void
    {
        $super = $this->makeSuperAdmin();
        $l1 = $this->makeLevel1Officer();

        $first = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);
        $second = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel1Review,
            'assigned_verifier_id' => null,
        ]);

        $this->actingAs($super)
            ->post(route('admin.verification.awaiting_level1_assignment.bulk_assign'), [
                'officer_id' => $l1->id,
                'qualification_ids' => [$first->id, $second->id],
                'comment' => 'Bulk L1 assignment',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $first->refresh();
        $second->refresh();

        $this->assertSame($l1->id, (int) $first->assigned_verifier_id);
        $this->assertSame($l1->id, (int) $second->assigned_verifier_id);
        $this->assertNotContains($first->id, $this->queueIds('admin.verification.awaiting_level1_assignment', $super));
        $this->assertNotContains($second->id, $this->queueIds('admin.verification.awaiting_level1_assignment', $super));

        $this->assertDatabaseHas('audit_logs', [
            'action_name' => 'assigned',
            'entity_type' => Qualification::class,
            'entity_id' => $first->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action_name' => 'assigned',
            'entity_type' => Qualification::class,
            'entity_id' => $second->id,
        ]);
    }

    public function test_level2_officer_can_bulk_assign_awaiting_level1_if_permitted(): void
    {
        $l2 = $this->makeLevel2Officer();
        $l1 = $this->makeLevel1Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        $this->actingAs($l2)
            ->post(route('admin.verification.awaiting_level1_assignment.bulk_assign'), [
                'officer_id' => $l1->id,
                'qualification_ids' => [$qualification->id],
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame($l1->id, (int) $qualification->assigned_verifier_id);
    }

    public function test_unauthorized_user_cannot_bulk_assign_awaiting_level1(): void
    {
        $l1 = $this->makeLevel1Officer();
        $l1Target = $this->makeLevel1Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        $this->actingAs($l1)
            ->post(route('admin.verification.awaiting_level1_assignment.bulk_assign'), [
                'officer_id' => $l1Target->id,
                'qualification_ids' => [$qualification->id],
            ])
            ->assertForbidden();
    }

    public function test_bulk_level1_skips_records_no_longer_awaiting_assignment(): void
    {
        $super = $this->makeSuperAdmin();
        $l1 = $this->makeLevel1Officer();
        $otherL1 = $this->makeLevel1Officer();

        $awaiting = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);
        $alreadyAssigned = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel1Review,
            'assigned_verifier_id' => $otherL1->id,
        ]);

        $response = $this->actingAs($super)
            ->post(route('admin.verification.awaiting_level1_assignment.bulk_assign'), [
                'officer_id' => $l1->id,
                'qualification_ids' => [$awaiting->id, $alreadyAssigned->id],
            ]);

        $response->assertRedirect();
        $this->assertStringContainsString('assigned successfully', (string) session('success'));
        $this->assertStringContainsString('skipped', (string) session('success'));

        $awaiting->refresh();
        $alreadyAssigned->refresh();

        $this->assertSame($l1->id, (int) $awaiting->assigned_verifier_id);
        $this->assertSame($otherL1->id, (int) $alreadyAssigned->assigned_verifier_id);
    }

    public function test_super_admin_can_bulk_assign_awaiting_level2_qualifications(): void
    {
        $super = $this->makeSuperAdmin();
        $l2 = $this->makeLevel2Officer();
        $otherL2 = $this->makeLevel2Officer();

        $first = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => null,
        ]);
        $second = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => null,
        ]);

        $this->actingAs($super)
            ->post(route('admin.verification.awaiting_level2_assignment.bulk_assign'), [
                'officer_id' => $otherL2->id,
                'qualification_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $first->refresh();
        $second->refresh();

        $this->assertSame($otherL2->id, (int) $first->level2_review_owner_id);
        $this->assertSame($otherL2->id, (int) $second->level2_review_owner_id);
        $this->assertNotContains($first->id, $this->queueIds('admin.verification.awaiting_level2_assignment', $super));
        $this->assertNotContains($second->id, $this->queueIds('admin.verification.awaiting_level2_assignment', $super));
    }

    public function test_level2_officer_can_bulk_assign_awaiting_level2_to_another_level2_officer(): void
    {
        $l2 = $this->makeLevel2Officer();
        $otherL2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => null,
        ]);

        $this->actingAs($l2)
            ->post(route('admin.verification.awaiting_level2_assignment.bulk_assign'), [
                'officer_id' => $otherL2->id,
                'qualification_ids' => [$qualification->id],
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame($otherL2->id, (int) $qualification->level2_review_owner_id);
    }

    public function test_unauthorized_user_cannot_bulk_assign_awaiting_level2(): void
    {
        $l1 = $this->makeLevel1Officer();
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => null,
        ]);

        $this->actingAs($l1)
            ->post(route('admin.verification.awaiting_level2_assignment.bulk_assign'), [
                'officer_id' => $l2->id,
                'qualification_ids' => [$qualification->id],
            ])
            ->assertForbidden();
    }

    public function test_bulk_level2_skips_auto_verified_and_already_owned_records(): void
    {
        $super = $this->makeSuperAdmin();
        $l2 = $this->makeLevel2Officer();
        $otherL2 = $this->makeLevel2Officer();

        $manual = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => null,
        ]);
        $autoVerified = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
            'level2_review_locked_by' => null,
        ]);
        $owned = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => $otherL2->id,
        ]);

        $this->actingAs($super)
            ->post(route('admin.verification.awaiting_level2_assignment.bulk_assign'), [
                'officer_id' => $l2->id,
                'qualification_ids' => [$manual->id, $autoVerified->id, $owned->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $manual->refresh();
        $autoVerified->refresh();
        $owned->refresh();

        $this->assertSame($l2->id, (int) $manual->level2_review_owner_id);
        $this->assertNull($autoVerified->level2_review_owner_id);
        $this->assertSame($otherL2->id, (int) $owned->level2_review_owner_id);
    }

    public function test_bulk_assign_rejects_ineligible_officer(): void
    {
        $super = $this->makeSuperAdmin();
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        $this->actingAs($super)
            ->post(route('admin.verification.awaiting_level1_assignment.bulk_assign'), [
                'officer_id' => $l2->id,
                'qualification_ids' => [$qualification->id],
            ])
            ->assertSessionHasErrors('officer_id');
    }

    public function test_awaiting_assignment_pages_expose_assign_permissions_for_ui(): void
    {
        $l2 = $this->makeLevel2Officer();

        $level1Props = $this->inertiaProps(
            $this->actingAs($l2)->get(route('admin.verification.awaiting_level1_assignment'))->assertOk()
        );
        $level2Props = $this->inertiaProps(
            $this->actingAs($l2)->get(route('admin.verification.awaiting_level2_assignment'))->assertOk()
        );

        $this->assertTrue($level1Props['can']['assign_level1']);
        $this->assertFalse($level1Props['can']['assign_level2']);
        $this->assertFalse($level2Props['can']['assign_level1']);
        $this->assertTrue($level2Props['can']['assign_level2']);
    }
}
