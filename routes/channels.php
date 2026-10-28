<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Canal de todas las pantallas del TPV (caja y móviles).
Broadcast::channel('pos', fn (User $user) => $user->canUsePos(), ['guards' => ['pos']]);
