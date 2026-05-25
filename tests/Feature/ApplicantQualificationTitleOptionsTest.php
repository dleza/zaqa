<?php

namespace Tests\Feature;

use App\Enums\LearnerRecordSourceType;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\LearnerRecord;
use App\Models\User;
use App\Support\Normalization\LearnerRecordNormalizer;
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
            'consent_form_path' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $instB = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Institution B',
            'consent_form_path' => null,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        foreach ([
            [$instA->id, 'Diploma in Testing'],
            [$instA->id, 'Diploma in Testing'], // duplicate
            [$instA->id, 'Bachelor of Science'],
            [$instB->id, 'Diploma in Testing'], // other institution
        ] as [$instId, $title]) {
            LearnerRecord::query()->create([
                'awarding_institution_id' => $instId,
                'program_of_study' => $title,
                'qualification_title_normalized' => LearnerRecordNormalizer::normalizeProgramTitle($title),
                'source_type' => LearnerRecordSourceType::Manual,
                'is_active' => true,
            ]);
        }

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

        // Search term narrows
        $res2 = $this->getJson('/applicant/reference/qualification-titles?awarding_institution_id='.$instA->id.'&q=bachelor');
        $res2->assertOk();
        $titles2 = collect($res2->json('data'))->pluck('title')->filter()->values()->all();
        $this->assertSame(['Bachelor of Science'], $titles2);
    }
}

