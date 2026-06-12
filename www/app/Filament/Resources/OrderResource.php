<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Jobs\SendInvoiceEmail;
use App\Models\Order;
use App\Services\InvoiceGenerationService;
use App\Services\MolliePaymentService;
use App\Services\PaymentFulfillmentService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Orders & Payments';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Order Information')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('payer_type')
                                    ->label('Payer Type')
                                    ->options([
                                        'user' => 'User',
                                        'organization' => 'Organization',
                                    ])
                                    ->required()
                                    ->reactive(),

                                Forms\Components\TextInput::make('payer_id')
                                    ->label('Payer ID')
                                    ->required()
                                    ->numeric(),

                                Forms\Components\Select::make('license_id')
                                    ->label('License')
                                    ->relationship('license', 'name')
                                    ->searchable()
                                    ->preload(),
                            ]),

                        Forms\Components\Select::make('type')
                            ->options([
                                'subscription' => 'Subscription',
                                'one_time' => 'One-time',
                                'upgrade' => 'Upgrade',
                            ])
                            ->required(),
                    ]),

                Forms\Components\Section::make('Amounts')
                    ->schema([
                        Forms\Components\Grid::make(4)
                            ->schema([
                                Forms\Components\TextInput::make('net_amount')
                                    ->label('Net Amount')
                                    ->numeric()
                                    ->step(0.01)
                                    ->required(),

                                Forms\Components\TextInput::make('tax_amount')
                                    ->label('Tax Amount')
                                    ->numeric()
                                    ->step(0.01)
                                    ->required(),

                                Forms\Components\TextInput::make('gross_amount')
                                    ->label('Gross Amount')
                                    ->numeric()
                                    ->step(0.01)
                                    ->required(),

                                Forms\Components\Select::make('currency')
                                    ->options([
                                        'EUR' => 'EUR',
                                        'USD' => 'USD',
                                    ])
                                    ->required(),
                            ]),
                    ]),

                Forms\Components\Section::make('Status & Payment')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->options([
                                        'initiated' => 'Initiated',
                                        'pending' => 'Pending',
                                        'paid' => 'Paid',
                                        'failed' => 'Failed',
                                        'canceled' => 'Canceled',
                                        'expired' => 'Expired',
                                        'refunded' => 'Refunded',
                                        'invoice_requested' => 'Invoice Requested',
                                    ])
                                    ->required(),

                                Forms\Components\TextInput::make('payment_method')
                                    ->label('Payment Method'),

                                Forms\Components\DateTimePicker::make('paid_at')
                                    ->label('Paid At'),
                            ]),
                    ]),

                Forms\Components\Section::make('Invoice')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('invoice_number')
                                    ->label('Invoice Number'),

                                Forms\Components\DateTimePicker::make('invoice_date')
                                    ->label('Invoice Date'),

                                Forms\Components\TextInput::make('invoice_file_path')
                                    ->label('Invoice File Path'),
                            ]),
                    ])
                    ->collapsed(),

                Forms\Components\Section::make('Mollie')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('mollie_payment_id'),
                                Forms\Components\TextInput::make('mollie_customer_id'),
                                Forms\Components\TextInput::make('mollie_subscription_id'),
                            ]),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Order ID')
                    ->searchable()
                    ->sortable()
                    ->limit(8)
                    ->tooltip(fn ($record) => $record->id),

                Tables\Columns\TextColumn::make('payer')
                    ->label('Payer')
                    ->state(function (Order $record): string {
                        if ($record->payer_type === 'user') {
                            $user = $record->userPayer;

                            return $user ? $user->name.' (User)' : 'Unknown User';
                        }
                        $org = $record->organizationPayer;

                        return $org ? $org->name.' (Org)' : 'Unknown Org';
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function ($q) use ($search) {
                            $q->whereHas('userPayer', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                                ->orWhereHas('organizationPayer', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                        });
                    }),

                Tables\Columns\TextColumn::make('license.name')
                    ->label('License')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('gross_amount')
                    ->label('Amount')
                    ->money(fn ($record) => $record->currency ?? 'EUR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state): string => $state instanceof OrderStatus ? $state->badgeColor() : 'gray')
                    ->formatStateUsing(fn ($state) => $state instanceof OrderStatus ? $state->label() : ucfirst(str_replace('_', ' ', $state ?? ''))),

                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Invoice')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Method')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('paid_at')
                    ->label('Paid')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'initiated' => 'Initiated',
                        'pending' => 'Pending',
                        'paid' => 'Paid',
                        'failed' => 'Failed',
                        'canceled' => 'Canceled',
                        'expired' => 'Expired',
                        'refunded' => 'Refunded',
                        'invoice_requested' => 'Invoice Requested',
                    ]),

                Tables\Filters\SelectFilter::make('payer_type')
                    ->label('Payer Type')
                    ->options([
                        'user' => 'User',
                        'organization' => 'Organization',
                    ]),

                Tables\Filters\SelectFilter::make('currency')
                    ->options([
                        'EUR' => 'EUR',
                        'USD' => 'USD',
                    ]),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'], fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('markPaid')
                    ->label('Betaald')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Order $record): bool => $record->isInvoiceRequested())
                    ->requiresConfirmation()
                    ->modalHeading('Factuur markeren als betaald')
                    ->modalDescription(fn (Order $record): string => sprintf(
                        'Markeer de factuur van %s (%s %s) als betaald. Licentie wordt geactiveerd en credits bijgeschreven.',
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

                Tables\Actions\Action::make('downloadInvoice')
                    ->label('Invoice')
                    ->icon('heroicon-o-document-arrow-down')
                    ->visible(fn ($record) => $record->invoice_file_path && Storage::exists($record->invoice_file_path))
                    ->action(function ($record) {
                        return response()->download(
                            Storage::path($record->invoice_file_path),
                            ($record->invoice_number ?? 'invoice').'.pdf'
                        );
                    }),

                Tables\Actions\Action::make('resendInvoice')
                    ->label('Resend')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->visible(fn ($record) => $record->invoice_number && $record->invoice_file_path)
                    ->requiresConfirmation()
                    ->modalHeading('Resend Invoice')
                    ->modalDescription(fn ($record) => "This will resend invoice {$record->invoice_number} to the customer.")
                    ->action(function ($record): void {
                        SendInvoiceEmail::dispatch($record);

                        Notification::make()
                            ->title('Invoice email queued')
                            ->body("Invoice {$record->invoice_number} will be sent shortly")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('generateInvoice')
                    ->label('Generate Invoice')
                    ->icon('heroicon-o-document-plus')
                    ->color('success')
                    ->visible(fn ($record) => $record->status?->value === 'paid' && ! $record->invoice_number)
                    ->requiresConfirmation()
                    ->modalHeading('Generate Invoice')
                    ->modalDescription('This will generate an invoice for this paid order.')
                    ->action(function ($record): void {
                        $service = app(InvoiceGenerationService::class);
                        $result = $service->generateInvoice($record, sendEmail: true);

                        if (isset($result['invoice_number'])) {
                            Notification::make()
                                ->title('Invoice generated')
                                ->body("Invoice {$result['invoice_number']} has been generated and sent")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Failed to generate invoice')
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('refund')
                    ->label('Refund')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn ($record) => ($record->mollie_payment_id || $record->provider_payment_id)
                        && $record->status?->value === 'paid'
                        && $record->gross_amount > 0
                    )
                    ->form([
                        Forms\Components\Placeholder::make('order_info')
                            ->label('Order Details')
                            ->content(fn ($record) => "Order: {$record->id}\nProvider: ".ucfirst($record->payment_provider ?? 'unknown')."\nAmount: {$record->currency} ".number_format($record->gross_amount, 2)),

                        Forms\Components\TextInput::make('amount')
                            ->label('Refund Amount')
                            ->numeric()
                            ->required()
                            ->step(0.01)
                            ->default(fn ($record) => $record->gross_amount)
                            ->maxValue(fn ($record) => $record->gross_amount)
                            ->helperText(fn ($record) => "Maximum: {$record->currency} ".number_format($record->gross_amount, 2)),

                        Forms\Components\Textarea::make('reason')
                            ->label('Reason for Refund')
                            ->required()
                            ->rows(2)
                            ->placeholder('Enter the reason for this refund...'),
                    ])
                    ->action(function ($record, array $data): void {
                        // Provider-aware refund: routeer naar Stripe of Mollie service.
                        // Different signature/return shapes — normaliseer naar
                        // gemeenschappelijke shape voor de Notification + meta update.
                        $provider = $record->payment_provider;
                        $isStripe = $provider === 'stripe';

                        if ($isStripe) {
                            $stripeService = app(\App\Services\Payments\StripeRefundService::class);
                            $result = $stripeService->createRefund($record, (float) $data['amount'], 'requested_by_customer');
                            $refundId = $result['refund_id'] ?? null;
                        } else {
                            $mollieService = app(MolliePaymentService::class);
                            $result = $mollieService->createRefundForAdmin($record->mollie_payment_id, [
                                'amount' => $data['amount'],
                                'currency' => $record->currency,
                                'description' => $data['reason'],
                            ]);
                            $refundId = $result['data']['id'] ?? null;
                        }

                        if ($result['success']) {
                            $isFullRefund = $data['amount'] >= $record->gross_amount;

                            // Stripe service zet status zelf al via order->update; Mollie niet,
                            // dus update altijd hier inclusief meta. Stripe-update is idempotent.
                            $record->update([
                                'status' => $isFullRefund ? 'refunded' : 'paid',
                                'meta' => array_merge($record->meta ?? [], [
                                    'refund_id' => $refundId,
                                    'refund_amount' => $data['amount'],
                                    'refund_reason' => $data['reason'],
                                    'refund_provider' => $provider,
                                    'refunded_at' => now()->toISOString(),
                                    'refunded_by' => auth()->user()->name ?? 'Admin',
                                ]),
                            ]);

                            Notification::make()
                                ->title('Refund created')
                                ->body("{$record->currency} ".number_format($data['amount'], 2)." has been refunded via ".ucfirst($provider ?? 'provider'))
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Refund failed')
                                ->body($result['error'] ?? 'Unknown error')
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('viewInProviderDashboard')
                    ->label(fn ($record) => 'View in '.ucfirst($record->payment_provider ?? 'provider'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn ($record) => $record->provider_dashboard_url)
                    ->openUrlInNewTab()
                    ->visible(fn ($record) => $record->provider_dashboard_url !== null),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Order Details')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('id')
                                    ->label('Order ID')
                                    ->copyable(),

                                Infolists\Components\TextEntry::make('status')
                                    ->badge()
                                    ->color(fn ($state): string => $state instanceof OrderStatus ? $state->badgeColor() : 'gray')
                                    ->formatStateUsing(fn ($state) => $state instanceof OrderStatus ? $state->label() : ucfirst(str_replace('_', ' ', $state ?? ''))),

                                Infolists\Components\TextEntry::make('type')
                                    ->formatStateUsing(fn ($state) => ucfirst(str_replace('_', ' ', $state))),
                            ]),

                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('payer_info')
                                    ->label('Payer')
                                    ->state(function (Order $record): string {
                                        if ($record->payer_type === 'user') {
                                            $user = $record->userPayer;

                                            return $user ? "{$user->name} ({$user->email})" : 'Unknown';
                                        }
                                        $org = $record->organizationPayer;

                                        return $org ? $org->name : 'Unknown';
                                    }),

                                Infolists\Components\TextEntry::make('license.name')
                                    ->label('License')
                                    ->placeholder('N/A'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Amounts')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('net_amount')
                                    ->label('Net')
                                    ->money(fn ($record) => $record->currency),

                                Infolists\Components\TextEntry::make('tax_amount')
                                    ->label('Tax')
                                    ->money(fn ($record) => $record->currency),

                                Infolists\Components\TextEntry::make('gross_amount')
                                    ->label('Total')
                                    ->money(fn ($record) => $record->currency)
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('vat_rate')
                                    ->label('VAT Rate')
                                    ->formatStateUsing(fn ($state) => number_format($state, 1).'%'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Payment')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('payment_method')
                                    ->placeholder('—'),

                                Infolists\Components\TextEntry::make('paid_at')
                                    ->dateTime()
                                    ->placeholder('Not paid'),

                                Infolists\Components\TextEntry::make('country')
                                    ->placeholder('—'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Invoice')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('invoice_number')
                                    ->placeholder('Not generated'),

                                Infolists\Components\TextEntry::make('invoice_date')
                                    ->dateTime()
                                    ->placeholder('—'),

                                Infolists\Components\TextEntry::make('vat_id')
                                    ->label('VAT ID')
                                    ->placeholder('—'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Mollie')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('mollie_payment_id')
                                    ->copyable()
                                    ->placeholder('—'),

                                Infolists\Components\TextEntry::make('mollie_customer_id')
                                    ->copyable()
                                    ->placeholder('—'),

                                Infolists\Components\TextEntry::make('mollie_subscription_id')
                                    ->copyable()
                                    ->placeholder('—'),
                            ]),
                    ])
                    ->collapsed(),

                Infolists\Components\Section::make('Timestamps')
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('created_at')
                                    ->dateTime(),

                                Infolists\Components\TextEntry::make('updated_at')
                                    ->dateTime(),
                            ]),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'view' => Pages\ViewOrder::route('/{record}'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
