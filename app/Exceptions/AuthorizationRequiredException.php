<?php

namespace App\Exceptions;

use App\Enums\AuthorizationAction;
use RuntimeException;

/** La acción necesita la autorización (PIN) de un encargado. La API responde 403 con el código. */
class AuthorizationRequiredException extends RuntimeException
{
    public function __construct(public readonly AuthorizationAction $action)
    {
        parent::__construct("{$action->label()}: necesita la autorización de un encargado.");
    }
}
