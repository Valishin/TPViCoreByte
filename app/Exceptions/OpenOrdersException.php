<?php

namespace App\Exceptions;

use RuntimeException;

/** Se intenta cerrar la caja con pedidos abiertos: hace falta confirmación explícita (409). */
class OpenOrdersException extends RuntimeException
{
    /**
     * @param  list<string>  $orders  descripción de cada pedido abierto ("Mesa 3 · 24,50 €")
     * @param  list<array{label: string, path: ?string}>  $items  los mismos, con la pantalla donde resolverlo
     */
    public function __construct(public readonly array $orders, public readonly array $items = [])
    {
        parent::__construct('Hay pedidos abiertos o sin cobrar: '.implode(', ', $orders).'. Confirma si quieres cerrar la caja igualmente.');
    }
}
