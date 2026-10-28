<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_is_stored_normalized(): void
    {
        $customer = Customer::factory()->create(['phone' => '612 34 56 78']);

        $this->assertSame('+34612345678', $customer->fresh()->phone);
    }

    public function test_finds_customer_by_phone_in_any_format(): void
    {
        $customer = Customer::factory()->create(['phone' => '612345678']);

        foreach (['+34 612 345 678', '0034-612-345-678', '612.34.56.78', '34612345678'] as $typed) {
            $this->assertTrue($customer->is(Customer::findByPhone($typed)), "No encuentra {$typed}");
        }
        $this->assertNull(Customer::findByPhone('699 999 999'));
    }

    public function test_same_phone_in_another_format_is_a_duplicate(): void
    {
        Customer::factory()->create(['phone' => '612345678']);

        $this->expectException(UniqueConstraintViolationException::class);
        Customer::factory()->create(['phone' => '+34 612 34 56 78']);
    }
}
