<?php

namespace App\Policies;

/** Solo consulta (cierres de caja); admin y encargado. */
class CashSessionPolicy extends BasePolicy
{
    protected bool $readOnly = true;
}
