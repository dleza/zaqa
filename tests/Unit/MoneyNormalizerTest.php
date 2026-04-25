<?php

namespace Tests\Unit;

use App\Support\Money\MoneyNormalizer;
use PHPUnit\Framework\TestCase;

class MoneyNormalizerTest extends TestCase
{
    public function test_to_minor_units_converts_whole_and_decimal_amounts(): void
    {
        $this->assertSame(5000, MoneyNormalizer::toMinorUnits('50'));
        $this->assertSame(5000, MoneyNormalizer::toMinorUnits('50.00'));
        $this->assertSame(5025, MoneyNormalizer::toMinorUnits('50.25'));
        $this->assertSame(120000, MoneyNormalizer::toMinorUnits('1200'));
        $this->assertSame(120050, MoneyNormalizer::toMinorUnits('1200.50'));
    }

    public function test_to_minor_units_allows_grouping_commas(): void
    {
        $this->assertSame(120050, MoneyNormalizer::toMinorUnits('1,200.50'));
    }

    public function test_to_minor_units_rejects_invalid_precision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MoneyNormalizer::toMinorUnits('50.999');
    }
}

