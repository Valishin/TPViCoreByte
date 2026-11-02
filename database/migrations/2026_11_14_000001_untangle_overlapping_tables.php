<?php

use App\Support\FloorLayout;
use Illuminate\Database\Migrations\Migration;

/**
 * Las mesas creadas en /admin quedaban todas en (0,0) y en el plano de la caja
 * se dibujaban unas encima de otras (parecía que desaparecían). Se separan las
 * que se pisan; las más antiguas se quedan donde están.
 */
return new class extends Migration
{
    public function up(): void
    {
        FloorLayout::untangle();
    }

    public function down(): void
    {
        // Las posiciones anteriores se pisaban: no se restauran.
    }
};
