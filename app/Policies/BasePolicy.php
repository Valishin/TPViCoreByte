<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Permisos del backoffice.
 *  - admin: todo.
 *  - encargado: solo si $managerAllowed.
 *  - $readOnly: nadie crea, edita ni borra (tickets, pedidos, cierres).
 * El borrado definitivo (force delete) no se permite nunca desde el panel.
 */
abstract class BasePolicy
{
    protected bool $managerAllowed = true;

    protected bool $readOnly = false;

    protected function allowed(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->role === UserRole::Admin
            || ($this->managerAllowed && $user->role === UserRole::Manager);
    }

    protected function canWrite(User $user): bool
    {
        return ! $this->readOnly && $this->allowed($user);
    }

    public function viewAny(User $user): bool
    {
        return $this->allowed($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->allowed($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->canWrite($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->canWrite($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->canWrite($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function replicate(User $user, Model $record): bool
    {
        return $this->canWrite($user);
    }

    public function reorder(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
