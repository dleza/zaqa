<?php

namespace Tests\Unit;

use App\Models\Qualification;
use App\Support\Qualifications\QualificationAwardingInstitutionFormState;
use Tests\TestCase;

class QualificationAwardingInstitutionFormStateTest extends TestCase
{
    public function test_legacy_name_only_maps_to_other_for_forms(): void
    {
        $qualification = new Qualification([
            'awarding_institution_id' => null,
            'awarding_institution_name_other' => null,
            'awarding_institution_name' => 'Test School',
        ]);

        $form = QualificationAwardingInstitutionFormState::forForm($qualification);

        $this->assertSame('other', $form['awarding_institution_id']);
        $this->assertSame('Test School', $form['awarding_institution_name_other']);
        $this->assertSame('Test School', $form['awarding_institution_name']);
    }

    public function test_catalog_institution_keeps_numeric_id(): void
    {
        $qualification = new Qualification([
            'awarding_institution_id' => 5,
            'awarding_institution_name_other' => null,
            'awarding_institution_name' => 'Examinations Council of Zambia (ECZ)',
        ]);

        $form = QualificationAwardingInstitutionFormState::forForm($qualification);

        $this->assertSame(5, $form['awarding_institution_id']);
        $this->assertNull($form['awarding_institution_name_other']);
    }
}
