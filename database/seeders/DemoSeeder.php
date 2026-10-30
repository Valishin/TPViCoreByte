<?php

namespace Database\Seeders;

use App\Enums\Allergen;
use App\Enums\HalfAndHalfModifierPricing;
use App\Enums\HalfAndHalfPricing;
use App\Enums\OrderType;
use App\Enums\PrinterType;
use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Printer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TicketSeries;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Base común de los datasets de demo: ajustes, series, personal e impresoras.
 * Las impresoras tienen un host ficticio; con PRINTER_MODE=simulated no se
 * envía nada a la red.
 */
abstract class DemoSeeder extends Seeder
{
    /** @param  list<OrderType>  $orderTypes */
    protected function setUpLocal(string $tradeName, array $orderTypes): Setting
    {
        $this->call(AdminUserSeeder::class);

        $default = TicketSeries::create(['code' => 'T', 'name' => 'Tickets']);
        $rectifying = TicketSeries::create(['code' => 'R', 'name' => 'Rectificativos', 'is_rectifying' => true]);
        $invoices = TicketSeries::create(['code' => 'F', 'name' => 'Facturas']);
        $invoiceRectifying = TicketSeries::create(['code' => 'FR', 'name' => 'Rectificativas de facturas', 'is_rectifying' => true]);

        return Setting::create([
            'trade_name' => $tradeName,
            'legal_name' => 'Hostelería Demo S.L.',
            'tax_id' => 'B00000000',
            'address' => 'Calle Mayor, 1',
            'postal_code' => '28001',
            'city' => 'Madrid',
            'province' => 'Madrid',
            'phone' => '910000000',
            'email' => 'hola@example.com',
            'ticket_footer' => "Gracias por su visita\nIVA incluido",
            'enabled_order_types' => $orderTypes,
            'default_ticket_series_id' => $default->id,
            'rectifying_ticket_series_id' => $rectifying->id,
            'invoice_series_id' => $invoices->id,
            'invoice_rectifying_series_id' => $invoiceRectifying->id,
            'half_and_half_pricing' => HalfAndHalfPricing::MostExpensive,
            'half_and_half_modifier_pricing' => HalfAndHalfModifierPricing::Full,
            'delivery_fee_vat_rate' => 1000,
            'quick_notes' => ['Para compartir', 'Sin gluten', 'Sin lactosa', 'Rápido'],
        ]);
    }

    /** @param  array<string, string>  $staff  nombre => PIN */
    protected function createStaff(UserRole $role, array $staff): void
    {
        foreach ($staff as $name => $pin) {
            User::create(['name' => $name, 'role' => $role, 'pin' => $pin, 'is_active' => true, 'password' => str()->random(32)]);
        }
    }

    protected function printer(string $name, PrinterType $type, string $host, int $columns = 48): Printer
    {
        return Printer::create(['name' => $name, 'type' => $type, 'host' => $host, 'port' => 9100, 'columns' => $columns, 'codepage' => 16]);
    }

    /** @param  list<string>  $quickNotes  notas rápidas de cocina de la categoría */
    protected function category(string $name, string $color, int $order, ?Printer $printer, array $quickNotes = []): Category
    {
        return Category::create(['name' => $name, 'color' => $color, 'sort_order' => $order, 'printer_id' => $printer?->id, 'quick_notes' => $quickNotes]);
    }

    /**
     * Crea productos simples de una categoría.
     *
     * @param  list<array{0: string, 1: int, 2?: list<Allergen>, 3?: array<string, mixed>}>  $items  [nombre, precio, alérgenos, atributos extra]
     * @return array<string, Product>
     */
    protected function products(Category $category, array $items): array
    {
        $created = [];
        foreach ($items as $i => $item) {
            [$name, $price] = $item;
            $created[$name] = Product::create(($item[3] ?? []) + [
                'category_id' => $category->id,
                'name' => $name,
                'price' => $price,
                'vat_rate' => 1000,
                'type' => ProductType::Simple,
                'allergens' => $item[2] ?? [],
                'sort_order' => $i,
            ]);
        }

        return $created;
    }

    /** @param  array<string, int>  $modifiers  nombre => incremento */
    protected function modifierGroup(string $name, int $min, ?int $max, array $modifiers): ModifierGroup
    {
        $group = ModifierGroup::create(['name' => $name, 'min_select' => $min, 'max_select' => $max]);
        $i = 0;
        foreach ($modifiers as $modifier => $delta) {
            $group->modifiers()->create(['name' => $modifier, 'price_delta' => $delta, 'sort_order' => $i++]);
        }

        return $group;
    }
}
