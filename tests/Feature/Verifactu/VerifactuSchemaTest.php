<?php

namespace Tests\Feature\Verifactu;

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Precheck;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Tickets\TicketIssuer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Esquema de VERI*FACTU: registros inmutables y una única cadena lineal (OM 7.a-c). */
class VerifactuSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSettings();
        $user = User::factory()->create();
        $order = app(OrderService::class)->open(OrderType::Bar, $user);
        app(OrderLineBuilder::class)->addProduct($order, Product::factory()->price(500)->create());
        $this->ticket = app(TicketIssuer::class)->issue($order, $user);
    }

    private function record(array $attributes = []): int
    {
        return DB::table('fiscal_records')->insertGetId($attributes + [
            'record_type' => 'alta', 'ticket_id' => $this->ticket->id, 'issuer_nif' => 'B12345674',
            'invoice_number' => $this->ticket->fullNumber(), 'issue_date' => '30-10-2026', 'invoice_type' => 'F2',
            'tax_total' => 45, 'amount_total' => 500, 'generated_at' => '2026-10-30T12:00:00+01:00',
            'hash' => strtoupper(bin2hex(random_bytes(32))), 'xml' => '<RegistroAlta/>',
        ]);
    }

    public function test_fiscal_records_cannot_be_updated_or_deleted(): void
    {
        $id = $this->record(['is_first' => true]);

        foreach ([fn () => DB::table('fiscal_records')->where('id', $id)->update(['xml' => '<x/>']),
            fn () => DB::table('fiscal_records')->where('id', $id)->delete()] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('El trigger debía impedirlo.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('inmutables', $e->getMessage());
            }
        }
    }

    public function test_single_first_record_and_no_forks_in_the_chain(): void
    {
        $first = $this->record(['is_first' => true]);
        $this->record(['previous_record_id' => $first]);

        $this->expectException(QueryException::class);
        $this->record(['previous_record_id' => $first]); // dos registros con el mismo anterior: bifurcación
    }

    public function test_only_one_first_record(): void
    {
        $this->record(['is_first' => true]);

        $this->expectException(QueryException::class);
        $this->record(['is_first' => true]);
    }

    public function test_prechecks_are_immutable(): void
    {
        $precheck = Precheck::create(['order_id' => Order::query()->first()->id, 'total' => 500, 'content' => ['lines' => []], 'printed_at' => now()]);

        $this->expectException(QueryException::class);
        DB::table('prechecks')->where('id', $precheck->id)->update(['total' => 1]);
    }

    public function test_ticket_can_be_annulled_only_from_issued(): void
    {
        $this->ticket->update(['status' => 'anulado']);
        $this->assertSame('anulado', $this->ticket->refresh()->status->value);

        $this->expectException(QueryException::class);
        DB::table('tickets')->where('id', $this->ticket->id)->update(['status' => 'emitido']);
    }
}
