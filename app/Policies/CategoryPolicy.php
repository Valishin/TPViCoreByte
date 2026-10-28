<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Admin y encargado. Una categoría con productos no se puede borrar. */
class CategoryPolicy extends BasePolicy
{
    public function delete(User $user, Model $record): bool
    {
        return parent::delete($user, $record) && ! $record->products()->exists();
    }
}
