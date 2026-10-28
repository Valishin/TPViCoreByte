<?php

namespace Tests\Feature\Filament;

use App\Enums\OrderType;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Product;
use App\Models\Ticket;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Tickets\TicketIssuer;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

class TicketResourceTest extends FilamentTestCase
{
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $order = app(OrderService::class)->open(OrderType::Bar, $this->admin);
        app(OrderLineBuilder::class)->addProduct($order, Product::factory()->price(350)->create(['name' => 'Vermut']), quantity: 2);
        $this->ticket = app(TicketIssuer::class)->issue($order, $this->admin);
    }

    public function test_tickets_have_no_edit_or_delete_anywhere(): void
    {
        $this->assertFalse(TicketResource::hasPage('edit'));
        $this->assertFalse(TicketResource::hasPage('create'));
        $this->assertFalse(TicketResource::canEdit($this->ticket));
        $this->assertFalse(TicketResource::canDelete($this->ticket));
        $this->assertFalse(TicketResource::canCreate());

        Livewire::test(ListTickets::class)
            ->assertCanSeeTableRecords([$this->ticket])
            ->assertActionDoesNotExist(TestAction::make('edit')->table($this->ticket))
            ->assertActionDoesNotExist(TestAction::make('delete')->table($this->ticket))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk())
            ->assertActionDoesNotExist('create');

        Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete')
            ->assertSee($this->ticket->fullNumber())
            ->assertSee('Vermut')
            ->assertSee('7,00 €');

        $this->get("/admin/tickets/{$this->ticket->id}/edit")->assertNotFound();
    }
}
