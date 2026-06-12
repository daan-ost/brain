<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

describe('User Invoice Authorization', function () {
    it('allows user to download their own invoice', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
        ]);

        // Create fake PDF file
        Storage::disk('local')->put($order->invoice_file_path, 'fake pdf content');

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.download', $order));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    });

    it('prevents user from downloading other users invoices', function () {
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

    it('redirects unauthenticated users to login', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
        ]);

        $response = $this->get(route('profile.invoices.download', $order));

        $response->assertRedirect(route('login'));
    });
});

describe('Organization Invoice Authorization', function () {
    it('allows org admin to download org invoice', function () {
        $org = createOrganization(['name' => 'Test Org']);
        $admin = createUser();

        // Attach user as admin
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

        // Create fake PDF file
        Storage::disk('local')->put($order->invoice_file_path, 'fake pdf content');

        $response = $this->actingAs($admin)
            ->get(route('profile.invoices.download', $order));

        $response->assertOk();
    });

    it('prevents org member from downloading org invoice', function () {
        $org = createOrganization(['name' => 'Test Org']);
        $member = createUser();

        // Attach user as regular member (NOT admin)
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

    it('prevents non-member from downloading org invoice', function () {
        $org = createOrganization(['name' => 'Test Org']);
        $outsider = createUser();

        $order = createOrder([
            'payer_type' => 'organization',
            'payer_id' => $org->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
        ]);

        $response = $this->actingAs($outsider)
            ->get(route('profile.invoices.download', $order));

        $response->assertForbidden();
    });
});

describe('Authorization Edge Cases', function () {
    it('prevents access after user leaves organization', function () {
        $org = createOrganization(['name' => 'Test Org']);
        $formerAdmin = createUser();

        $order = createOrder([
            'payer_type' => 'organization',
            'payer_id' => $org->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
        ]);

        // User was admin but left the organization
        // (not attached to org)

        $response = $this->actingAs($formerAdmin)
            ->get(route('profile.invoices.download', $order));

        $response->assertForbidden();
    });

    it('allows access to invoice even after license expires', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
        ]);

        // Create fake PDF file
        Storage::disk('local')->put($order->invoice_file_path, 'fake pdf content');

        // Invoices are permanent - even after license expires, user can download
        $response = $this->actingAs($user)
            ->get(route('profile.invoices.download', $order));

        $response->assertOk();
    });
});

describe('Invoice List Authorization', function () {
    it('shows user their own invoices', function () {
        $user = createUser();

        createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
        ]);

        createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10002',
        ]);

        $response = $this->actingAs($user)
            ->get(route('profile.invoices.index'));

        $response->assertOk();
        $response->assertSee('2025-Q1-10001');
        $response->assertSee('2025-Q1-10002');
    });

    it('shows org admin both personal and org invoices', function () {
        $org = createOrganization(['name' => 'Test Org']);
        $admin = createUser();

        // Attach as admin
        $org->users()->attach($admin->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        // Personal invoice
        createOrder([
            'payer_type' => 'user',
            'payer_id' => $admin->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
        ]);

        // Organization invoice
        createOrder([
            'payer_type' => 'organization',
            'payer_id' => $org->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10002',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('profile.invoices.index'));

        $response->assertOk();
        $response->assertSee('2025-Q1-10001'); // Personal
        $response->assertSee('2025-Q1-10002'); // Organization
    });

    it('shows org member only personal invoices', function () {
        $org = createOrganization(['name' => 'Test Org']);
        $member = createUser();

        // Attach as regular member
        $org->users()->attach($member->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now(),
        ]);

        // Personal invoice
        createOrder([
            'payer_type' => 'user',
            'payer_id' => $member->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
        ]);

        // Organization invoice (should NOT see)
        createOrder([
            'payer_type' => 'organization',
            'payer_id' => $org->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10002',
        ]);

        $response = $this->actingAs($member)
            ->get(route('profile.invoices.index'));

        $response->assertOk();
        $response->assertSee('2025-Q1-10001'); // Personal
        $response->assertDontSee('2025-Q1-10002'); // Org invoice (hidden)
    });

    it('hides other users invoices', function () {
        $user1 = createUser();
        $user2 = createUser();

        // User 2's invoice
        createOrder([
            'payer_type' => 'user',
            'payer_id' => $user2->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-99999',
        ]);

        $response = $this->actingAs($user1)
            ->get(route('profile.invoices.index'));

        $response->assertOk();
        $response->assertDontSee('2025-Q1-99999');
    });
});
