<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\AuthorizationAction;
use App\Enums\PaymentMethod;
use App\Enums\RectificationReason;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Exceptions\ExchangeOtherYearException;
use App\Filament\Resources\Tickets\InvoiceActions;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Customer;
use App\Services\Billing\InvoiceExchanger;
use App\Services\Pos\AuthorizationService;
use App\Services\Tickets\AnnulmentService;
use App\Services\Tickets\RectificationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public function getTitle(): string
    {
        return ($this->record->type->isFullInvoice() ? 'Factura ' : 'Ticket ').$this->record->fullNumber();
    }

    /**
     * El ticket no se edita ni se borra: se rectifica. Por diferencias (anular
     * todo o parte, como en la caja) o por sustitución (solo aquí).
     */
    protected function getHeaderActions(): array
    {
        $ticket = $this->record;
        // Se rectifican tickets (también canjeados, solo por diferencias: FAQ 27) y facturas directas.
        // Las facturas de canje (F3) no se rectifican: se corrige el destinatario (F3 negativa + nueva).
        $canView = fn () => auth()->user()?->can('view', $ticket);
        $canRectify = fn () => in_array($ticket->type, [TicketType::Simplified, TicketType::Invoice], true)
            && $ticket->status !== TicketStatus::Annulled && $canView();
        $canSubstitute = fn () => $canRectify() && $ticket->status !== TicketStatus::Exchanged;
        $canCorrectRecipient = fn () => in_array($ticket->type, [TicketType::Invoice, TicketType::InvoiceExchange], true)
            && $ticket->status === TicketStatus::Issued && $ticket->total >= 0 && $canView();
        $isInvoice = $ticket->type->isFullInvoice();
        // Facturas completas: motivo de la rectificación (tipo R1-R4 de VERI*FACTU, art. 80 LIVA).
        $reasonCode = fn () => Select::make('reason_code')->label('Tipo de rectificación')
            ->options(collect(RectificationReason::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()]))
            ->default(RectificationReason::ErrorOrDiscount->value)->required()->visible($isInvoice);
        $billingCustomers = fn () => Customer::query()->whereNotNull('tax_id')->whereNull('anonymized_at')->orderBy('legal_name')->limit(500)
            ->get()->mapWithKeys(fn (Customer $c) => [$c->id => "{$c->legal_name} ({$c->tax_id})"]);
        $lineFields = fn (bool $all) => $ticket->lines()->whereNull('parent_line_id')->get()
            ->map(fn ($line) => TextInput::make("lines.{$line->id}")
                ->label("{$line->name} (de {$line->quantity})")
                ->numeric()->integer()->minValue(0)->maxValue($line->quantity)
                ->default($all ? $line->quantity : 0))
            ->all();

        return [
            Action::make('voidTicket')
                ->label($isInvoice ? 'Anular factura' : 'Anular ticket')->icon('heroicon-o-receipt-refund')->color('danger')
                ->visible($canRectify)
                ->modalHeading($isInvoice ? 'Anular factura (rectificativa por diferencias)' : 'Anular ticket (rectificativo por diferencias)')
                ->modalDescription($isInvoice
                    ? 'Se emite una factura rectificativa (serie FR) con los importes en negativo de lo que indiques. La original no se modifica. La devolución se registra en la caja abierta.'
                    : 'Se emite un ticket rectificativo con los importes en negativo de lo que indiques. El original no se modifica. La devolución se registra en la caja abierta.')
                ->schema(fn () => [
                    TextInput::make('reason')->label('Motivo')->required()->maxLength(200),
                    $reasonCode(),
                    Select::make('refund_method')->label('Devolver por')->options(PaymentMethod::class)->default(PaymentMethod::Cash->value)->required(),
                    Section::make('Unidades a anular')->schema($lineFields(true)),
                ])
                ->action(function (array $data) use ($ticket) {
                    $this->rectify(fn (RectificationService $s) => $s->byDifferences($ticket, auth()->user(), $data['reason'], array_map('intval', $data['lines'] ?? []), self::method($data['refund_method']),
                        reasonCode: self::reason($data['reason_code'] ?? null)));
                }),
            Action::make('substitute')
                ->label('Rectificar por sustitución')->icon('heroicon-o-arrows-right-left')->color('gray')
                ->visible($canSubstitute)
                ->modalHeading('Rectificativo por sustitución')
                ->modalDescription('El rectificativo contendrá el ticket ya corregido (sin lo que quites) y sustituye al original. La diferencia se devuelve.')
                ->schema(fn () => [
                    TextInput::make('reason')->label('Motivo')->required()->maxLength(200),
                    $reasonCode(),
                    Select::make('refund_method')->label('Devolver por')->options(PaymentMethod::class)->default(PaymentMethod::Cash->value)->required(),
                    Section::make('Unidades que se quitan')->schema($lineFields(false)),
                ])
                ->action(function (array $data) use ($ticket) {
                    $this->rectify(fn (RectificationService $s) => $s->bySubstitution($ticket, auth()->user(), $data['reason'], array_map('intval', $data['lines'] ?? []), self::method($data['refund_method']),
                        reasonCode: self::reason($data['reason_code'] ?? null)));
                }),
            // Datos del destinatario mal (art. 15.1): rectificativa por sustitución, mismos importes.
            Action::make('correctRecipient')
                ->label('Corregir destinatario')->icon('heroicon-o-identification')->color('gray')
                ->visible($canCorrectRecipient)
                ->modalHeading('Corregir los datos del destinatario')
                ->modalDescription($ticket->type === TicketType::InvoiceExchange
                    ? 'Factura de canje: se emite una factura de canje en negativo y otra nueva con los datos del cliente elegido (FAQ 27 de la AEAT). Si el NIF era correcto y solo cambia el domicilio, corrige primero la ficha del cliente.'
                    : 'Se emite una factura rectificativa (serie FR) por sustitución con los datos del cliente elegido y los mismos importes. Si el NIF era correcto y solo cambia el domicilio, corrige primero la ficha del cliente.')
                ->schema(fn () => [
                    Select::make('customer_id')->label('Destinatario correcto')->options($billingCustomers)->searchable()->required(),
                    TextInput::make('reason')->label('Motivo')->required()->maxLength(200)->default('Corrección de los datos del destinatario'),
                ])
                ->action(function (array $data) use ($ticket) {
                    $this->rectify(fn (RectificationService $s) => $s->correctRecipient($ticket, Customer::query()->findOrFail($data['customer_id']), auth()->user(), $data['reason']));
                }),
            // Canje del ticket por factura completa (art. 15.6): no es una rectificación.
            Action::make('exchange')
                ->label('Hacer factura de este ticket')->icon('heroicon-o-document-text')->color('primary')
                ->visible(fn () => $ticket->type === TicketType::Simplified && $ticket->status === TicketStatus::Issued && auth()->user()?->can('view', $ticket))
                ->modalHeading('Factura completa de este ticket')
                ->modalDescription('Se emite una factura (serie F) que sustituye a este ticket, con los mismos importes y la fecha de operación del ticket. El ticket queda canjeado: no se puede canjear otra vez ni rectificar.')
                ->schema(fn () => [
                    Select::make('customer_id')->label('Cliente (con datos fiscales)')->options($billingCustomers)->searchable()->required()
                        ->helperText('Si no está, dalo de alta en Clientes con sus datos fiscales.'),
                    Checkbox::make('confirm_other_year')->label("El ticket es de {$ticket->issued_at->year}: emitir igualmente")
                        ->visible($ticket->issued_at->year !== now()->year)->accepted(),
                ])
                ->action(function (array $data) use ($ticket) {
                    try {
                        $invoice = app(InvoiceExchanger::class)->exchange($ticket, Customer::query()->findOrFail($data['customer_id']), auth()->user(), (bool) ($data['confirm_other_year'] ?? false));
                    } catch (ExchangeOtherYearException|\LogicException|\RuntimeException $e) {
                        Notification::make()->danger()->title('No se ha podido hacer la factura')->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title("Emitida la factura {$invoice->fullNumber()}")->send();
                    $this->redirect(TicketResource::getUrl('view', ['record' => $invoice]));
                }),
            // Emitido por error, sin venta (RRSIF 11.1): registro de anulación de VERI*FACTU.
            Action::make('annul')
                ->label('Anular por error')->icon('heroicon-o-no-symbol')->color('danger')
                ->visible(fn () => in_array($ticket->type, [TicketType::Simplified, TicketType::Invoice], true) && $ticket->status === TicketStatus::Issued && $canView())
                ->modalHeading('Anular por error (no hubo venta)')
                ->modalDescription('Solo para documentos emitidos por error sin venta real: duplicados, pruebas, ventas que no se produjeron. Se envía a Hacienda un registro de anulación, deja de contar en ventas e IVA y, si se cobró, se devuelve en la caja abierta. Si la venta existió, rectifica.')
                ->schema(fn () => [
                    Select::make('reason')->label('Motivo')->required()
                        ->options(array_combine($r = ['Ticket duplicado', 'Emitido por error (no hubo venta)', 'Prueba o formación'], $r)),
                ])
                ->action(function (array $data) use ($ticket) {
                    try {
                        app(AnnulmentService::class)->annul($ticket, auth()->user(), $data['reason']);
                    } catch (\LogicException|\RuntimeException $e) {
                        Notification::make()->danger()->title('No se ha podido anular')->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title("{$ticket->fullNumber()} anulado")->send();
                    $this->redirect(TicketResource::getUrl('view', ['record' => $ticket]));
                }),
            InvoiceActions::pdf()->record($ticket),
            InvoiceActions::email()->record($ticket),
        ];
    }

    private static function method(PaymentMethod|string $value): PaymentMethod
    {
        return $value instanceof PaymentMethod ? $value : PaymentMethod::from($value);
    }

    private static function reason(RectificationReason|string|null $value): ?RectificationReason
    {
        return $value instanceof RectificationReason ? $value : RectificationReason::tryFrom((string) $value);
    }

    private function rectify(\Closure $callback): void
    {
        try {
            // El usuario del backoffice es admin/encargado: se autoriza él mismo, y queda registrado.
            app(AuthorizationService::class)->require(AuthorizationAction::VoidTicket, auth()->user(), null, $this->record);
            $rectifying = $callback(app(RectificationService::class));
        } catch (\LogicException|\RuntimeException $e) {
            Notification::make()->danger()->title('No se ha podido rectificar')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title("Emitido el rectificativo {$rectifying->fullNumber()}")->send();
        $this->redirect(TicketResource::getUrl('view', ['record' => $rectifying]));
    }
}
