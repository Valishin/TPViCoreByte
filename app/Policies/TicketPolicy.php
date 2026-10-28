<?php

namespace App\Policies;

/** Solo consulta (tickets: inmutables); admin y encargado. */
class TicketPolicy extends BasePolicy
{
    protected bool $readOnly = true;
}
