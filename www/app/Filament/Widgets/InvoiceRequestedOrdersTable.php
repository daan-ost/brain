<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Services\PaymentFulfillmentService;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class InvoiceRequestedOrdersTable extends BaseWidget
{
    protected static ?string $heading = 'Openstaande facturen';

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->where('status', 'invoice_requested')
                    ->with(['license', 'payer'])
                    ->latest()
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('payer_display')
                    ->label('Organisatie / gebruiker')
                    ->state(function (Order $record): string {
                        if ($record->payer_type === 'user') {
                            return $record->payer?->name ?? 'Onbekende gebruiker';
                        }

                        return $record->payer?->name ?? 'Onbekende organisatie';
                    })
                    ->limit(25),

                Tables\Columns\TextColumn::make('license.name')
                    ->label('Licentie')
                    ->badge(),

                Tables\Columns\TextColumn::make('gross_amount')
                    ->label('Bedrag')
                    ->formatStateUsing(
                        fn (Order $record): string => strtoupper($record->currency).' '.number_format($record->gross_amount, 2)
                    ),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Aangevraagd')
                    ->since(),
            ])
            ->paginated(false)
            ->actions([
                Tables\Actions\Action::make('mark_paid')
                    ->label('Betaald')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Factuur markeren als betaald')
                    ->modalDescription(fn (Order $record): string => sprintf(
                        'Markeer de factuur van %s (%s %s) als betaald. De licentie wordt direct geactiveerd en credits worden bijgeschreven.',
                        $record->payer?->name ?? 'onbekend',
                        strtoupper($record->currency),
                        number_format($record->gross_amount, 2)
                    ))
                    ->modalSubmitActionLabel('Ja, markeer als betaald')
                    ->action(function (Order $record): void {
                        $ok = app(PaymentFulfillmentService::class)->fulfillInvoicePayment($record);

                        if ($ok) {
                            Notification::make()
                                ->title('Betaling verwerkt')
                                ->body('Licentie geactiveerd en credits bijgeschreven.')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Verwerking mislukt')
                                ->body('Controleer de logs voor meer informatie.')
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('view')
                    ->label('Bekijk')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->url(fn (Order $record): string => route('filament.admin.resources.orders.view', ['record' => $record->id])),
            ]);
    }
}
