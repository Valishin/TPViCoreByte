<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Usuarios: solo admin. Nadie puede borrarse a sí mismo. */
class UserPolicy extends BasePolicy
{
    protected bool $managerAllowed = false;

    public function delete(User $user, Model $record): bool
    {
        return parent::delete($user, $record) && ! $user->is($record);
    }
}
