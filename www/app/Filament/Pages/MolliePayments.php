<?php

namespace App\Filament\Pages;

use App\Services\MolliePaymentService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class MolliePayments extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Mollie Payments';

    protected static string $view = 'filament.pages.mollie-payments';

    public ?string $searchQuery = null;

    public ?string $statusFilter = null;

    public ?string $methodFilter = null;

    public array $payments = [];

    public bool $isLoading = false;

    public ?string $error = null;

    public ?array $selectedPayment = null;

    public bool $showRefundModal = false;

    public ?string $refundAmount = null;

    public ?string $refundDescription = null;

    public function mount(): void
    {
        $this->loadPayments();
    }

    public function loadPayments(): void
    {
        $this->isLoading = true;
        $this->error = null;

        try {
            $mollieService = app(MolliePaymentService::class);

            if (! empty($this->searchQuery)) {
                $result = $mollieService->searchPaymentsForAdmin($this->searchQuery);
            } elseif (! empty($this->statusFilter) || ! empty($this->methodFilter)) {
                $filters = array_filter([
                    'status' => $this->statusFilter,
                    'method' => $this->methodFilter,
                ]);
                $result = $mollieService->filterPaymentsForAdmin($filters);
            } else {
                $result = $mollieService->listPaymentsForAdmin(['limit' => 50]);
            }

            if ($result['success']) {
                $this->payments = $result['payments'];
            } else {
                $this->error = $result['error'] ?? 'Failed to load payments';
                $this->payments = [];
            }
        } catch (\Exception $e) {
            $this->error = 'Error: '.$e->getMessage();
            $this->payments = [];
        }

        $this->isLoading = false;
    }

    public function search(): void
    {
        $this->loadPayments();
    }

    public function clearFilters(): void
    {
        $this->searchQuery = null;
        $this->statusFilter = null;
        $this->methodFilter = null;
        $this->loadPayments();
    }

    public function viewPayment(string $paymentId): void
    {
        $mollieService = app(MolliePaymentService::class);
        $result = $mollieService->getPaymentForAdmin($paymentId);

        if ($result['success']) {
            $this->selectedPayment = $result['payment'];
        } else {
            Notification::make()
                ->title('Failed to load payment details')
                ->body($result['error'] ?? 'Unknown error')
                ->danger()
                ->send();
        }
    }

    public function closePaymentModal(): void
    {
        $this->selectedPayment = null;
    }

    public function openRefundModal(string $paymentId): void
    {
        $this->viewPayment($paymentId);
        $this->showRefundModal = true;
        $this->refundAmount = null;
        $this->refundDescription = null;
    }

    public function closeRefundModal(): void
    {
        $this->showRefundModal = false;
        $this->refundAmount = null;
        $this->refundDescription = null;
    }

    public function createRefund(): void
    {
        if (! $this->selectedPayment || ! $this->refundAmount) {
            Notification::make()
                ->title('Please enter a refund amount')
                ->warning()
                ->send();

            return;
        }

        $mollieService = app(MolliePaymentService::class);
        $result = $mollieService->createRefundForAdmin($this->selectedPayment['id'], [
            'amount' => $this->refundAmount,
            'currency' => $this->selectedPayment['amount']['currency'],
            'description' => $this->refundDescription ?? 'Refund via admin panel',
        ]);

        if ($result['success']) {
            Notification::make()
                ->title('Refund created successfully')
                ->success()
                ->send();

            $this->closeRefundModal();
            $this->loadPayments();
        } else {
            Notification::make()
                ->title('Failed to create refund')
                ->body($result['error'] ?? 'Unknown error')
                ->danger()
                ->send();
        }
    }

    public function openInMollie(string $paymentId): void
    {
        $this->dispatch('open-mollie-dashboard', paymentId: $paymentId);
    }

    protected function getFiltersFormSchema(): array
    {
        $mollieService = app(MolliePaymentService::class);

        return [
            Forms\Components\TextInput::make('searchQuery')
                ->label('Search')
                ->placeholder('Payment ID, description, email...')
                ->live(debounce: 500),

            Forms\Components\Select::make('statusFilter')
                ->label('Status')
                ->options([
                    'open' => 'Open',
                    'pending' => 'Pending',
                    'authorized' => 'Authorized',
                    'paid' => 'Paid',
                    'expired' => 'Expired',
                    'failed' => 'Failed',
                    'canceled' => 'Canceled',
                ])
                ->placeholder('All statuses'),

            Forms\Components\Select::make('methodFilter')
                ->label('Payment Method')
                ->options($mollieService->getPaymentMethodsForAdmin())
                ->placeholder('All methods'),
        ];
    }

    public function getStatusBadgeColor(string $status): string
    {
        return match ($status) {
            'paid' => 'success',
            'pending', 'authorized', 'open' => 'warning',
            'failed', 'canceled', 'expired' => 'danger',
            default => 'gray',
        };
    }
}
