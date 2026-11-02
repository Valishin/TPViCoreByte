<?php

namespace App\Console\Commands;

use App\Enums\HalfAndHalfModifierPricing;
use App\Enums\HalfAndHalfPricing;
use App\Enums\OrderType;
use App\Models\Setting;
use App\Models\TicketSeries;
use App\Models\User;
use App\Support\SetupCode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Instalación nueva sin datos de demo: series (T, R, F, FR) y ajustes del
 * local por rellenar. El negocio (empresa, NIF, régimen, impresoras, copias,
 * VERI*FACTU…) y el administrador se configuran después en /admin (primer
 * acceso con el código de configuración y «Puesta en marcha»).
 *
 * Idempotente: si ya existen, no se tocan. Mientras no haya usuarios genera
 * (o renueva) el código de configuración y lo devuelve; lo lanza install.sh.
 */
#[Signature('tpv:setup
    {--name= : Nombre comercial provisional (se cambia en /admin)}
    {--json : Salida en JSON}')]
#[Description('Prepara una instalación nueva del TPV (ajustes y series) y el código para crear el administrador en /admin')]
class SetupCommand extends Command
{
    public function handle(): int
    {
        $created = DB::transaction(function () {
            $default = TicketSeries::query()->firstOrCreate(['code' => 'T'], ['name' => 'Tickets']);
            $rectifying = TicketSeries::query()->firstOrCreate(['code' => 'R'], ['name' => 'Rectificativos', 'is_rectifying' => true]);
            $invoices = TicketSeries::query()->firstOrCreate(['code' => 'F'], ['name' => 'Facturas']);
            $invoiceRectifying = TicketSeries::query()->firstOrCreate(['code' => 'FR'], ['name' => 'Rectificativas de facturas', 'is_rectifying' => true]);

            if (Setting::query()->exists()) {
                return false;
            }
            $name = $this->option('name') ?: 'Mi local';
            Setting::create([
                'trade_name' => $name,
                'legal_name' => $name,   // datos fiscales reales: /admin → Puesta en marcha
                'tax_id' => 'PENDIENTE',
                'enabled_order_types' => [OrderType::Table, OrderType::Bar],
                'default_ticket_series_id' => $default->id,
                'rectifying_ticket_series_id' => $rectifying->id,
                'invoice_series_id' => $invoices->id,
                'invoice_rectifying_series_id' => $invoiceRectifying->id,
                'half_and_half_pricing' => HalfAndHalfPricing::MostExpensive,
                'half_and_half_modifier_pricing' => HalfAndHalfModifierPricing::Full,
                'ticket_footer' => "Gracias por su visita\nIVA incluido",
            ]);

            return true;
        });

        // Sin usuarios: código nuevo para «Crear administrador» (el anterior deja de valer).
        $code = User::query()->exists() ? null : SetupCode::issue();
        $result = ['settings_created' => $created, 'setup_code' => $code];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->info($created ? 'Local creado (datos por rellenar en /admin).' : 'El local ya existía: ajustes sin cambios.');
        $this->line($code ? "Código de configuración para crear el administrador en /admin: {$code}" : 'El administrador ya existe.');

        return self::SUCCESS;
    }
}
