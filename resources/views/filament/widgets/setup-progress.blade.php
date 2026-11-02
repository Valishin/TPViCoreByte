<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Puesta en marcha: {{ $completed }} de {{ $total }} pasos</x-slot>
        <ul style="display:flex;flex-wrap:wrap;gap:.5rem 1.25rem;margin:0 0 1rem;padding:0;list-style:none">
            @foreach ($steps as $step)
                <li>{{ $step['complete'] ? '✓' : '○' }} {{ $step['label'] }}{{ $step['skipped'] ? ' (omitido)' : '' }}</li>
            @endforeach
        </ul>
        <x-filament::button tag="a" :href="$url" icon="heroicon-o-rocket-launch">Continuar la puesta en marcha</x-filament::button>
    </x-filament::section>
</x-filament-widgets::widget>
