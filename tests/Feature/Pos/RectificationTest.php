<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Exceptions\ImmutableTicketException;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\RectificationService;

class RectificationTest extends PosTestCase
{
    private User $manager;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openCash();
        $this->manager = User::factory()->role(UserRole::Manager)->withPin('9999')->create();
        $beer = $this->product('Caña', 250, $this->drinks);
        $food = $this->product('Bravas', 550);
        $orderId = $this->orderWith([[$beer, 3], [$food, 1]]); // 7,50 + 5,50 = 13,00
        $id = $this->checkout($orderId, [['method' => 'efectivo', 'amount' => 1300, 'tendered' => 1300]])->json('data.ticket.id');
        $this->ticket = Ticket::find($id);
    }

    public function test_full_cancellation_by_differences(): void
    {
        $response = $this->asPos($this->manager)->postJson("/api/tickets/{$this->ticket->id}/rectify", ['reason' => 'Error de cobro'])->assertCreated();

        $r = Ticket::find($response->json('data.id'));
        $this->assertSame('rectificativa', $r->type->value);
        $this->assertTrue($r->series->is_rectifying);
        $this->assertSame(1, $r->number);
        $this->assertSame(-1300, $r->total);
        $this->assertSame(-1300, array_sum(array_column($r->vat_breakdown, 'total')));
        $this->assertSame($this->ticket->id, $r->rectified_ticket_id);
        $this->assertSame('diferencias', $r->rectification_method->value);
        $this->assertSame([-3, -1], $r->lines->pluck('quantity')->all());

        // Devolución: pago negativo en efectivo (resta del efectivo esperado).
        $this->assertSame(-1300, (int) $r->payments()->sum('amount'));
        $this->assertSame(PaymentMethod::Cash, $r->payments()->first()->method);

        // El original solo cambia de estado.
        $original = $this->ticket->fresh();
        $this->assertSame(TicketStatus::Rectified, $original->status);
        $this->assertSame(1300, $original->total);
        $this->expectException(ImmutableTicketException::class);
        $original->update(['total' => 0]);
    }

    public function test_partial_cancellations_add_up_exactly_and_respect_remaining(): void
    {
        $line = $this->ticket->lines()->where('name', 'Caña')->first();

        $first = app(RectificationService::class)->byDifferences($this->ticket, $this->manager, 'Una caña de más', [$line->id => 1]);
        $this->assertSame(-250, $first->total);

        $second = app(RectificationService::class)->byDifferences($this->ticket->fresh(), $this->manager, 'Otras dos', [$line->id => 2]);
        $this->assertSame(-500, $second->total);
        $this->assertSame(2, $second->number); // numeración correlativa en la serie de rectificativos

        $this->asPos($this->manager)->postJson("/api/tickets/{$this->ticket->id}/rectify", ['reason' => 'Otra', 'lines' => [$line->id => 1]])
            ->assertUnprocessable();
    }

    public function test_partial_amounts_square_to_the_cent(): void
    {
        $this->ticket = Ticket::find($this->checkout($this->orderWith([[$this->product('Tapa', 333), 3]]), [['method' => 'tarjeta', 'amount' => 999]])->json('data.ticket.id'));
        $line = $this->ticket->lines()->first();
        $service = app(RectificationService::class);

        $totals = [];
        foreach ([1, 1, 1] as $units) {
            $totals[] = $service->byDifferences($this->ticket->fresh(), $this->manager, 'Devolución', [$line->id => $units])->total;
        }

        $this->assertSame(-999, array_sum($totals));
    }

    public function test_substitution_contains_corrected_ticket_and_refunds_difference(): void
    {
        $line = $this->ticket->lines()->where('name', 'Caña')->first();

        $r = app(RectificationService::class)->bySubstitution($this->ticket, $this->manager, 'Sobraba una caña', [$line->id => 1]);

        $this->assertSame('sustitucion', $r->rectification_method->value);
        $this->assertSame(1050, $r->total); // 2 cañas + bravas
        $this->assertSame(['base' => $this->ticket->tax_base, 'tax' => $this->ticket->tax_amount, 'total' => 1300], $r->rectified_amounts);
        $this->assertSame(-250, (int) $r->payments()->sum('amount'));
    }

    public function test_waiter_needs_authorization_to_void_a_ticket(): void
    {
        $this->asWaiter()->postJson("/api/tickets/{$this->ticket->id}/rectify", ['reason' => 'Error'])->assertForbidden()->assertJsonPath('code', 'authorization_required');
    }
}
