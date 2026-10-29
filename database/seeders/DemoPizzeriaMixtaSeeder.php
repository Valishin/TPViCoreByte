<?php

namespace Database\Seeders;

use App\Enums\OrderType;
use App\Enums\TableShape;
use App\Enums\UserRole;
use App\Models\Room;

/**
 * Pizzería mixta: la carta, los clientes y las zonas de reparto de Sant Feliu
 * de la pizzería, más sala (comedor con 8 mesas y terraza con 6) y todos los
 * tipos de pedido activos: mesa, barra, para llevar y domicilio.
 *
 *   DEMO_DATASET=pizzeria_mixta sail artisan migrate:fresh --seed
 *   sail artisan db:seed --class=DemoPizzeriaMixtaSeeder   (sobre una base vacía)
 */
class DemoPizzeriaMixtaSeeder extends DemoPizzeriaSeeder
{
    protected function orderTypes(): array
    {
        return [OrderType::Table, OrderType::Bar, OrderType::Takeaway, OrderType::Delivery];
    }

    protected function extras(): void
    {
        $this->createStaff(UserRole::Waiter, ['Laia (sala)' => '2222', 'Pau (sala)' => '3333']);

        $comedor = Room::create(['name' => 'Comedor', 'sort_order' => 1]);
        foreach (range(1, 8) as $n) {
            $comedor->tables()->create([
                'name' => (string) $n,
                'seats' => $n <= 4 ? 4 : 2,
                'pos_x' => (($n - 1) % 4) * 3, 'pos_y' => intdiv($n - 1, 4) * 3,
                'shape' => $n % 2 ? TableShape::Square : TableShape::Round,
            ]);
        }

        $terraza = Room::create(['name' => 'Terraza', 'sort_order' => 2]);
        foreach (range(1, 6) as $n) {
            $terraza->tables()->create([
                'name' => "T{$n}", 'seats' => 4,
                'pos_x' => (($n - 1) % 3) * 3, 'pos_y' => intdiv($n - 1, 3) * 3,
                'shape' => TableShape::Round,
            ]);
        }
    }
}
