<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Admin y encargado. Una sala con mesas que tienen pedidos no se puede borrar. */
class RoomPolicy extends BasePolicy
{
    public function delete(User $user, Model $record): bool
    {
        return parent::delete($user, $record)
            && ! $record->tables()->whereHas('orders')->exists();
    }
}
