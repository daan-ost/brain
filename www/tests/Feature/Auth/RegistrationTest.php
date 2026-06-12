<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $this->withoutExceptionHandling(); // Show real exceptions
        Notification::fake();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'newreguser@gmail.com',
            'password' => 'password123456',
            'password_confirmation' => 'password123456',
            'terms' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertAuthenticated();
        $response->assertRedirect(route('verification.notice'));
    }
}
