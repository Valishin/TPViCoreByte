<?php

namespace App\Support;

use Closure;

/**
 * Avisos a las pantallas agrupados: una acción puede guardar muchas filas
 * (ticket, pagos, movimientos…), pero las pantallas reciben UN aviso al final
 * de la petición (como CatalogService::touched).
 */
final class PosNotifier
{
    /** @param  Closure(): object  $event */
    public static function later(string $key, Closure $event): void
    {
        $flag = "pos.notify.{$key}";
        if (app()->bound($flag)) {
            return;
        }
        app()->instance($flag, true);
        app()->terminating(function () use ($flag, $event) {
            app()->forgetInstance($flag);
            event($event());
        });
    }
}
