<?php

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Models\License;
use App\Services\LicenseRenewalService;
use App\Services\MollieSubscriptionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class OrganizationLicensesRelationManager extends RelationManager
{
    protected static string $relationship = 'organizationLicenses';

    protected static ?string $title = 'Licenses';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('license_id')
                    ->label('License')
                    ->options(License::where('active', true)->pluck('name', 'id'))
                    ->required()
                    ->searchable(),

                Forms\Components\DateTimePicker::make('starts_at')
                    ->label('Starts At'),

                Forms\Components\DateTimePicker::make('ends_at')
                    ->label('Ends At'),

                Forms\Components\Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'canceled' => 'Canceled',
                        'expired' => 'Expired',
                        'pending' => 'Pending',
                    ])
                    ->default('active')
                    ->required(),

                Forms\Components\TextInput::make('source')
                    ->default('manual'),

                Forms\Components\Section::make('Billing')
                    ->schema([
                        Forms\Components\Select::make('billing_method')
                            ->options([
                                'online' => 'Online (Mollie)',
                                'invoice' => 'Invoice',
                            ])
                            ->default('online'),
                        Forms\Components\Select::make('payment_status')
                            ->options([
                                'paid' => 'Paid',
                                'unpaid' => 'Unpaid',
                                'pending' => 'Pending',
                            ])
                            ->visible(fn ($get) => $get('billing_method') === 'invoice'),
                        Forms\Components\TextInput::make('invoice_number')
                            ->visible(fn ($get) => $get('billing_method') === 'invoice'),
                    ])
                    ->columns(3)
                    ->collapsed(),

                Forms\Components\Section::make('Subscription Details')
                    ->schema([
                        Forms\Components\TextInput::make('mollie_subscription_id')
                            ->label('Mollie Subscription ID')
                            ->disabled(),
                        Forms\Components\TextInput::make('mollie_customer_id')
                            ->label('Mollie Customer ID')
                            ->disabled(),
                        Forms\Components\TextInput::make('price_at_purchase')
                            ->label('Price at Purchase')
                            ->disabled(),
                    ])
                    ->columns(3)
                    ->collapsed()
                    ->visible(fn ($record) => $record?->mollie_subscription_id !== null
                        || $record?->provider_subscription_id !== null),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('license.name')
            ->columns([
                Tables\Columns\TextColumn::make('license.name')
                    ->label('License')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('license.tier')
                    ->label('Tier')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'free' => 'gray',
                        'onetime' => 'info',
                        'premium' => 'success',
                        'enterprise' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'canceled' => 'warning',
                        'expired' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('subscription_status')
                    ->label('Subscription')
                    ->badge()
                    ->state(function ($record): string {
                        if ($record->billing_method === 'invoice') {
                            return 'Invoice';
                        }
                        if (! $record->mollie_subscription_id) {
                            return 'N/A';
                        }
                        if ($record->status === 'canceled' || $record->ends_at) {
                            return 'Canceled';
                        }

                        return 'Active';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Canceled' => 'warning',
                        'Invoice' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('next_renewal')
                    ->label('Next Renewal')
                    ->state(function ($record): string {
                        if (! $record->license || $record->ends_at) {
                            return $record->ends_at?->format('Y-m-d') ?? '—';
                        }

                        $billingCycle = $record->license->billing_cycle;
                        if (! in_array($billingCycle, ['monthly', 'yearly', '6month'])) {
                            return '—';
                        }

                        $renewalService = app(LicenseRenewalService::class);
                        $nextRenewal = $renewalService->getNextRenewalDate(
                            $record->last_credit_reset_at ?? $record->starts_at ?? $record->created_at,
                            $billingCycle
                        );

                        return $nextRenewal?->format('Y-m-d') ?? '—';
                    })
                    ->color(function ($record): string {
                        if ($record->ends_at && $record->ends_at->isPast()) {
                            return 'danger';
                        }

                        return 'gray';
                    }),

                Tables\Columns\TextColumn::make('price_at_purchase')
                    ->label('Price')
                    ->money(fn ($record) => $record->currency_at_purchase ?? 'EUR')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('billing_method')
                    ->label('Billing')
                    ->badge()
                    ->color(fn ($state) => $state === 'invoice' ? 'info' : 'gray'),

                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'canceled' => 'Canceled',
                        'expired' => 'Expired',
                        'pending' => 'Pending',
                    ]),

                Tables\Filters\SelectFilter::make('billing_method')
                    ->options([
                        'online' => 'Online',
                        'invoice' => 'Invoice',
                    ]),

                Tables\Filters\TernaryFilter::make('has_subscription')
                    ->label('Has Subscription')
                    ->queries(
                        true: fn ($query) => $query->hasProviderSubscription(),
                        false: fn ($query) => $query
                            ->whereNull('mollie_subscription_id')
                            ->whereNull('provider_subscription_id'),
                    ),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['source'] = 'manual';

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->status !== 'active')
                    ->action(function ($record): void {
                        $record->activate();

                        Notification::make()
                            ->title('License activated')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('cancelSubscription')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => ($record->mollie_subscription_id || $record->provider_subscription_id)
                        && $record->status === 'active'
                        && ! $record->ends_at
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Cancel Subscription')
                    ->modalDescription(fn ($record) => "This will cancel the subscription for {$record->license?->name}. The organization will retain access until the next renewal date.")
                    ->action(function ($record): void {
                        $renewalService = app(LicenseRenewalService::class);
                        $result = $renewalService->cancelRenewal($record, 'organization');

                        if ($result['success']) {
                            Notification::make()
                                ->title('Subscription canceled')
                                ->body("License will expire on {$result['renewal_date']?->format('Y-m-d')}")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Failed to cancel subscription')
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

                Tables\Actions\Action::make('fetchMollieDetails')
                    ->label('Mollie Details')
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->color('info')
                    ->visible(fn ($record) => $record->mollie_customer_id !== null)
                    ->modalHeading('Mollie Subscription Details')
                    ->modalDescription(fn ($record) => "Customer ID: {$record->mollie_customer_id}")
                    ->modalContent(function ($record) {
                        $customerId = $record->mollie_customer_id;
                        $subscriptionId = $record->mollie_subscription_id;

                        if (! $customerId) {
                            return view('filament.components.mollie-details-modal', [
                                'error' => 'No Mollie customer ID found',
                                'customer' => null,
                                'subscription' => null,
                                'payments' => [],
                            ]);
                        }

                        try {
                            $service = app(MollieSubscriptionService::class);

                            // Fetch customer details
                            $customerResult = $service->getCustomer($customerId);
                            $customer = $customerResult['success'] ? $customerResult['data'] : null;

                            // Fetch subscription details if available
                            $subscription = null;
                            $payments = [];

                            if ($subscriptionId && $customer) {
                                $subscriptionResult = $service->getSubscription($customerId, $subscriptionId);
                                $subscription = $subscriptionResult['success'] ? $subscriptionResult['data'] : null;

                                // Fetch recent payments for this subscription
                                $paymentsResult = $service->getSubscriptionPayments($customerId, $subscriptionId, 10);
                                if ($paymentsResult['success'] && isset($paymentsResult['data']['_embedded']['payments'])) {
                                    $payments = $paymentsResult['data']['_embedded']['payments'];
                                }
                            }

                            return view('filament.components.mollie-details-modal', [
                                'error' => null,
                                'customer' => $customer,
                                'subscription' => $subscription,
                                'payments' => $payments,
                            ]);
                        } catch (\Exception $e) {
                            return view('filament.components.mollie-details-modal', [
                                'error' => 'Could not fetch Mollie data: '.$e->getMessage(),
                                'customer' => null,
                                'subscription' => null,
                                'payments' => [],
                            ]);
                        }
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
