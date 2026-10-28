<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Admin y encargado. Una mesa con pedidos no se borra: se desactiva. */
class TablePolicy extends BasePolicy
{
    public function delete(User $user, Model $record): bool
    {
        return parent::delete($user, $record) && ! $record->orders()->exists();
    }
}
