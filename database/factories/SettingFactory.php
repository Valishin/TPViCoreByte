<?php

namespace Database\Factories;

use App\Enums\HalfAndHalfModifierPricing;
use App\Enums\HalfAndHalfPricing;
use App\Enums\OrderType;
use App\Models\Setting;
use App\Models\TicketSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Setting> */
class SettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trade_name' => 'Bar Demo',
            'legal_name' => 'Hostelería Demo S.L.',
            'tax_id' => 'B12345678',
            'address' => 'Calle Mayor, 1',
            'postal_code' => '28001',
            'city' => 'Madrid',
            'province' => 'Madrid',
            'phone' => '910000000',
            'ticket_footer' => 'Gracias por su visita',
            'enabled_order_types' => OrderType::cases(),
            'default_ticket_series_id' => TicketSeries::factory(),
            'rectifying_ticket_series_id' => TicketSeries::factory()->state(['is_rectifying' => true]),
            'half_and_half_pricing' => HalfAndHalfPricing::MostExpensive,
            'half_and_half_modifier_pricing' => HalfAndHalfModifierPricing::Full,
            'delivery_fee_vat_rate' => 1000,
        ];
    }
}
