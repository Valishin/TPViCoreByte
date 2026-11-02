<?php

namespace App\Filament\Resources\Printers\Schemas;

use App\Enums\PrinterConnection;
use App\Enums\PrinterType;
use App\Services\Printing\UsbPrinterDetector;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

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
                Select::make('detected_usb')->label('Impresoras USB conectadas')->dehydrated(false)->live()
                    ->placeholder(fn () => (new UsbPrinterDetector)->detect() ? 'Elige una…' : 'No se detecta ninguna: conéctala y enciéndela')
                    ->options(fn () => collect((new UsbPrinterDetector)->detect())->mapWithKeys(fn ($p) => [$p['path'] => "{$p['label']} ({$p['path']})"])->all())
                    ->afterStateUpdated(fn (?string $state, Set $set) => $state ? $set('device_path', $state) : null)
                    ->visible(fn (Get $get) => self::usb($get))->columnSpanFull(),
                TextInput::make('device_path')->label('Dispositivo USB')->placeholder('/dev/usb/impresora-04b8-0202')
                    ->datalist(fn () => collect((new UsbPrinterDetector)->detect())->pluck('path')->all())
                    ->startsWith(['/'])
                    ->validationMessages(['starts_with' => 'Debe ser una ruta absoluta, p. ej. /dev/usb/lp0.'])
                    ->visible(fn (Get $get) => self::usb($get))->required(fn (Get $get) => self::usb($get))
                    ->columnSpanFull()
                    ->helperText('Enlace fijo /dev/usb/impresora-… (lo crea el instalador para cualquier impresora USB): /dev/usb/lp0 puede cambiar al reiniciar.'),
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

    private static function usb(Get $get): bool
    {
        $value = $get('connection_type');

        return ($value instanceof PrinterConnection ? $value : PrinterConnection::tryFrom((string) $value)) === PrinterConnection::Usb;
    }
}
