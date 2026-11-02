<?php

namespace App\Filament\Resources\Printers\Schemas;

use App\Enums\PrinterConnection;
use App\Enums\PrinterType;
use App\Services\Printing\UsbPrinterDetector;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class PrinterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label('Nombre')->required()->maxLength(255)->placeholder('Cocina'),
                ToggleButtons::make('type')->label('Uso')->options(PrinterType::class)->default(PrinterType::Kitchen)->inline()->required(),
                ToggleButtons::make('connection_type')->label('Conexión')->options(PrinterConnection::class)
                    ->default(PrinterConnection::Network)->inline()->required()->live()->columnSpanFull(),
                TextInput::make('host')->label('Dirección IP')->placeholder('192.168.1.200')
                    ->ipv4()
                    ->visible(fn (Get $get) => ! self::usb($get))->required(fn (Get $get) => ! self::usb($get))
                    ->helperText('La impresora debe tener IP fija en el router.'),
                TextInput::make('port')->label('Puerto')->numeric()->integer()->default(9100)
                    ->visible(fn (Get $get) => ! self::usb($get))->required(fn (Get $get) => ! self::usb($get)),
                TextInput::make('device_path')->label('Dispositivo USB')->placeholder('/dev/usb/impresora-04b8-0202')
                    ->live(onBlur: true)
                    ->startsWith(['/'])
                    ->validationMessages(['starts_with' => 'Debe ser una ruta absoluta, p. ej. /dev/usb/lp0.'])
                    ->visible(fn (Get $get) => self::usb($get))->required(fn (Get $get) => self::usb($get))
                    ->columnSpanFull()
                    ->suffixAction(self::scanAction())
                    // Indicador de estado: ¿está conectada ahora mismo la impresora de este dispositivo?
                    ->hint(fn (?string $state) => self::status($state)['text'])
                    ->hintColor(fn (?string $state) => self::status($state)['color'])
                    ->hintIcon(fn (?string $state) => self::status($state)['icon'])
                    ->helperText('Pulsa «Buscar impresoras USB» con la impresora enchufada y encendida. Enlace fijo /dev/usb/impresora-…: no cambia al reiniciar.'),
                Select::make('columns')->label('Ancho del papel')->required()->default(48)
                    ->options([48 => '80 mm (48 caracteres)', 32 => '58 mm (32 caracteres)']),
                TextInput::make('codepage')->label('Página de códigos')->numeric()->integer()->default(16)
                    ->helperText('16 = WPC1252 (acentos, ñ y €) en la mayoría de impresoras. Vacío = automático.'),
                TextInput::make('profile')->label('Perfil')->default('default')->required()
                    ->helperText('Perfil de escpos-php: default, simple, TM-T88V…'),
                Toggle::make('is_active')->label('Activa')->default(true)->inline(false),
            ]),
        ]);
    }

    /**
     * «Buscar impresoras USB»: ninguna → aviso; una → rellena el dispositivo;
     * varias → se elige en una lista. Las ya dadas de alta van al final.
     */
    public static function scanAction(): Action
    {
        return Action::make('scanUsb')->label('Buscar impresoras USB')->icon(Heroicon::OutlinedMagnifyingGlass)
            ->modalHeading('Impresoras USB conectadas')
            ->modalSubmitActionLabel('Usar esta')
            ->mountUsing(function (Action $action, Set $set, ?Schema $schema) {
                $found = self::found();
                if ($found === []) {
                    Notification::make()->warning()->title('No se detecta ninguna impresora USB')
                        ->body('Conéctala por USB, enciéndela y vuelve a buscar.')->send();
                    $action->cancel();
                }
                if (count($found) === 1) {
                    $set('device_path', $found[0]['path']);
                    Notification::make()->success()->title("Encontrada: {$found[0]['label']}")->body($found[0]['path'])->send();
                    $action->cancel();
                }
                $schema?->fill(['path' => $found[0]['path']]);
            })
            ->schema([
                Radio::make('path')->label('Elige la impresora')->required()
                    ->options(fn () => collect(self::found())->mapWithKeys(fn ($p) => [$p['path'] => "{$p['label']} · {$p['path']}".($p['configured'] ? ' (ya dada de alta)' : '')])->all()),
            ])
            ->action(fn (array $data, Set $set) => $set('device_path', $data['path']));
    }

    /** @return list<array{path: string, label: string, configured: bool}> sin dar de alta primero */
    private static function found(): array
    {
        return collect((new UsbPrinterDetector)->detect())->sortBy(fn ($p) => $p['configured'] ? 1 : 0)->values()->all();
    }

    /** @return array{text: ?string, color: string, icon: ?Heroicon} */
    public static function status(?string $path): array
    {
        if (blank($path)) {
            return ['text' => null, 'color' => 'gray', 'icon' => null];
        }
        $match = collect((new UsbPrinterDetector)->detect())->firstWhere('path', $path);

        return $match
            ? ['text' => "Conectada: {$match['label']}", 'color' => 'success', 'icon' => Heroicon::CheckCircle]
            : ['text' => 'No se detecta ahora: conéctala y enciéndela', 'color' => 'danger', 'icon' => Heroicon::ExclamationCircle];
    }

    private static function usb(Get $get): bool
    {
        $value = $get('connection_type');

        return ($value instanceof PrinterConnection ? $value : PrinterConnection::tryFrom((string) $value)) === PrinterConnection::Usb;
    }
}
