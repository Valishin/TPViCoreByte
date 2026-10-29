<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\AuthorizationAction;
use App\Enums\PaymentMethod;
use App\Enums\TicketType;
use App\Filament\Resources\Tickets\TicketResource;
use App\Services\Pos\AuthorizationService;
use App\Services\Tickets\RectificationService;
use Filament\Actions\Action;
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
        return "Ticket {$this->record->fullNumber()}";
    }

    /**
     * El ticket no se edita ni se borra: se rectifica. Por diferencias (anular
     * todo o parte, como en la caja) o por sustitución (solo aquí).
     */
    protected function getHeaderActions(): array
    {
        $ticket = $this->record;
        $canRectify = fn () => $ticket->type === TicketType::Simplified && auth()->user()?->can('view', $ticket);
        $lineFields = fn (bool $all) => $ticket->lines()->whereNull('parent_line_id')->get()
            ->map(fn ($line) => TextInput::make("lines.{$line->id}")
                ->label("{$line->name} (de {$line->quantity})")
                ->numeric()->integer()->minValue(0)->maxValue($line->quantity)
                ->default($all ? $line->quantity : 0))
            ->all();

        return [
            Action::make('voidTicket')
                ->label('Anular ticket')->icon('heroicon-o-receipt-refund')->color('danger')
                ->visible($canRectify)
                ->modalHeading('Anular ticket (rectificativo por diferencias)')
                ->modalDescription('Se emite un ticket rectificativo con los importes en negativo de lo que indiques. El original no se modifica. La devolución se registra en la caja abierta.')
                ->schema(fn () => [
                    TextInput::make('reason')->label('Motivo')->required()->maxLength(200),
                    Select::make('refund_method')->label('Devolver por')->options(PaymentMethod::class)->default(PaymentMethod::Cash->value)->required(),
                    Section::make('Unidades a anular')->schema($lineFields(true)),
                ])
                ->action(function (array $data) use ($ticket) {
                    $this->rectify(fn (RectificationService $s) => $s->byDifferences($ticket, auth()->user(), $data['reason'], array_map('intval', $data['lines'] ?? []), self::method($data['refund_method'])));
                }),
            Action::make('substitute')
                ->label('Rectificar por sustitución')->icon('heroicon-o-arrows-right-left')->color('gray')
                ->visible($canRectify)
                ->modalHeading('Rectificativo por sustitución')
                ->modalDescription('El rectificativo contendrá el ticket ya corregido (sin lo que quites) y sustituye al original. La diferencia se devuelve.')
                ->schema(fn () => [
                    TextInput::make('reason')->label('Motivo')->required()->maxLength(200),
                    Select::make('refund_method')->label('Devolver por')->options(PaymentMethod::class)->default(PaymentMethod::Cash->value)->required(),
                    Section::make('Unidades que se quitan')->schema($lineFields(false)),
                ])
                ->action(function (array $data) use ($ticket) {
                    $this->rectify(fn (RectificationService $s) => $s->bySubstitution($ticket, auth()->user(), $data['reason'], array_map('intval', $data['lines'] ?? []), self::method($data['refund_method'])));
                }),
        ];
    }

    private static function method(PaymentMethod|string $value): PaymentMethod
    {
        return $value instanceof PaymentMethod ? $value : PaymentMethod::from($value);
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
