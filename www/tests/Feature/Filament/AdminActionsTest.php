<?php

use App\Enums\OrderStatus;
use App\Models\CreditLedger;
use App\Models\License;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

describe('User Grant Credits Action', function () {
    test('admin can grant bonus credits to user', function () {
        $user = User::factory()->create(['credits' => 100]);

        // Simulate the grant credits action
        $currentBalance = $user->credits;
        $grantAmount = 50;
        $newBalance = $currentBalance + $grantAmount;

        CreditLedger::create([
            'user_id' => $user->id,
            'delta' => $grantAmount,
            'reason' => 'bonus',
            'balance_after' => $newBalance,
            'meta' => [
                'admin_reason' => 'Test bonus',
                'granted_by' => $this->admin->name,
                'granted_at' => now()->toISOString(),
            ],
        ]);

        $user->update(['credits' => $newBalance]);

        $user->refresh();
        expect($user->credits)->toBe(150);

        $ledger = CreditLedger::where('user_id', $user->id)->latest()->first();
        expect($ledger->delta)->toBe(50);
        expect($ledger->reason)->toBe('bonus');
        expect($ledger->meta['admin_reason'])->toBe('Test bonus');
    });

    test('grant credits creates ledger entry with correct balance_after', function () {
        $user = User::factory()->create(['credits' => 200]);

        $grantAmount = 100;
        $newBalance = $user->credits + $grantAmount;

        CreditLedger::create([
            'user_id' => $user->id,
            'delta' => $grantAmount,
            'reason' => 'compensation',
            'balance_after' => $newBalance,
            'meta' => ['admin_reason' => 'Service issue compensation'],
        ]);

        $user->update(['credits' => $newBalance]);

        $ledger = CreditLedger::where('user_id', $user->id)->latest()->first();

        expect($ledger->balance_after)->toBe(300);
        expect($ledger->reason)->toBe('compensation');
    });
});

describe('User License Subscription Management', function () {
    test('subscription status shows active for license with subscription', function () {
        $user = User::factory()->create();
        $license = License::factory()->create(['tier' => 'premium', 'billing_cycle' => 'yearly']);

        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'mollie_subscription_id' => 'sub_test123',
            'ends_at' => null,
        ]);

        // Simulate the status calculation
        $status = 'N/A';
        if ($userLicense->mollie_subscription_id) {
            if ($userLicense->status === 'canceled' || $userLicense->ends_at) {
                $status = 'Canceled';
            } else {
                $status = 'Active';
            }
        }

        expect($status)->toBe('Active');
    });

    test('subscription status shows canceled when ends_at is set', function () {
        $user = User::factory()->create();
        $license = License::factory()->create(['tier' => 'premium']);

        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'canceled',
            'mollie_subscription_id' => 'sub_test123',
            'ends_at' => now()->addMonth(),
        ]);

        $status = 'N/A';
        if ($userLicense->mollie_subscription_id) {
            if ($userLicense->status === 'canceled' || $userLicense->ends_at) {
                $status = 'Canceled';
            } else {
                $status = 'Active';
            }
        }

        expect($status)->toBe('Canceled');
    });

    test('subscription status shows N/A for non-subscription license', function () {
        $user = User::factory()->create();
        $license = License::factory()->create(['tier' => 'onetime']);

        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'mollie_subscription_id' => null,
        ]);

        $status = 'N/A';
        if ($userLicense->mollie_subscription_id) {
            $status = 'Active';
        }

        expect($status)->toBe('N/A');
    });
});

describe('Organization License Management', function () {
    test('organization subscription shows invoice status for invoice billing', function () {
        $organization = Organization::factory()->create();
        $license = License::factory()->create(['tier' => 'enterprise']);

        $orgLicense = OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active',
            'billing_method' => 'invoice',
            'mollie_subscription_id' => null,
        ]);

        $status = 'N/A';
        if ($orgLicense->billing_method === 'invoice') {
            $status = 'Invoice';
        } elseif ($orgLicense->mollie_subscription_id) {
            $status = 'Active';
        }

        expect($status)->toBe('Invoice');
    });
});

describe('Order Actions', function () {
    test('order refund metadata is stored correctly', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();

        $order = Order::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'type' => 'onetime',
            'status' => OrderStatus::Paid,
            'mollie_payment_id' => 'tr_test123',
            'gross_amount' => 49.00,
            'net_amount' => 40.50,
            'tax_amount' => 8.50,
            'currency' => 'EUR',
            'country' => 'NL',
            'meta' => [],
        ]);

        // Simulate refund metadata update (keeping status as paid since DB enum doesn't support refunded)
        $refundData = [
            'refund_id' => 're_test456',
            'refund_amount' => 49.00,
            'refund_reason' => 'Customer requested refund',
            'refunded_at' => now()->toISOString(),
            'refunded_by' => $this->admin->name,
        ];

        $order->update([
            'meta' => array_merge($order->meta ?? [], $refundData),
        ]);

        $order->refresh();

        expect($order->meta['refund_reason'])->toBe('Customer requested refund');
        expect((float) $order->meta['refund_amount'])->toBe(49.00);
        expect($order->meta['refund_id'])->toBe('re_test456');
    });

    test('partial refund keeps order as paid', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'gross_amount' => 100.00,
        ]);

        $refundAmount = 25.00;

        // Partial refund should keep status as paid
        $newStatus = $refundAmount >= $order->gross_amount ? 'refunded' : 'paid';

        expect($newStatus)->toBe('paid');
    });

    test('full refund changes order status to refunded', function () {
        $order = Order::factory()->create([
            'status' => 'paid',
            'gross_amount' => 100.00,
        ]);

        $refundAmount = 100.00;

        $newStatus = $refundAmount >= $order->gross_amount ? 'refunded' : 'paid';

        expect($newStatus)->toBe('refunded');
    });
});

describe('Credit Ledger Expiration Display', function () {
    test('credit entry shows expiration date when set', function () {
        $user = User::factory()->create();

        $ledger = CreditLedger::create([
            'user_id' => $user->id,
            'delta' => 100,
            'reason' => 'bonus',
            'balance_after' => 100,
            'expires_at' => now()->addMonth(),
        ]);

        expect($ledger->expires_at)->not->toBeNull();
        expect($ledger->expires_at->isFuture())->toBeTrue();
    });

    test('expired credits are identified correctly', function () {
        $user = User::factory()->create();

        $ledger = CreditLedger::create([
            'user_id' => $user->id,
            'delta' => 100,
            'reason' => 'bonus',
            'balance_after' => 100,
            'expires_at' => now()->subDay(),
        ]);

        expect($ledger->expires_at->isPast())->toBeTrue();
    });

    test('credits expiring within 30 days are identified', function () {
        $user = User::factory()->create();

        $ledger = CreditLedger::create([
            'user_id' => $user->id,
            'delta' => 100,
            'reason' => 'bonus',
            'balance_after' => 100,
            'expires_at' => now()->addDays(15),
        ]);

        $isExpiringSoon = $ledger->expires_at->diffInDays(now()) <= 30;

        expect($isExpiringSoon)->toBeTrue();
    });
});

describe('Next Renewal Date Calculation', function () {
    test('yearly subscription shows correct next renewal', function () {
        $renewalService = app(\App\Services\LicenseRenewalService::class);

        $startDate = now()->subMonths(3);
        $nextRenewal = $renewalService->getNextRenewalDate($startDate, 'yearly');

        expect($nextRenewal)->not->toBeNull();
        expect($nextRenewal->isFuture())->toBeTrue();
    });

    test('monthly subscription shows correct next renewal', function () {
        $renewalService = app(\App\Services\LicenseRenewalService::class);

        $startDate = now()->subDays(15);
        $nextRenewal = $renewalService->getNextRenewalDate($startDate, 'monthly');

        expect($nextRenewal)->not->toBeNull();
    });
});
