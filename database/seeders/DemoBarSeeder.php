<?php

namespace Database\Seeders;

use App\Enums\Allergen as A;
use App\Enums\OrderType;
use App\Enums\PrinterType;
use App\Enums\TableShape;
use App\Enums\UserRole;
use App\Models\Room;

/** Dataset "bar": 2 salas, 12 mesas, ~40 productos de tapas y bebidas, 3 camareros. */
class DemoBarSeeder extends DemoSeeder
{
    public function run(): void
    {
        $this->setUpLocal('Bar La Esquina', [OrderType::Table, OrderType::Bar]);

        $this->createStaff(UserRole::Waiter, ['Ana' => '1111', 'Luis' => '2222', 'Marta' => '3333']);

        // Salas y mesas (posición en una cuadrícula para el plano).
        $salon = Room::create(['name' => 'Salón', 'sort_order' => 1]);
        foreach (range(1, 8) as $n) {
            $salon->tables()->create([
                'name' => (string) $n, 'seats' => $n <= 4 ? 4 : 2,
                'pos_x' => (($n - 1) % 4) * 3, 'pos_y' => intdiv($n - 1, 4) * 3,
                'shape' => $n % 2 ? TableShape::Square : TableShape::Round,
            ]);
        }
        $terraza = Room::create(['name' => 'Terraza', 'sort_order' => 2]);
        foreach (range(1, 4) as $n) {
            $terraza->tables()->create(['name' => "T{$n}", 'seats' => 4, 'pos_x' => ($n - 1) * 3, 'pos_y' => 0, 'shape' => TableShape::Round]);
        }

        $bar = $this->printer('Barra', PrinterType::Bar, '192.168.1.201');
        $kitchen = $this->printer('Cocina', PrinterType::Kitchen, '192.168.1.202');
        $this->printer('Caja', PrinterType::Ticket, '192.168.1.203');

        // Para llevar, el alcohol tributa al 21 % (en el local, servicio de restauración al 10 %).
        $alcohol = ['vat_rate_takeaway' => 2100];

        $tapas = $this->products($this->category('Tapas', '#f59e0b', 1, $kitchen, ['Sin cebolla', 'Sin picante', 'Bien caliente', 'Salsa aparte']), [
            ['Patatas bravas', 550, [A::Eggs]],
            ['Croquetas de jamón (6)', 750, [A::Gluten, A::Milk, A::Eggs]],
            ['Tortilla de patata (pincho)', 350, [A::Eggs]],
            ['Pimientos de Padrón', 650, []],
            ['Boquerones en vinagre', 700, [A::Fish, A::Sulphites]],
            ['Ensaladilla rusa', 600, [A::Eggs, A::Fish]],
            ['Gambas al ajillo', 1150, [A::Crustaceans]],
            ['Calamares a la romana', 1050, [A::Gluten, A::Molluscs, A::Eggs]],
            ['Pulpo a la gallega', 1650, [A::Molluscs]],
            ['Montadito de lomo', 400, [A::Gluten]],
        ]);
        $raciones = $this->products($this->category('Raciones y carnes', '#ef4444', 2, $kitchen, ['Poco hecha', 'Al punto', 'Muy hecha', 'Sin sal', 'Sin guarnición']), [
            ['Entrecot a la brasa', 1950, []],
            ['Secreto ibérico', 1650, []],
            ['Tabla de quesos', 1400, [A::Milk, A::Nuts]],
            ['Jamón ibérico (ración)', 1900, []],
            ['Ensalada mixta', 850, [A::Eggs, A::Fish]],
        ]);
        $this->products($this->category('Postres', '#ec4899', 3, $kitchen, ['Con nata', 'Sin nata', 'Dos cucharas']), [
            ['Tarta de queso', 550, [A::Milk, A::Eggs, A::Gluten]],
            ['Flan casero', 450, [A::Milk, A::Eggs]],
            ['Helado (2 bolas)', 400, [A::Milk]],
        ]);
        $this->products($this->category('Cervezas', '#eab308', 4, $bar, ['Bien fría', 'Con limón', 'En jarra', 'Sin espuma']), [
            ['Caña', 220, [A::Gluten], $alcohol],
            ['Tercio', 300, [A::Gluten], $alcohol],
            ['Doble', 350, [A::Gluten], $alcohol],
            ['Cerveza sin alcohol', 300, [A::Gluten]],
            ['Clara de limón', 250, [A::Gluten], $alcohol],
        ]);
        $this->products($this->category('Vinos', '#7c3aed', 5, $bar, ['Fresquito', 'Copa grande']), [
            ['Copa de Rioja', 350, [A::Sulphites], $alcohol],
            ['Copa de Ribera', 380, [A::Sulphites], $alcohol],
            ['Copa de Albariño', 400, [A::Sulphites], $alcohol],
            ['Copa de Verdejo', 330, [A::Sulphites], $alcohol],
            ['Vermut de grifo', 300, [A::Sulphites], $alcohol],
        ]);
        $this->products($this->category('Refrescos y agua', '#06b6d4', 6, $bar, ['Sin hielo', 'Con limón', 'Con hielo aparte', 'Del tiempo']), [
            ['Agua 50 cl', 200, []],
            ['Coca-Cola', 280, []],
            ['Coca-Cola Zero', 280, []],
            ['Fanta de naranja', 280, []],
            ['Tónica', 280, []],
            ['Zumo de naranja natural', 350, []],
        ]);
        $cafes = $this->products($this->category('Cafés', '#78350f', 7, $bar, ['Leche fría', 'Leche templada', 'Sacarina', 'Vaso de cristal', 'Muy caliente']), [
            ['Café solo', 140, []],
            ['Café con leche', 170, [A::Milk]],
            ['Cortado', 150, [A::Milk]],
            ['Infusión', 160, []],
        ]);
        $this->products($this->category('Copas', '#1e3a8a', 8, $bar, ['Sin hielo', 'Con limón', 'Tónica aparte']), [
            ['Gin-tonic', 850, [], $alcohol],
            ['Ron cola', 800, [], $alcohol],
        ]);

        $punto = $this->modifierGroup('Punto de la carne', 1, 1, ['Poco hecho' => 0, 'Al punto' => 0, 'Hecho' => 0]);
        foreach (['Entrecot a la brasa', 'Secreto ibérico'] as $meat) {
            $raciones[$meat]->modifierGroups()->attach($punto);
        }

        $leche = $this->modifierGroup('Leche', 0, 1, ['Entera' => 0, 'Desnatada' => 0, 'Sin lactosa' => 20, 'De avena' => 30]);
        foreach (['Café con leche', 'Cortado'] as $coffee) {
            $cafes[$coffee]->modifierGroups()->attach($leche);
        }

        $salsas = $this->modifierGroup('Salsa', 0, 2, ['Brava' => 0, 'Alioli' => 0, 'Extra de salsa' => 50]);
        $tapas['Patatas bravas']->modifierGroups()->attach($salsas);
    }
}
