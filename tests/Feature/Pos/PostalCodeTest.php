<?php

namespace Tests\Feature\Pos;

use App\Models\PrintJob;
use App\Models\Setting;
use App\Models\Ticket;
use App\Support\PostalCode;

/** Código postal → municipio y provincia: en la dirección y en el ticket de domicilio. */
class PostalCodeTest extends TakeawayTestCase
{
    public function test_province_from_the_first_two_digits(): void
    {
        $this->assertSame('Girona', PostalCode::province('17220'));
        $this->assertSame('Madrid', PostalCode::province('28010'));
        $this->assertSame('Santa Cruz de Tenerife', PostalCode::province('38001'));
        $this->assertNull(PostalCode::province('99001'));
        $this->assertNull(PostalCode::province('1722'));
        $this->assertSame('17220 Sant Feliu de Guíxols (Girona)', PostalCode::place('17220', 'Sant Feliu de Guíxols'));
        $this->assertSame('28010 Madrid', PostalCode::place('28010', 'Madrid'), 'Sin repetir la provincia si es igual.');
    }

    public function test_city_is_learned_from_saved_addresses_and_the_local(): void
    {
        $this->assertNull(PostalCode::city('17220'));
        Setting::current()->update(['postal_code' => '17220', 'city' => 'Sant Feliu de Guíxols']);
        $this->assertSame('Sant Feliu de Guíxols', PostalCode::city('17220'), 'El CP del local.');

        $this->lucia->addresses()->create(['street' => 'Carrer Major', 'postal_code' => '17250', 'city' => "Platja d'Aro", 'delivery_zone_id' => $this->centro->id]);
        $this->asWaiter()->getJson('/api/delivery-zones?postal_code=17250')->assertOk()
            ->assertJsonPath('city', "Platja d'Aro")->assertJsonPath('province', 'Girona');
    }

    public function test_new_address_without_city_gets_it_from_the_postal_code(): void
    {
        $this->lucia->addresses()->create(['street' => 'Rambla Vidal', 'postal_code' => '17220', 'city' => 'Sant Feliu de Guíxols', 'delivery_zone_id' => $this->centro->id]);

        $this->asWaiter()->postJson('/api/customers', [
            'phone' => '699111222', 'name' => 'Marc', 'address' => ['street' => 'Carrer de Sant Pere', 'number' => '4', 'postal_code' => '17220', 'delivery_zone_id' => $this->centro->id],
        ])->assertCreated()->assertJsonPath('customer.addresses.0.city', 'Sant Feliu de Guíxols');
    }

    public function test_delivery_ticket_shows_town_and_province(): void
    {
        $this->openCash();
        $this->luciaHome->update(['postal_code' => '17220', 'city' => 'Sant Feliu de Guíxols']);
        $id = $this->deliveryWith();
        $this->send($id)->assertOk();
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/status", ['status' => 'listo'])->assertOk();
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/dispatch", ['courier_id' => $this->javi->id])->assertOk();

        $ticket = Ticket::query()->where('order_id', $id)->sole();
        $text = $this->printedText(PrintJob::query()->where('ticket_id', $ticket->id)->where('kind', 'ticket')->sole()->content);
        $this->assertStringContainsString('17220 Sant Feliu de Guíxols (Girona)', $text);
    }
}
