<?php

namespace Tests\Unit;

use App\Support\Format;
use PHPUnit\Framework\TestCase;

class FormatUnitNetTest extends TestCase
{
    public function test_two_decimals_when_exact_and_up_to_four_when_needed(): void
    {
        $this->assertSame('9,00 €', Format::unitNet(90000));
        $this->assertSame('9,09 €', Format::unitNet(90900));
        $this->assertSame('9,095 €', Format::unitNet(90950));
        $this->assertSame('9,0909 €', Format::unitNet(90909));
        $this->assertSame('1.234,5678 €', Format::unitNet(12345678));
        $this->assertSame('-2,2727 €', Format::unitNet(-22727));
        $this->assertSame('0,05 €', Format::unitNet(500));
    }
}
