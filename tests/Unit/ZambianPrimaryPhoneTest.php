<?php

namespace Tests\Unit;

use App\Support\Phone\ZambianPrimaryPhone;
use PHPUnit\Framework\TestCase;

class ZambianPrimaryPhoneTest extends TestCase
{
    public function test_normalizes_nine_digit_local_number(): void
    {
        $this->assertSame('260973936164', ZambianPrimaryPhone::normalize('973936164'));
    }

    public function test_normalizes_local_trunk_prefix_number(): void
    {
        $this->assertSame('260973936164', ZambianPrimaryPhone::normalize('0973936164'));
    }

    public function test_normalizes_international_with_plus(): void
    {
        $this->assertSame('260973936164', ZambianPrimaryPhone::normalize('+260973936164'));
    }

    public function test_leaves_already_normalized_number_unchanged(): void
    {
        $this->assertSame('260973936164', ZambianPrimaryPhone::normalize('260973936164'));
    }

    public function test_rejects_invalid_lengths(): void
    {
        $this->assertNull(ZambianPrimaryPhone::tryNormalize('97393616'));
        $this->assertNull(ZambianPrimaryPhone::tryNormalize('097393616'));
        $this->assertNull(ZambianPrimaryPhone::tryNormalize('2609739361644'));
    }

    public function test_validates_normalized_format(): void
    {
        $this->assertTrue(ZambianPrimaryPhone::isValidNormalized('260973936164'));
        $this->assertFalse(ZambianPrimaryPhone::isValidNormalized('973936164'));
        $this->assertFalse(ZambianPrimaryPhone::isValidNormalized('+260973936164'));
    }

    public function test_builds_equivalent_storage_values(): void
    {
        $this->assertSame(
            ['260973936164', '+260973936164', '0973936164', '973936164'],
            ZambianPrimaryPhone::equivalentStorageValues('260973936164'),
        );
    }
}
