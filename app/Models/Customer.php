<?php

namespace App\Models;

use App\Enums\TaxIdType;
use App\Support\PhoneNormalizer;
use App\Support\TaxId;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente identificado por teléfono. El teléfono se guarda normalizado
 * (E.164, p. ej. +34612345678); null tras anonimizar.
 */
#[Unguarded]
class Customer extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'orders_count' => 'integer',
            'last_order_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'tax_id_type' => TaxIdType::class,
            'email_invoices_accepted_at' => 'datetime',
        ];
    }

    /** NIF guardado normalizado (mayúsculas, sin espacios ni guiones). */
    protected function taxId(): Attribute
    {
        return Attribute::set(fn (?string $value) => TaxId::normalize($value));
    }

    /** ¿Tiene lo necesario para ser destinatario de una factura completa (art. 6.1.c, d, e)? */
    public function hasFiscalData(bool $requireAddress = true): bool
    {
        return filled($this->legal_name) && filled($this->tax_id)
            && (! $requireAddress || (filled($this->fiscal_address) && filled($this->fiscal_postal_code) && filled($this->fiscal_city)));
    }

    /** Copia de los datos fiscales que se guarda en la factura (no cambia si luego cambia la ficha). */
    public function recipientSnapshot(): array
    {
        return [
            'customer_id' => $this->id,
            'name' => $this->legal_name,
            'tax_id' => $this->tax_id,
            'tax_id_type' => $this->tax_id_type?->value,
            'address' => $this->fiscal_address,
            'postal_code' => $this->fiscal_postal_code,
            'city' => $this->fiscal_city,
            'province' => $this->fiscal_province,
            'country' => $this->fiscal_country ?? 'ES',
        ];
    }

    public function acceptsEmailInvoices(): bool
    {
        return $this->email_invoices_accepted_at !== null && filled($this->billing_email);
    }

    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => PhoneNormalizer::normalize($value));
    }

    public function scopeWherePhone(Builder $query, string $phone): void
    {
        $query->where('phone', PhoneNormalizer::normalize($phone));
    }

    public static function findByPhone(string $phone): ?self
    {
        $normalized = PhoneNormalizer::normalize($phone);

        return $normalized === null ? null : static::query()->where('phone', $normalized)->first();
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
