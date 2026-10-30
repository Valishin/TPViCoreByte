<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\LocalSettings;
use App\Models\Setting;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

class SmtpSettingsTest extends FilamentTestCase
{
    public function test_smtp_password_is_kept_when_left_blank(): void
    {
        Livewire::test(LocalSettings::class)
            ->fillForm(['smtp_host' => 'smtp.ejemplo.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => 'tpv',
                'smtp_password' => 'secreta', 'mail_from_address' => 'facturas@local.cat'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('secreta', Setting::current()->smtp_password);

        // Volver a guardar sin escribir la contraseña: se conserva.
        Livewire::test(LocalSettings::class)->assertFormSet(['smtp_password' => null])
            ->fillForm(['smtp_host' => 'smtp2.ejemplo.com'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('secreta', Setting::current()->smtp_password);
        $this->assertSame('smtp2.ejemplo.com', Setting::current()->smtp_host);
    }

    public function test_send_test_email(): void
    {
        Mail::fake();
        Setting::current()->update(['smtp_host' => 'smtp.ejemplo.com', 'mail_from_address' => 'facturas@local.cat']);

        Livewire::test(LocalSettings::class)
            ->callAction('sendTestEmail', ['to' => 'yo@local.cat'])
            ->assertNotified('Enviado a yo@local.cat');
    }
}
