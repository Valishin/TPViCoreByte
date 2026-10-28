<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Series de tickets: solo admin. Se desactivan, no se borran. */
class TicketSeriesPolicy extends BasePolicy
{
    protected bool $managerAllowed = false;

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
