<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Canal de todas las pantallas del TPV (caja, móviles y repartidores): los
// eventos solo llevan identificadores y estados, cada pantalla recarga lo suyo.
Broadcast::channel('pos', fn (User $user) => $user->canSignInPos(), ['guards' => ['pos']]);
