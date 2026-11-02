<x-filament-panels::page>
    <x-filament::section heading="Sistema y obligado tributario">
        <dl style="display:grid;grid-template-columns:max-content 1fr;gap:.5rem 1.5rem">
            <dt style="font-weight:600">Obligado tributario</dt><dd>{{ $obliged['name'] }} · NIF {{ $obliged['nif'] }}</dd>
            <dt style="font-weight:600">Sistema</dt><dd>{{ $system['name'] }} ({{ $system['id'] }}) · versión {{ $system['version'] }}</dd>
            <dt style="font-weight:600">Número de instalación</dt><dd>{{ $system['installation'] }}</dd>
            <dt style="font-weight:600">Productor</dt><dd>{{ $system['producer'] }} · NIF {{ $system['producer_nif'] }}<br>{{ $system['producer_address'] }}</dd>
            <dt style="font-weight:600">Soporte técnico</dt><dd>@include('filament.support-contact', ['inline' => true])</dd>
            <dt style="font-weight:600">VERI*FACTU</dt><dd>{{ $verifactu['active'] ? 'Activo' : 'No activo' }} · entorno de {{ $verifactu['environment'] }}</dd>
        </dl>
    </x-filament::section>
    <x-filament::section heading="Declaración responsable">
        <div class="prose max-w-none dark:prose-invert" style="line-height:1.5">{!! $declaration_html !!}</div>
    </x-filament::section>
</x-filament-panels::page>
