<?php

namespace App\Filament\Resources\Tickets;

use App\Models\Ticket;
use App\Services\Billing\InvoiceMailer;
use App\Services\Billing\InvoicePdf;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/** Acciones de las facturas completas (PDF A4 y email), en el listado y en la ficha. */
class InvoiceActions
{
    public static function pdf(): Action
    {
        return Action::make('pdf')
            ->label('PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->visible(fn (Ticket $record) => $record->type->isFullInvoice())
            ->action(fn (Ticket $record) => response()->streamDownload(
                fn () => print (app(InvoicePdf::class)->render($record)),
                InvoicePdf::filename($record),
                ['Content-Type' => 'application/pdf'],
            ));
    }

    public static function email(): Action
    {
        return Action::make('email')
            ->label(fn (Ticket $record) => $record->emails()->exists() ? 'Reenviar email' : 'Enviar por email')
            ->icon('heroicon-o-envelope')->color('gray')
            ->visible(fn (Ticket $record) => $record->type->isFullInvoice())
            ->modalHeading('Enviar la factura por email')
            ->modalDescription('Solo si el cliente ha aceptado recibir las facturas por email (art. 9.2 RD 1619/2012). Se envía en segundo plano y se reintenta si falla.')
            ->schema(fn (Ticket $record) => [
                TextInput::make('to')->label('Email')->email()->required()
                    ->default($record->customer_id ? $record->customerModel?->billing_email : null),
            ])
            ->action(function (Ticket $record, array $data) {
                try {
                    $email = app(InvoiceMailer::class)->queue($record, auth()->user(), $data['to']);
                } catch (\LogicException|\RuntimeException $e) {
                    Notification::make()->danger()->title('No se ha podido enviar')->body($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title("Factura en cola para {$email->to}")->send();
            });
    }
}
