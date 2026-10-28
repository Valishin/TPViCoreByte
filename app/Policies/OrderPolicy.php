<?php

namespace App\Policies;

/** Solo consulta (pedidos); admin y encargado. */
class OrderPolicy extends BasePolicy
{
    protected bool $readOnly = true;
}
