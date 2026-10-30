<?php

namespace App\Console\Commands;

use App\Enums\HalfAndHalfModifierPricing;
use App\Enums\HalfAndHalfPricing;
use App\Enums\OrderType;
use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\TicketSeries;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Instalación real (sin datos de demo): series T y R, ajustes del local y el
 * admin. Idempotente: si ya existen, no se tocan (salvo --reset-password).
 * Lo lanza install.sh; imprime las credenciales en JSON para el resumen.
 */
#[Signature('tpv:setup
    {--name= : Nombre comercial del local}
    {--admin-email= : Email del administrador (entra en /admin)}
    {--admin-name=Administrador : Nombre del administrador}
    {--types=mesa,barra : Tipos de pedido activos (mesa,barra,para_llevar,domicilio)}
    {--reset-password : Genera una contraseña y un PIN nuevos para el admin}
    {--json : Salida en JSON}')]
#[Description('Prepara una instalación nueva del TPV (ajustes, series y admin) sin datos de demo')]
class SetupCommand extends Command
{
    public function handle(): int
    {
        $email = $this->option('admin-email');
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Indica --admin-email con un email válido.');

            return self::FAILURE;
        }
        $types = collect(explode(',', (string) $this->option('types')))->map(fn ($t) => OrderType::tryFrom(trim($t)))->filter()->values();
        if ($types->isEmpty()) {
            $this->error('Ningún tipo de pedido válido en --types.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($email, $types) {
            $default = TicketSeries::query()->firstOrCreate(['code' => 'T'], ['name' => 'Tickets']);
            $rectifying = TicketSeries::query()->firstOrCreate(['code' => 'R'], ['name' => 'Rectificativos', 'is_rectifying' => true]);
            $invoices = TicketSeries::query()->firstOrCreate(['code' => 'F'], ['name' => 'Facturas']);
            $invoiceRectifying = TicketSeries::query()->firstOrCreate(['code' => 'FR'], ['name' => 'Rectificativas de facturas', 'is_rectifying' => true]);

            $created = false;
            if (! Setting::query()->exists()) {
                $name = $this->option('name') ?: 'Mi local';
                Setting::create([
                    'trade_name' => $name,
                    'legal_name' => $name,  // datos fiscales reales: /admin → Ajustes del local
                    'tax_id' => 'PENDIENTE',
                    'enabled_order_types' => $types->all(),
                    'default_ticket_series_id' => $default->id,
                    'rectifying_ticket_series_id' => $rectifying->id,
                    'invoice_series_id' => $invoices->id,
                    'invoice_rectifying_series_id' => $invoiceRectifying->id,
                    'half_and_half_pricing' => HalfAndHalfPricing::MostExpensive,
                    'half_and_half_modifier_pricing' => HalfAndHalfModifierPricing::Full,
                    'ticket_footer' => "Gracias por su visita\nIVA incluido",
                ]);
                $created = true;
            }

            $admin = User::query()->where('email', $email)->first();
            $password = null;
            $pin = null;
            if (! $admin || $this->option('reset-password')) {
                $password = Str::password(16, symbols: false);
                $pin = (string) random_int(1000, 9999);
                $admin = User::query()->updateOrCreate(['email' => $email], [
                    'name' => $admin?->name ?? $this->option('admin-name'),
                    'role' => UserRole::Admin,
                    'password' => $password,
                    'pin' => $pin,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);
            }

            return ['settings_created' => $created, 'admin_email' => $admin->email, 'admin_password' => $password, 'admin_pin' => $pin];
        });

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info($result['settings_created'] ? 'Local creado.' : 'El local ya existía: ajustes sin cambios.');
        $this->line("Admin: {$result['admin_email']}");
        if ($result['admin_password']) {
            $this->line("Contraseña: {$result['admin_password']}   PIN del TPV: {$result['admin_pin']}");
            $this->warn('Apúntalos ahora: no se vuelven a mostrar.');
        } else {
            $this->line('El admin ya existía: contraseña sin cambios (usa --reset-password para generar otra).');
        }

        return self::SUCCESS;
    }
}
