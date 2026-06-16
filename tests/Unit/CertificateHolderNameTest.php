<?php

namespace Tests\Unit;

use App\Support\Certificates\CertificateHolderName;
use PHPUnit\Framework\TestCase;

class CertificateHolderNameTest extends TestCase
{
    public function test_formats_uppercase_name_to_title_case(): void
    {
        $this->assertSame('Martin Mwale', CertificateHolderName::format('MARTIN MWALE'));
    }

    public function test_formats_lowercase_name_to_title_case(): void
    {
        $this->assertSame('Martin Mwale', CertificateHolderName::format('martin mwale'));
    }

    public function test_formats_mixed_case_name_to_title_case(): void
    {
        $this->assertSame('Martin Mwale', CertificateHolderName::format('MaRTin mWaLe'));
    }

    public function test_formats_hyphenated_name(): void
    {
        $this->assertSame('Mary-Jane Banda', CertificateHolderName::format('MARY-JANE BANDA'));
    }

    public function test_formats_apostrophe_name(): void
    {
        $this->assertSame("O'Connor", CertificateHolderName::format("O'CONNOR"));
    }

    public function test_collapses_whitespace(): void
    {
        $this->assertSame('Martin Mwale', CertificateHolderName::format('  MARTIN   MWALE  '));
    }

    public function test_empty_name_returns_null(): void
    {
        $this->assertNull(CertificateHolderName::format('   '));
    }
}
