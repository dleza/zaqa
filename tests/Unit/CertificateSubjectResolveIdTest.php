<?php

namespace Tests\Unit;

use App\Models\CertificateSubject;
use Tests\TestCase;

class CertificateSubjectResolveIdTest extends TestCase
{
    public function test_resolve_id_by_name_matches_active_subject_case_insensitively(): void
    {
        $subject = CertificateSubject::query()->create([
            'name' => 'Mathematics '.uniqid(),
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->assertSame($subject->id, CertificateSubject::resolveIdByName('  '.$subject->name.'  '));
        $this->assertSame($subject->id, CertificateSubject::resolveIdByName(strtolower($subject->name)));
        $this->assertNull(CertificateSubject::resolveIdByName('Unknown Subject'));
    }
}
