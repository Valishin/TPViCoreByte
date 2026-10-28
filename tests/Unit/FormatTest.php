<?php

namespace Tests\Unit;

use App\Support\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public static function money(): array
    {
        return [
            ['12,50', 1250], ['12.50', 1250], ['12,5', 1250], ['12', 1200], ['0,05', 5],
            ['1.234,56', 123456], ['1.250', 125000], ['12,50 €', 1250], ['-3,20', -320],
            ['12,505', null], ['abc', null], ['', null],
        ];
    }

    #[DataProvider('money')]
    public function test_parse_money(string $input, ?int $cents): void
    {
        $this->assertSame($cents, Format::parseMoney($input));
    }

    public function test_format_money_and_percent(): void
    {
        $this->assertSame('12,50 €', Format::money(1250));
        $this->assertSame('1.234,05 €', Format::money(123405));
        $this->assertSame('-0,50 €', Format::money(-50));
        $this->assertSame('12,50', Format::moneyInput(1250));
        $this->assertSame('10 %', Format::percent(1000));
        $this->assertSame('12,5 %', Format::percent(1250));
        $this->assertSame('0 %', Format::percent(0));
        $this->assertSame(1050, Format::parsePercent('10,5'));
    }
}
