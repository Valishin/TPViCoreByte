<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Testing\TestResponse;

class PosAuthTest extends PosTestCase
{
    private function login(int $userId, string $pin): TestResponse
    {
        // Origin de un dominio "stateful": Sanctum arranca la sesión por cookie.
        return $this->withHeader('Origin', 'http://localhost')->postJson('/api/login-pin', ['user_id' => $userId, 'pin' => $pin]);
    }

    public function test_staff_lists_pos_users_and_couriers_without_sensitive_data(): void
    {
        User::factory()->role(UserRole::Courier)->withPin('5555')->create(['name' => 'Repartidor']);
        User::factory()->withPin('1111')->create(['name' => 'Inactivo', 'is_active' => false]);
        User::factory()->role(UserRole::Courier)->create(['name' => 'Sin PIN']);

        $staff = $this->getJson('/api/staff')->assertOk()->json('data');

        $this->assertSame(['Ana López', 'Repartidor'], collect($staff)->pluck('name')->all());
        $this->assertSame('repartidor', $staff[1]['role']); // la pantalla de PIN los pone aparte
        $this->assertSame(['id', 'name', 'initials', 'color', 'role'], array_keys($staff[0]));
        $this->assertSame('AL', $staff[0]['initials']);
    }

    public function test_login_with_pin_and_me(): void
    {
        $this->login($this->waiter->id, '1234')
            ->assertOk()
            ->assertJsonPath('user.name', 'Ana López')
            ->assertJsonPath('settings.auto_lock_minutes_pos', 2)
            ->assertJsonPath('settings.auto_lock_minutes_mobile', 0);

        $this->withHeader('Origin', 'http://localhost')->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $this->waiter->id);
    }

    public function test_wrong_pin_is_rejected_and_rate_limited_per_user(): void
    {
        foreach (range(1, 5) as $i) {
            $this->login($this->waiter->id, '0000')->assertUnprocessable()->assertJsonValidationErrors('pin');
        }

        $this->login($this->waiter->id, '1234')->assertStatus(429)->assertJsonStructure(['message', 'retry_after']);

        // Otro camarero no se ve afectado por los intentos de Ana.
        $luis = User::factory()->withPin('2222')->create();
        $this->login($luis->id, '2222')->assertOk();
    }

    public function test_courier_signs_in_but_cannot_use_room_pos(): void
    {
        $courier = User::factory()->role(UserRole::Courier)->withPin('5555')->create();

        $this->login($courier->id, '5555')->assertOk()->assertJsonPath('user.role', 'repartidor');
        $this->actingAs($courier, 'pos')->getJson('/api/rooms')->assertForbidden();
        $this->actingAs($courier, 'pos')->getJson('/api/takeaway-orders')->assertForbidden();
        $this->actingAs($courier, 'pos')->getJson('/api/my-deliveries')->assertOk();

        // Y el personal de sala no entra en "Mis repartos".
        $this->asPos($this->waiter)->getJson('/api/my-deliveries')->assertForbidden();
    }

    public function test_api_requires_login(): void
    {
        $this->getJson('/api/rooms')->assertUnauthorized();
        $this->getJson('/api/catalog')->assertUnauthorized();
    }

    public function test_logout_only_closes_pos_session(): void
    {
        $this->login($this->waiter->id, '1234')->assertOk();
        $this->withHeader('Origin', 'http://localhost')->postJson('/api/logout')->assertOk();

        $this->assertGuest('pos');
    }
}
