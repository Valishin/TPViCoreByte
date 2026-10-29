<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\Authorization;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\User;

class AuthorizationTest extends PosTestCase
{
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->role(UserRole::Manager)->withPin('9999')->create(['name' => 'Elena Encargada']);
    }

    private function token(string $action, string $pin = '9999'): ?string
    {
        return $this->asWaiter()->postJson('/api/authorize', ['manager_id' => $this->manager->id, 'pin' => $pin, 'action' => $action])->json('token');
    }

    public function test_discount_above_limit_needs_manager_pin_and_is_logged(): void
    {
        $orderId = $this->orderWith([[$this->product('Cena', 2000), 1]]);
        $line = Order::find($orderId)->lines()->first();

        // 10 % (el límite): sin autorización.
        $this->asWaiter()->postJson("/api/order-lines/{$line->id}/discount", ['type' => 'porcentaje', 'value' => 1000])->assertOk();

        // 20 %: hace falta un encargado.
        $this->asWaiter()->postJson("/api/order-lines/{$line->id}/discount", ['type' => 'porcentaje', 'value' => 2000])
            ->assertForbidden()->assertJsonPath('code', 'authorization_required')->assertJsonPath('action', 'descuento');

        $this->asWaiter()->postJson('/api/authorize', ['manager_id' => $this->manager->id, 'pin' => '0000', 'action' => 'descuento'])->assertUnprocessable();

        $token = $this->token('descuento');
        $this->asWaiter()->postJson("/api/order-lines/{$line->id}/discount", ['type' => 'porcentaje', 'value' => 2000, 'authorization_token' => $token, 'reason' => 'Cliente habitual'])
            ->assertOk()->assertJsonPath('data.total', 1600);

        $log = Authorization::query()->latest('id')->first();
        $this->assertSame($this->waiter->id, $log->requested_by);
        $this->assertSame($this->manager->id, $log->authorized_by);
        $this->assertSame('Cliente habitual', $log->reason);

        // El token es de un solo uso.
        $this->asWaiter()->postJson("/api/order-lines/{$line->id}/discount", ['type' => 'porcentaje', 'value' => 2500, 'authorization_token' => $token])->assertForbidden();
    }

    public function test_amount_discount_is_compared_as_percentage(): void
    {
        $orderId = $this->orderWith([[$this->product('Cena', 2000), 1]]);

        $this->asWaiter()->postJson("/api/orders/{$orderId}/discount", ['type' => 'importe', 'value' => 200])->assertOk(); // 10 %
        $this->asWaiter()->postJson("/api/orders/{$orderId}/discount", ['type' => 'importe', 'value' => 201])->assertForbidden();
    }

    public function test_token_is_bound_to_action_and_waiter(): void
    {
        $orderId = $this->orderWith([[$this->product('Cena', 2000), 1]]);
        $line = Order::find($orderId)->lines()->first();

        $token = $this->token('descuento');
        $this->asWaiter()->postJson("/api/order-lines/{$line->id}/invite", ['authorization_token' => $token])->assertForbidden();
    }

    public function test_invitation_requires_authorization_but_manager_authorizes_himself(): void
    {
        $orderId = $this->orderWith([[$this->product('Chupito', 300, $this->drinks), 1]]);
        $line = Order::find($orderId)->lines()->first();

        $this->asWaiter()->postJson("/api/order-lines/{$line->id}/invite")->assertForbidden();
        $this->asWaiter()->postJson("/api/order-lines/{$line->id}/invite", ['authorization_token' => $this->token('invitacion')])->assertOk()->assertJsonPath('data.total', 0);

        $other = Order::find($this->orderWith([[$this->product('Café', 140, $this->drinks), 1]]))->lines()->first();
        $this->asPos($this->manager)->postJson("/api/order-lines/{$other->id}/invite")->assertOk();
        $this->assertSame($this->manager->id, Authorization::query()->latest('id')->first()->authorized_by);
    }

    public function test_open_drawer_without_sale_needs_authorization_and_prints_pulse(): void
    {
        $this->openCash();

        $this->asWaiter()->postJson('/api/drawer/open')->assertForbidden();
        $this->asWaiter()->postJson('/api/drawer/open', ['authorization_token' => $this->token('abrir_cajon'), 'reason' => 'Cambio'])->assertOk();

        $job = PrintJob::query()->where('kind', 'cajon')->firstOrFail();
        $this->assertSame([['type' => 'pulse']], $job->content);
        $this->assertSame($this->tickets->id, $job->printer_id);
    }

    public function test_only_manager_opens_and_closes_cash(): void
    {
        $this->asWaiter()->postJson('/api/cash-session/open', ['opening_float' => 15000])->assertForbidden();
        $this->asWaiter()->postJson('/api/cash-session/open', ['opening_float' => 15000, 'authorization_token' => $this->token('abrir_caja')])
            ->assertCreated()->assertJsonPath('data.opened_by', 'Elena Encargada');

        $this->asWaiter()->postJson('/api/cash-session/close', ['counted_cash' => 15000])->assertForbidden();
        $this->asPos($this->manager)->postJson('/api/cash-session/close', ['counted_cash' => 15000])->assertOk()->assertJsonPath('data.z_number', 1);
    }

    public function test_manager_list_for_the_modal(): void
    {
        $names = collect($this->asWaiter()->getJson('/api/managers')->json('data'))->pluck('name')->all();

        $this->assertSame(['Elena Encargada'], $names);
    }
}
