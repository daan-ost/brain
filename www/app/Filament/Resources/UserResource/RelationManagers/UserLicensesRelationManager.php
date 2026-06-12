<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\License;
use App\Models\UserLicense;
use App\Services\LicenseRenewalService;
use App\Services\MollieSubscriptionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class UserLicensesRelationManager extends RelationManager
{
    protected static string $relationship = 'userLicenses';

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
                        'trial' => 'Trial',
                    ])
                    ->default('active')
                    ->required(),

                Forms\Components\TextInput::make('source')
                    ->default('manual'),

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
            ->modifyQueryUsing(fn ($query) => $query->with('license'))
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
                        'trial' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_premature_expiry')
                    ->label('')
                    ->icon(fn ($state) => $state ? 'heroicon-o-exclamation-triangle' : null)
                    ->color('danger')
                    ->tooltip(function ($record): ?string {
                        if (! $record->is_premature_expiry) {
                            return null;
                        }
                        $days = (int) round($record->starts_at->diffInDays($record->ends_at, true));
                        $cycle = $record->license?->billing_cycle ?? 'unknown';
                        $expected = UserLicense::PREMATURE_EXPIRY_THRESHOLDS[$cycle] ?? '?';

                        return "{$cycle} subscription closed after only {$days} days — expected ≥{$expected}";
                    }),

                Tables\Columns\TextColumn::make('license.billing_cycle')
                    ->label('Cycle')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'yearly' => 'success',
                        '6month' => 'info',
                        'monthly' => 'warning',
                        'weekly' => 'warning',
                        'one_time', 'onetime' => 'gray',
                        default => 'gray',
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('subscription_status')
                    ->label('Subscription')
                    ->badge()
                    ->state(function ($record): string {
                        if (! $record->provider_subscription_id) {
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
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Ends')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn ($record): string => $record->ends_at && $record->ends_at->isPast() ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('duration_label')
                    ->label('Duration'),

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
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('license.credit_reset_interval')
                    ->label('Reset')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('last_credit_reset_at')
                    ->label('Last Reset')
                    ->dateTime('Y-m-d')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('price_at_purchase')
                    ->label('Price')
                    ->money(fn ($record) => $record->currency_at_purchase ?? 'EUR')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

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
                        'trial' => 'Trial',
                    ]),

                Tables\Filters\SelectFilter::make('billing_cycle')
                    ->label('Billing cycle')
                    ->options([
                        'monthly' => 'Monthly',
                        'yearly' => 'Yearly',
                        '6month' => '6 month',
                        'weekly' => 'Weekly',
                        'one_time' => 'One time',
                    ])
                    ->query(fn ($query, array $data) => $data['value']
                        ? $query->whereHas('license', fn ($q) => $q->where('billing_cycle', $data['value']))
                        : $query
                    ),

                Tables\Filters\TernaryFilter::make('has_subscription')
                    ->label('Has Subscription')
                    ->queries(
                        true: fn ($query) => $query->where(fn ($q) => $q
                            ->whereNotNull('mollie_subscription_id')
                            ->orWhereNotNull('provider_subscription_id')),
                        false: fn ($query) => $query
                            ->whereNull('mollie_subscription_id')
                            ->whereNull('provider_subscription_id'),
                    ),

                Tables\Filters\TernaryFilter::make('premature_expiry')
                    ->label('Premature expiry')
                    ->placeholder('All')
                    ->trueLabel('Only anomalies')
                    ->falseLabel('Hide anomalies')
                    ->queries(
                        true: function ($query) {
                            $cycles = array_keys(UserLicense::PREMATURE_EXPIRY_THRESHOLDS);
                            $caseWhen = collect(UserLicense::PREMATURE_EXPIRY_THRESHOLDS)
                                ->map(fn ($days, $cycle) => "WHEN (SELECT billing_cycle FROM licenses WHERE licenses.id = user_licenses.license_id) = '{$cycle}' THEN {$days}")
                                ->implode(' ');

                            return $query
                                ->whereIn('user_licenses.status', ['canceled', 'expired'])
                                ->whereNotNull('user_licenses.starts_at')
                                ->whereNotNull('user_licenses.ends_at')
                                ->whereHas('license', fn ($q) => $q->whereIn('billing_cycle', $cycles))
                                ->whereRaw("TIMESTAMPDIFF(DAY, user_licenses.starts_at, user_licenses.ends_at) < CASE {$caseWhen} ELSE 999999 END");
                        },
                        false: fn ($query) => $query, // 'Hide anomalies' is too complex SQL-side for limited value
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
                Tables\Actions\EditAction::make(),

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
                    ->modalDescription(fn ($record) => "This will cancel the subscription for {$record->license?->name}. The user will retain access until the next renewal date.")
                    ->action(function ($record): void {
                        $renewalService = app(LicenseRenewalService::class);
                        $result = $renewalService->cancelRenewal($record, 'user');

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
