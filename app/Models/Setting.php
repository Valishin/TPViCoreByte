<?php

namespace App\Models;

use App\Enums\HalfAndHalfModifierPricing;
use App\Enums\HalfAndHalfPricing;
use App\Enums\OrderType;
use App\Services\Pos\CatalogService;
use App\Support\QuickNotes;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajustes del local (una sola fila). Usa Setting::current().
 */
#[Unguarded]
class Setting extends Model
{
    protected $hidden = ['smtp_password'];

    use HasFactory;

    protected function casts(): array
    {
        return [
            'enabled_order_types' => AsEnumCollection::of(OrderType::class),
            'half_and_half_pricing' => HalfAndHalfPricing::class,
            'half_and_half_modifier_pricing' => HalfAndHalfModifierPricing::class,
            'delivery_fee_vat_rate' => 'integer',
            'auto_lock_minutes_pos' => 'integer',
            'auto_lock_minutes_mobile' => 'integer',
            'lock_pos_after_send' => 'boolean',
            'discount_limit_bp' => 'integer',
            'print_ticket_on_checkout' => 'boolean',
            'quick_notes' => 'array',
            'show_onscreen_keyboard_pos' => 'boolean',
            'takeaway_prep_minutes' => 'integer',
            'delivery_minutes' => 'integer',
            'print_courier_copy' => 'boolean',
            'invoice_require_address' => 'boolean',
            'smtp_password' => 'encrypted', // con APP_KEY: guárdala con la clave age (docs/instalacion-minipc.md)
            'smtp_port' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (Setting $setting) => $setting->quick_notes = QuickNotes::normalize($setting->quick_notes ?? []));

        // Las notas generales viajan con la carta del TPV.
        static::saved(function (Setting $setting) {
            if ($setting->wasChanged('quick_notes') || $setting->wasRecentlyCreated) {
                CatalogService::touched();
            }
        });
    }

    public static function current(): self
    {
        return static::query()->firstOrFail();
    }

    public function isOrderTypeEnabled(OrderType $type): bool
    {
        return $this->enabled_order_types->contains($type);
    }

    /** Datos fiscales que se copian en cada ticket. */
    public function issuerSnapshot(): array
    {
        return $this->only(['trade_name', 'legal_name', 'tax_id', 'address', 'postal_code', 'city', 'province', 'phone']);
    }

    public function defaultTicketSeries(): BelongsTo
    {
        return $this->belongsTo(TicketSeries::class, 'default_ticket_series_id');
    }

    public function rectifyingTicketSeries(): BelongsTo
    {
        return $this->belongsTo(TicketSeries::class, 'rectifying_ticket_series_id');
    }

    /** Serie F: facturas completas (directas y canjes). Separada de la de tickets (art. 7.1). */
    public function invoiceSeries(): BelongsTo
    {
        return $this->belongsTo(TicketSeries::class, 'invoice_series_id');
    }

    /** Serie FR: rectificativas de facturas completas (art. 6.1.a 2.º). */
    public function invoiceRectifyingSeries(): BelongsTo
    {
        return $this->belongsTo(TicketSeries::class, 'invoice_rectifying_series_id');
    }
}
