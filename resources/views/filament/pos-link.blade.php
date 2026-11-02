@php
    /** «Ir a la TPV» en la cabecera de /admin (no inicia sesión en la caja: allí se entra con PIN). */
    $newTab = (bool) \App\Models\Setting::query()->value('admin_pos_link_new_tab');
@endphp
<x-filament::button tag="a" href="/" color="gray" icon="heroicon-o-computer-desktop" size="sm"
    :target="$newTab ? '_blank' : null" :rel="$newTab ? 'noopener' : null">
    Ir a la TPV
</x-filament::button>
