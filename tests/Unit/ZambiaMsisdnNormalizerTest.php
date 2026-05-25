<?php

namespace Tests\Unit;

use App\Support\Phone\ZambiaMsisdnNormalizer;
use PHPUnit\Framework\TestCase;

class ZambiaMsisdnNormalizerTest extends TestCase
{
    public function test_normalizes_local_format(): void
    {
        $this->assertSame('0970000000', ZambiaMsisdnNormalizer::normalizeForCGrate('0970000000', 'local'));
        $this->assertSame('0970000000', ZambiaMsisdnNormalizer::normalizeForCGrate('+260970000000', 'local'));
        $this->assertSame('0970000000', ZambiaMsisdnNormalizer::normalizeForCGrate('260970000000', 'local'));
        $this->assertSame('0970000000', ZambiaMsisdnNormalizer::normalizeForCGrate('970000000', 'local'));
    }

    public function test_normalizes_international_without_plus_format(): void
    {
        $this->assertSame('260970000000', ZambiaMsisdnNormalizer::normalizeForCGrate('0970000000', 'international_without_plus'));
        $this->assertSame('260970000000', ZambiaMsisdnNormalizer::normalizeForCGrate('+260970000000', 'international_without_plus'));
    }

    public function test_rejects_invalid_number(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ZambiaMsisdnNormalizer::normalizeForCGrate('081234', 'local');
    }
}

