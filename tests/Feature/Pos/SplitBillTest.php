<?php

namespace Tests\Feature\Pos;

use App\Models\Order;
use App\Models\Table;

class SplitBillTest extends PosTestCase
{
    public function test_split_by_products_creates_new_order_and_each_part_gets_its_own_ticket(): void
    {
        $this->openCash();
        $table = Table::factory()->create();
        $order = $this->openTable($table);
        $beer = $this->addLine($order['id'], ['product_id' => $this->product('Caña', 250, $this->drinks)->id, 'quantity' => 4])->json('line_id');
        $tapa = $this->addLine($order['id'], ['product_id' => $this->product('Bravas', 550)->id])->json('line_id');

        $new = $this->asWaiter()->postJson("/api/orders/{$order['id']}/split", ['lines' => [$beer => 1, $tapa => 1]])
            ->assertCreated()->assertJsonPath('data.total', 800)->json('data');

        $this->assertSame(750, Order::find($order['id'])->total); // quedan 3 cañas
        $this->assertSame(1550, 750 + $new['total']);

        $this->checkout($new['id'], [['method' => 'tarjeta', 'amount' => 800]])->assertCreated();
        $this->assertSame('ocupada', $table->fresh()->status->value); // queda el resto en la mesa

        $this->checkout($order['id'], [['method' => 'efectivo', 'amount' => 750]])->assertCreated();
        $this->assertSame('libre', $table->fresh()->status->value);
    }

    public function test_cannot_split_everything(): void
    {
        $order = $this->openTable();
        $beer = $this->addLine($order['id'], ['product_id' => $this->product('Caña', 250, $this->drinks)->id, 'quantity' => 2])->json('line_id');

        $this->asWaiter()->postJson("/api/orders/{$order['id']}/split", ['lines' => [$beer => 2]])->assertUnprocessable();
    }
}
