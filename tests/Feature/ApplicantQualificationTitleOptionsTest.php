<?php

namespace Tests\Feature;

use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\QualificationTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicantQualificationTitleOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_title_options_are_distinct_and_filterable_by_institution(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $this->actingAs($user);

        $country = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $instA = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Institution A',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $instB = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Institution B',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $titleA = QualificationTitle::query()->create(['name' => 'Diploma in Testing', 'is_active' => true]);
        $titleB = QualificationTitle::query()->create(['name' => 'Bachelor of Science', 'is_active' => true]);
        $titleA->awardingInstitutions()->sync([$instA->id, $instB->id]);
        $titleB->awardingInstitutions()->sync([$instA->id]);

        $res = $this->getJson('/applicant/reference/qualification-titles?awarding_institution_id='.$instA->id);
        $res->assertOk();

        $titles = collect($res->json('data'))
            ->pluck('title')
            ->filter()
            ->values()
            ->all();

        $this->assertContains('Diploma in Testing', $titles);
        $this->assertContains('Bachelor of Science', $titles);
        $this->assertSame(count($titles), count(array_unique($titles)));

        $res2 = $this->getJson('/applicant/reference/qualification-titles?awarding_institution_id='.$instA->id.'&q=bachelor');
        $res2->assertOk();
        $titles2 = collect($res2->json('data'))->pluck('title')->filter()->values()->all();
        $this->assertSame(['Bachelor of Science'], $titles2);
    }
}
