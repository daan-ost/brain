<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Payments\StripeRefundService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class StripePayments extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Stripe Payments';

    protected static string $view = 'filament.pages.stripe-payments';

    public ?string $searchQuery = null;

    public ?string $statusFilter = null;

    public array $orders = [];

    public ?string $selectedOrderId = null;

    public bool $showRefundModal = false;

    public ?string $refundAmount = null;

    public function mount(): void
    {
        $this->loadOrders();
    }

    public function loadOrders(): void
    {
        $query = Order::where('payment_provider', 'stripe')
            ->with('license')
            ->latest();

        if ($this->searchQuery) {
            $query->where(function ($q) {
                $q->where('id', 'like', "%{$this->searchQuery}%")
                    ->orWhere('provider_payment_id', 'like', "%{$this->searchQuery}%")
                    ->orWhere('provider_customer_id', 'like', "%{$this->searchQuery}%")
                    ->orWhereJsonContains('billing_snapshot->email', $this->searchQuery);
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        $this->orders = $query->limit(100)->get()->map(fn ($order) => [
            'id' => $order->id,
            'status' => $order->status?->value ?? $order->status,
            'gross_amount' => $order->gross_amount,
            'currency' => $order->currency,
            'provider_payment_id' => $order->provider_payment_id,
            'provider_customer_id' => $order->provider_customer_id,
            'provider_subscription_id' => $order->provider_subscription_id,
            'email' => $order->billing_snapshot['email'] ?? null,
            'license_name' => $order->license?->name,
            'paid_at' => $order->paid_at?->toDateTimeString(),
            'created_at' => $order->created_at->toDateTimeString(),
            'type' => $order->type,
        ])->toArray();
    }

    public function search(): void
    {
        $this->loadOrders();
    }

    public function selectOrder(string $orderId): void
    {
        $this->selectedOrderId = $orderId;
        $this->showRefundModal = true;
        $this->refundAmount = null;
    }

    public function closeModal(): void
    {
        $this->showRefundModal = false;
        $this->selectedOrderId = null;
        $this->refundAmount = null;
    }

    public function processRefund(): void
    {
        if (! $this->selectedOrderId) {
            Notification::make()->title('Geen order geselecteerd')->warning()->send();

            return;
        }

        $order = Order::find($this->selectedOrderId);
        if (! $order) {
            Notification::make()->title('Order niet gevonden')->danger()->send();

            return;
        }

        $amountEur = $this->refundAmount ? (float) str_replace(',', '.', $this->refundAmount) : null;

        $result = app(StripeRefundService::class)->createRefund($order, $amountEur);

        if ($result['success']) {
            Notification::make()
                ->title('Refund aangemaakt: €'.number_format($result['amount'], 2))
                ->success()
                ->send();

            $this->closeModal();
            $this->loadOrders();
        } else {
            Notification::make()
                ->title('Refund mislukt: '.($result['error'] ?? 'Onbekende fout'))
                ->danger()
                ->send();
        }
    }

    public function getStatusOptions(): array
    {
        return collect(OrderStatus::cases())
            ->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])
            ->toArray();
    }
}
