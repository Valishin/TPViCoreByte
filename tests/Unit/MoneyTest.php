<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_percentage_rounds_half_cent_up(): void
    {
        $this->assertSame(199, Money::percentage(1985, 1000)); // 198,5 → 199
        $this->assertSame(198, Money::percentage(1984, 1000));
    }

    public function test_vat_base_from_gross_amount(): void
    {
        $this->assertSame(682, Money::vatBase(750, 1000)); // 681,8
        $this->assertSame(441, Money::vatBase(534, 2100)); // 441,3
    }

    public function test_allocate_squares_to_the_cent_and_gives_remainder_to_largest(): void
    {
        $shares = Money::allocate(1000, ['pizza' => 900, 'cerveza' => 250]);

        $this->assertSame(['pizza' => 783, 'cerveza' => 217], $shares);
        $this->assertSame(1000, array_sum($shares));
    }

    public function test_allocate_with_zero_weights_splits_equally(): void
    {
        $this->assertSame([0 => 51, 1 => 50], Money::allocate(101, [0, 0]));
    }
}
