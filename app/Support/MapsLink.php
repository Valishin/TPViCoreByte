<?php

namespace App\Support;

/** Enlace que abre una dirección en Google Maps (móvil del repartidor y QR de la copia). */
final class MapsLink
{
    /** @param  array<string, mixed>|null  $address  snapshot de la dirección del pedido */
    public static function forAddress(?array $address): ?string
    {
        if (! $address || empty($address['street'])) {
            return null;
        }
        $query = trim(($address['street'] ?? '').' '.($address['number'] ?? ''))
            .', '.trim(($address['postal_code'] ?? '').' '.($address['city'] ?? ''));

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(trim($query, ', '));
    }
}
