<?php

namespace App\Policies;

/** Ajustes fiscales del local: solo admin. */
class SettingPolicy extends BasePolicy
{
    protected bool $managerAllowed = false;
}
