<?php

namespace App\Filament\Resources\TicketSeries;

use App\Models\TicketSeries;
use App\Services\Tickets\SeriesStartService;
use App\Support\DocumentNumber;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * «Primer número de la serie» (continuar la numeración de un sistema
 * anterior): sección del paso «Fiscal» de la Puesta en marcha y botón en
 * /admin → Series. La lógica está en SeriesStartService.
 */
class SeriesNumbering
{
    public const CONFIRM = 'Este número se usará como primer documento de la serie. Después de emitir el primero no se podrá cambiar.';

    /** Sección «Numeración»: una fila por serie activa con su próximo número y el botón. */
    public static function section(): Section
    {
        $rows = TicketSeries::query()->where('is_active', true)->orderBy('code')->get()
            ->map(fn (TicketSeries $series) => Grid::make(['default' => 1, 'md' => 3])->key("numeracion-{$series->id}")->schema([
                TextEntry::make("numeracion_{$series->id}")->label("{$series->code} · {$series->name}")->columnSpan(['md' => 2])
                    ->state(fn () => self::stateText($series->refresh()))
                    ->helperText(fn () => app(SeriesStartService::class)->status($series)['locked'] ? SeriesStartService::LOCKED.'.' : null),
                Actions::make([self::action("primerNumero{$series->id}", $series)])->alignEnd(),
            ]))->all();

        return Section::make('Numeración')
            ->description('Para continuar la numeración de un sistema anterior: el primer número de cada serie este año. '
                .'Si empiezas de cero, no hace falta tocar nada (empiezan en 1).')
            ->schema($rows);
    }

    /** «Primer número 1543 · próximo: T-26-001543» (o el último emitido si ya está bloqueada). */
    public static function stateText(TicketSeries $series): string
    {
        $status = app(SeriesStartService::class)->status($series);

        return $status['locked']
            ? 'Último emitido: '.DocumentNumber::format($series->code, $status['year'], $status['last_issued'], $series->digits ?? DocumentNumber::DEFAULT_DIGITS)
                ." · próximo: {$status['next_full']}"
            : "Primer número de la serie: {$status['next']} · próximo: {$status['next_full']}";
    }

    /**
     * Botón «Cambiar primer número» con su ventana de confirmación. Con $series
     * fija (asistente); sin ella, la del registro (lista y ficha de series).
     */
    public static function action(string $name = 'primerNumero', ?TicketSeries $series = null): Action
    {
        $resolve = fn ($record = null): ?TicketSeries => $series ?? ($record instanceof TicketSeries ? $record : null);

        return Action::make($name)
            ->label('Cambiar primer número')
            ->icon(Heroicon::OutlinedHashtag)
            ->color('gray')
            ->visible(fn ($record = null) => ($s = $resolve($record)) && $s->is_active && ! app(SeriesStartService::class)->status($s)['locked'])
            ->modalHeading(fn ($record = null) => 'Primer número de la serie '.$resolve($record)?->code)
            ->modalDescription(self::CONFIRM)
            ->modalSubmitActionLabel('Confirmar y guardar')
            ->fillForm(fn ($record = null) => ['first_number' => app(SeriesStartService::class)->status($resolve($record))['next']])
            ->schema(fn ($record = null) => [
                TextInput::make('first_number')->label('Primer número de la serie')
                    ->required()->integer()->minValue(1)
                    ->maxValue(10 ** ($resolve($record)->digits ?? DocumentNumber::DEFAULT_DIGITS) - 1)
                    ->live(onBlur: true)
                    ->helperText(fn ($state) => 'El primer documento será '.DocumentNumber::format(
                        $resolve($record)->code, DocumentNumber::yearOf(now()), max(1, (int) $state), $resolve($record)->digits ?? DocumentNumber::DEFAULT_DIGITS,
                    ).'. Solo este año: el que viene empieza en 1.'),
            ])
            ->action(function (array $data, Action $action, $record = null) use ($resolve) {
                $series = $resolve($record);
                try {
                    $next = app(SeriesStartService::class)->setFirstNumber($series, (int) $data['first_number'], Auth::user());
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('No se ha cambiado')->body(collect($e->errors())->flatten()->first())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title("Serie {$series->code}: el próximo documento será {$next->full}")->send();
            });
    }
}
