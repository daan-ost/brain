<?php

namespace App\Filament\Resources\LicenseResource\Pages;

use App\Filament\Resources\LicenseResource;
use App\Services\LicensePriceChangeService;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Artisan;

class EditLicense extends EditRecord
{
    protected static string $resource = LicenseResource::class;

    private ?array $originalData = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),

            Actions\Action::make('syncToStripe')
                ->label('Sync naar Stripe')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn () => $this->record->payment_provider === 'stripe')
                ->requiresConfirmation()
                ->modalHeading('Sync naar Stripe')
                ->modalDescription('Dit synchroniseert het Stripe Product en de Price voor deze licentie. Bij een prijswijziging wordt de oude Price gearchiveerd en een nieuwe aangemaakt.')
                ->action(function () {
                    $exitCode = Artisan::call('stripe:sync-prices', [
                        '--license' => $this->record->id,
                    ]);

                    $output = trim(Artisan::output());
                    $record = $this->record->fresh();

                    if ($exitCode === 0) {
                        Notification::make()
                            ->title('Gesynchroniseerd met Stripe')
                            ->body('Product: '.($record->stripe_product_id ?? '—').
                                ' | Price: '.($record->stripe_price_id ?? '—'))
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Sync mislukt')
                            ->body($output ?: 'Controleer de logs voor details.')
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('schedulePriceChange')
                ->label('Schedule Price Change')
                ->icon('heroicon-o-calendar')
                ->color('warning')
                ->visible(fn () => $this->record->tier === 'premium')
                ->form([
                    Forms\Components\Section::make('Current Pricing')
                        ->schema([
                            Forms\Components\Placeholder::make('current_amount')
                                ->label('Current Price')
                                ->content(fn () => $this->record->currency.' '.number_format($this->record->amount, 2)),
                            Forms\Components\Placeholder::make('current_credits')
                                ->label('Current Credits')
                                ->content(fn () => $this->record->credits),
                        ])
                        ->columns(2),

                    Forms\Components\Section::make('New Pricing')
                        ->schema([
                            Forms\Components\TextInput::make('new_amount')
                                ->label('New Price')
                                ->numeric()
                                ->prefix($this->record->currency)
                                ->required()
                                ->default($this->record->amount)
                                ->step(0.01),
                            Forms\Components\TextInput::make('new_credits')
                                ->label('New Credits')
                                ->numeric()
                                ->default($this->record->credits)
                                ->helperText('Leave empty to keep current credits'),
                            Forms\Components\DatePicker::make('effective_from')
                                ->label('Effective From')
                                ->required()
                                ->minDate(now()->addDay())
                                ->default(now()->addMonth()->startOfMonth())
                                ->helperText('New price will apply from this date for renewals'),
                        ])
                        ->columns(3),

                    Forms\Components\Section::make('Impact Analysis')
                        ->schema([
                            Forms\Components\Placeholder::make('impact')
                                ->label('')
                                ->content(function () {
                                    $service = app(LicensePriceChangeService::class);
                                    $impact = $service->getPriceChangeImpact($this->record);

                                    $html = '<div class="space-y-2">';
                                    $html .= '<p><strong>Total active subscriptions:</strong></p>';
                                    $html .= '<p>Users: '.$impact['total_user_licenses'].' | Organizations: '.$impact['total_org_licenses'].'</p>';
                                    $html .= '<hr class="my-2">';
                                    $html .= '<p><strong>Renewals within 7 days (keep OLD price):</strong></p>';
                                    $html .= '<p class="text-success-600">Users: '.$impact['user_renewals_within_7_days'].' | Orgs: '.$impact['org_renewals_within_7_days'].'</p>';
                                    $html .= '<p><strong>Renewals 7-30 days (will be notified NOW):</strong></p>';
                                    $html .= '<p class="text-warning-600">Users: '.$impact['user_renewals_within_30_days'].' | Orgs: '.$impact['org_renewals_within_30_days'].'</p>';
                                    $html .= '<p><strong>Renewals after 30 days (notified 30 days before):</strong></p>';
                                    $html .= '<p>Users: '.$impact['user_renewals_after_30_days'].' | Orgs: '.$impact['org_renewals_after_30_days'].'</p>';
                                    $html .= '</div>';

                                    return new \Illuminate\Support\HtmlString($html);
                                }),
                        ]),

                    Forms\Components\Checkbox::make('confirm')
                        ->label('I understand that this will schedule emails to customers and update Mollie subscriptions on the effective date')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $service = app(LicensePriceChangeService::class);

                    $service->schedulePriceChange(
                        license: $this->record,
                        newAmount: $data['new_amount'],
                        newCredits: $data['new_credits'] ?? null,
                        effectiveFrom: Carbon::parse($data['effective_from'])
                    );

                    Notification::make()
                        ->title('Price change scheduled')
                        ->body('Notifications will be sent 30 days before renewal. Mollie subscriptions will be updated on '.Carbon::parse($data['effective_from'])->format('d-m-Y'))
                        ->success()
                        ->send();
                }),

            Actions\Action::make('cancelPriceChange')
                ->label('Cancel Scheduled Change')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->upcoming_amount !== null)
                ->requiresConfirmation()
                ->modalHeading('Cancel Scheduled Price Change')
                ->modalDescription(fn () => 'This will cancel the scheduled price change from '.
                    $this->record->currency.' '.number_format($this->record->amount, 2).
                    ' to '.$this->record->currency.' '.number_format($this->record->upcoming_amount, 2).
                    ' (effective '.$this->record->price_effective_from?->format('d-m-Y').')')
                ->action(function () {
                    $service = app(LicensePriceChangeService::class);
                    $service->cancelScheduledPriceChange($this->record);

                    Notification::make()
                        ->title('Price change canceled')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->originalData = $data;

        return $data;
    }

    protected function beforeSave(): void
    {
        $record = $this->record;
        $newAmount = $this->data['amount'] ?? null;
        $oldAmount = $this->originalData['amount'] ?? null;

        // Check if price is being changed directly for a premium license with active subscriptions
        if (
            $record->tier === 'premium' &&
            $newAmount !== null &&
            $oldAmount !== null &&
            (float) $newAmount !== (float) $oldAmount
        ) {
            $service = app(LicensePriceChangeService::class);
            $impact = $service->getPriceChangeImpact($record);

            $totalSubscriptions = $impact['total_user_licenses'] + $impact['total_org_licenses'];

            if ($totalSubscriptions > 0) {
                Notification::make()
                    ->title('Warning: Direct price change not recommended')
                    ->body("This license has {$totalSubscriptions} active subscriptions. Use 'Schedule Price Change' button instead to notify customers and update Mollie properly.")
                    ->warning()
                    ->persistent()
                    ->send();
            }
        }
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
        ];
    }
}
