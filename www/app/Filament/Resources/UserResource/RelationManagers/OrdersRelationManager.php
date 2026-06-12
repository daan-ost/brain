<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Orders';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_number')
            ->modifyQueryUsing(fn ($query) => $query->with('license'))
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('Order #')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'subscription' => 'success',
                        'onetime' => 'info',
                        default => 'gray',
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('license.name')
                    ->label('License')
                    ->placeholder('—')
                    ->tooltip(fn ($record) => $record->license?->tier
                        ? "Tier: {$record->license->tier}"
                        : null
                    ),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->money(fn ($record) => $record->currency ?? 'EUR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->label())
                    ->color(fn ($state) => $state->badgeColor()),

                Tables\Columns\TextColumn::make('payment_provider')
                    ->label('Provider')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'mollie', 'stripe' => 'purple',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state) => $state ? ucfirst($state) : '—')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Method')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('provider_payment_id')
                    ->label('Provider ID')
                    ->copyable()
                    ->limit(14)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('paid_at')
                    ->label('Paid')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'paid' => 'Paid',
                        'pending' => 'Pending',
                        'failed' => 'Failed',
                        'canceled' => 'Canceled',
                        'expired' => 'Expired',
                    ]),

                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'onetime' => 'One time',
                        'subscription' => 'Subscription',
                    ]),

                Tables\Filters\SelectFilter::make('payment_provider')
                    ->label('Provider')
                    ->options([
                        'mollie' => 'Mollie',
                        'stripe' => 'Stripe',
                    ])
                    ->query(function ($query, array $data) {
                        if (! ($data['value'] ?? null)) {
                            return $query;
                        }

                        if ($data['value'] === 'mollie') {
                            return $query->where(function ($q) {
                                $q->where('payment_provider', 'mollie')
                                    ->orWhere(function ($q2) {
                                        $q2->whereNull('payment_provider')
                                            ->whereNotNull('mollie_payment_id');
                                    });
                            });
                        }

                        return $query->where('payment_provider', $data['value']);
                    }),

                Tables\Filters\Filter::make('date_range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Paid from'),
                        Forms\Components\DatePicker::make('to')->label('Paid to'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('paid_at', '>=', $date))
                            ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('paid_at', '<=', $date));
                    }),
            ])
            ->headerActions([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('viewInProviderDashboard')
                    ->label(fn ($record) => 'View in '.ucfirst($record->payment_provider ?? 'provider'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn ($record) => $record->provider_dashboard_url)
                    ->openUrlInNewTab()
                    ->visible(fn ($record) => $record->provider_dashboard_url !== null),

                Tables\Actions\Action::make('downloadInvoice')
                    ->label('Invoice')
                    ->icon('heroicon-o-document-arrow-down')
                    ->visible(fn ($record) => $record->invoice_file_path && Storage::exists($record->invoice_file_path))
                    ->action(function ($record) {
                        return response()->download(
                            Storage::path($record->invoice_file_path),
                            $record->invoice_number.'.pdf'
                        );
                    }),
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('paid_at', 'desc');
    }
}
