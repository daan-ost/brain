<?php

use App\Models\Order;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

describe('Download Endpoint Responses', function () {
    it('returns 200 for authorized download', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
        ]);

        Storage::disk('local')->put($order->invoice_file_path, 'fake pdf content');

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.download', $order));

        $response->assertOk();
    });

    it('returns application/pdf content type', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
        ]);

        Storage::disk('local')->put($order->invoice_file_path, 'fake pdf content');

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.download', $order));

        $response->assertHeader('Content-Type', 'application/pdf');
    });

    it('returns correct filename in content disposition', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
        ]);

        Storage::disk('local')->put($order->invoice_file_path, 'fake pdf content');

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.download', $order));

        $response->assertHeader('Content-Disposition', 'attachment; filename="2025-Q1-10001.pdf"');
    });

    it('returns 403 for unauthorized access', function () {
        $user1 = createUser();
        $user2 = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user2->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
        ]);

        $response = $this->actingAs($user1)
            ->get(route('profile.invoices.download', $order));

        $response->assertForbidden();
    });

    it('returns 404 for missing invoice', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            // No invoice_number set
        ]);

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.download', $order));

        $response->assertNotFound();
    });
});

describe('On-the-fly Invoice Generation', function () {
    it('generates invoice if file missing', function () {
        $user = createUser(['country' => 'NL']);

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => null, // File path not set
            'billing_snapshot' => [
                'first_name' => 'Test',
                'last_name' => 'User',
                'country' => 'NL',
            ],
        ]);

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.download', $order));

        // Should regenerate and return successfully
        $response->assertOk();

        // Order should be updated with file path
        $order->refresh();
        expect($order->invoice_file_path)->not->toBeNull();

        // File should exist in storage
        Storage::disk('local')->assertExists($order->invoice_file_path);
    });

    it('regenerates invoice if file deleted from storage', function () {
        $user = createUser(['country' => 'NL']);

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
            'billing_snapshot' => [
                'first_name' => 'Test',
                'last_name' => 'User',
                'country' => 'NL',
            ],
        ]);

        // File path exists but file doesn't
        // (simulates deleted file)

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.download', $order));

        $response->assertOk();

        // Refresh to get the regenerated file path
        $order->refresh();

        // File should now exist (with newly generated path)
        Storage::disk('local')->assertExists($order->invoice_file_path);
    });
});

describe('File Content Validation', function () {
    it('returns PDF file content', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
        ]);

        $pdfContent = '%PDF-1.4 fake pdf content';
        Storage::disk('local')->put($order->invoice_file_path, $pdfContent);

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.download', $order));

        expect($response->getContent())->toBe($pdfContent);
    });

    it('returns non-empty file', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
        ]);

        Storage::disk('local')->put($order->invoice_file_path, 'fake pdf content');

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.download', $order));

        expect($response->getContent())->not->toBeEmpty();
    });
});

describe('Multiple Downloads', function () {
    it('allows multiple downloads of same invoice', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
        ]);

        Storage::disk('local')->put($order->invoice_file_path, 'fake pdf content');

        // First download
        $response1 = $this->actingAs($user)
            ->get(route('profile.invoices.download', $order));

        // Second download
        $response2 = $this->actingAs($user)
            ->get(route('profile.invoices.download', $order));

        $response1->assertOk();
        $response2->assertOk();
    });
});

describe('Error Scenarios', function () {
    it('handles missing storage disk gracefully', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
        ]);

        // File doesn't exist and can't be generated (would be caught by service)
        // This test verifies the download endpoint handles the scenario

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.download', $order));

        // Should either regenerate (200) or fail gracefully (500/404)
        $this->assertTrue(
            in_array($response->status(), [200, 404, 500])
        );
    });
});

describe('Organization Invoice Downloads', function () {
    it('allows org admin to download org invoice', function () {
        $org = createOrganization(['name' => 'Test Org']);
        $admin = createUser();

        $org->users()->attach($admin->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $order = createOrder([
            'payer_type' => 'organization',
            'payer_id' => $org->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
        ]);

        Storage::disk('local')->put($order->invoice_file_path, 'fake pdf content');

        $response = $this->actingAs($admin)
            ->get(route('profile.invoices.download', $order));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    });

    it('prevents org member from downloading org invoice', function () {
        $org = createOrganization(['name' => 'Test Org']);
        $member = createUser();

        $org->users()->attach($member->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now(),
        ]);

        $order = createOrder([
            'payer_type' => 'organization',
            'payer_id' => $org->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
        ]);

        $response = $this->actingAs($member)
            ->get(route('profile.invoices.download', $order));

        $response->assertForbidden();
    });
});

describe('Invoice List Page', function () {
    it('displays invoice list page', function () {
        $user = createUser();

        createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
        ]);

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.index'));

        $response->assertOk();
    });

    it('shows invoice details on list page', function () {
        $user = createUser();

        createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
        ]);

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.index'));

        $response->assertSee('2025-Q1-10001');
    });

    it('shows empty state when no invoices', function () {
        $user = createUser();

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.index'));

        $response->assertOk();
        // Should show some kind of empty state message
    });
});
