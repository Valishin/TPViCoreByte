<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class FilamentTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSettings();
        $this->admin = User::factory()->role(UserRole::Admin)->create();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
    }

    protected function actingAsManager(): User
    {
        $manager = User::factory()->role(UserRole::Manager)->create();
        $this->actingAs($manager);

        return $manager;
    }
}
