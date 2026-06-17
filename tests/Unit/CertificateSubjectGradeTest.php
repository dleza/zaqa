<?php

namespace Tests\Unit;

use App\Support\Qualifications\CertificateSubjectGrade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CertificateSubjectGradeTest extends TestCase
{
    public function test_allowed_includes_numeric_and_letter_grades(): void
    {
        $allowed = CertificateSubjectGrade::allowed();

        $this->assertContains('1', $allowed);
        $this->assertContains('9', $allowed);
        $this->assertContains('A', $allowed);
        $this->assertContains('Z', $allowed);
        $this->assertNotContains('10', $allowed);
        $this->assertNotContains('0', $allowed);
    }

    #[DataProvider('validGradeProvider')]
    public function test_normalize_accepts_valid_grades(mixed $input, string $expected): void
    {
        $this->assertSame($expected, CertificateSubjectGrade::normalize($input));
        $this->assertTrue(CertificateSubjectGrade::isAllowed($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function validGradeProvider(): array
    {
        return [
            'numeric 1' => ['1', '1'],
            'numeric 9' => ['9', '9'],
            'letter A' => ['A', 'A'],
            'letter Z' => ['Z', 'Z'],
            'lowercase a' => ['a', 'A'],
            'lowercase z' => ['z', 'Z'],
        ];
    }

    #[DataProvider('invalidGradeProvider')]
    public function test_normalize_rejects_invalid_grades(mixed $input): void
    {
        $this->assertNull(CertificateSubjectGrade::normalize($input));
        $this->assertFalse(CertificateSubjectGrade::isAllowed($input));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidGradeProvider(): array
    {
        return [
            'ten' => ['10'],
            'zero' => ['0'],
            'plus' => ['A+'],
            'minus' => ['B-'],
            'pass' => ['Pass'],
            'credit' => ['Credit'],
            'empty' => [''],
            'whitespace' => ['   '],
        ];
    }
}
