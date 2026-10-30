<?php

namespace App\Console\Commands;

use App\Enums\HalfAndHalfModifierPricing;
use App\Enums\HalfAndHalfPricing;
use App\Enums\OrderType;
use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\TicketSeries;
use App\Models\User;
use App\Services\Verifactu\CertificateStore;
use App\Support\TaxId;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Instalación real (sin datos de demo): series T y R, ajustes del local y el
 * admin. Idempotente: si ya existen, no se tocan (salvo --reset-password).
 * Lo lanza install.sh; imprime las credenciales en JSON para el resumen.
 *
 * Producción (decisión Q5 de docs/verifactu/PLAN.md): el instalador lo llama
 * primero con los datos fiscales y el régimen de IVA, instala el certificado
 * (tpv:verifactu:certificate) y lo vuelve a llamar con --verifactu, que activa
 * VERI*FACTU en el entorno de producción de la AEAT desde ese momento (antes de
 * la primera factura). Sin certificado o sin NIF válido no se activa.
 */
#[Signature('tpv:setup
    {--name= : Nombre comercial del local}
    {--admin-email= : Email del administrador (entra en /admin)}
    {--admin-name=Administrador : Nombre del administrador}
    {--types=mesa,barra : Tipos de pedido activos (mesa,barra,para_llevar,domicilio)}
    {--legal-name= : Razón social (si aún no hay datos fiscales)}
    {--tax-id= : NIF del titular (si aún no hay datos fiscales)}
    {--vat-regime= : Clave de régimen de IVA: 01 general, 20 simplificado}
    {--verifactu : Activa VERI*FACTU en producción (exige certificado instalado)}
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

        $regime = $this->option('vat-regime');
        if ($regime !== null && ! in_array($regime, ['01', '20'], true)) {
            $this->error('--vat-regime debe ser 01 (general) o 20 (simplificado).');

            return self::FAILURE;
        }
        $taxId = $this->option('tax-id') ? strtoupper(trim((string) $this->option('tax-id'))) : null;
        if ($taxId && ! TaxId::isValidSpanish($taxId)) {
            $this->error("El NIF {$taxId} no es válido.");

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($email, $types, $regime, $taxId) {
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
            // Datos fiscales del instalador: solo si siguen sin rellenar (no pisa lo de /admin).
            $settings = Setting::current();
            $fiscal = array_filter([
                'tax_id' => $taxId && $settings->tax_id === 'PENDIENTE' ? $taxId : null,
                'legal_name' => $this->option('legal-name') && ($created || $settings->tax_id === 'PENDIENTE') ? $this->option('legal-name') : null,
                'vat_regime_key' => $regime,
            ]);
            if ($fiscal) {
                $settings->update($fiscal);
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

        $result['verifactu'] = null;
        if ($this->option('verifactu')) {
            $settings = Setting::current();
            if (! CertificateStore::installed($settings)) {
                $this->error('VERI*FACTU: instala antes el certificado (tpv:verifactu:certificate).');

                return self::FAILURE;
            }
            if (! TaxId::isValidSpanish($settings->tax_id)) {
                $this->error('VERI*FACTU: falta el NIF del titular (--tax-id).');

                return self::FAILURE;
            }
            if (! $settings->verifactu_enabled) {
                $settings->update(['verifactu_enabled' => true, 'verifactu_environment' => 'produccion', 'verifactu_started_at' => now(), 'verifactu_end_date' => null]);
            }
            $result['verifactu'] = ['environment' => $settings->verifactu_environment, 'started_at' => $settings->verifactu_started_at->toIso8601String()];
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info($result['settings_created'] ? 'Local creado.' : 'El local ya existía: ajustes sin cambios.');
        if ($result['verifactu']) {
            $this->info("VERI*FACTU activo ({$result['verifactu']['environment']}) desde {$result['verifactu']['started_at']}.");
        }
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
