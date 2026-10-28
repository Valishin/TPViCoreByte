<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use App\Services\Customers\CustomerPrivacyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Clientes y reparto';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'cliente';

    protected static ?string $pluralModelLabel = 'clientes';

    protected static ?string $slug = 'clientes';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [AddressesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }

    /** Descarga todos los datos del cliente en JSON (derecho de acceso). */
    public static function exportAction(): Action
    {
        return Action::make('exportData')
            ->label('Exportar datos')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->authorize(fn (Customer $record) => auth()->user()->can('view', $record))
            ->action(function (Customer $record) {
                $json = json_encode(app(CustomerPrivacyService::class)->export($record), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return response()->streamDownload(fn () => print ($json), "cliente-{$record->id}-datos.json", ['Content-Type' => 'application/json']);
            });
    }

    /** Anonimiza al cliente (irreversible). Los tickets emitidos conservan su copia. */
    public static function anonymizeAction(): Action
    {
        return Action::make('anonymize')
            ->label('Anonimizar')
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->visible(fn (Customer $record) => ! $record->isAnonymized())
            ->authorize(fn (Customer $record) => auth()->user()->can('update', $record))
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalHeading(fn (Customer $record) => "Anonimizar a {$record->name}")
            ->modalDescription('Esta acción es IRREVERSIBLE. Se borrarán su nombre, teléfono, notas y direcciones, y los datos de contacto de sus pedidos. Los tickets ya emitidos conservan sus datos porque son documentos fiscales obligatorios. Si el cliente vuelve, habrá que darlo de alta de nuevo.')
            ->schema([
                TextInput::make('confirmation')
                    ->label('Escribe ANONIMIZAR para confirmar')
                    ->required()
                    ->in(['ANONIMIZAR'])
                    ->validationMessages(['in' => 'Escribe ANONIMIZAR en mayúsculas.']),
            ])
            ->modalSubmitActionLabel('Anonimizar definitivamente')
            ->action(function (Customer $record) {
                app(CustomerPrivacyService::class)->anonymize($record);
                Notification::make()->success()->title('Cliente anonimizado')->send();
            });
    }
}
