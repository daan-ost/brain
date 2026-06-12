<?php

namespace Tests\Feature\Newsletter;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LastLoginAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_last_login_at_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'last_login_at'));
    }

    public function test_new_users_have_null_last_login_at(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->last_login_at);
    }

    public function test_last_login_at_is_updated_on_login_event(): void
    {
        $user = User::factory()->create(['last_login_at' => null]);

        Event::dispatch(new Login('web', $user, false));

        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertTrue($user->last_login_at->isToday());
    }

    public function test_last_login_at_is_cast_to_datetime(): void
    {
        $user = User::factory()->create(['last_login_at' => now()]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $user->refresh()->last_login_at);
    }

    public function test_listener_ignores_non_user_authenticatables(): void
    {
        $fakeUser = new class extends \Illuminate\Foundation\Auth\User {
            public $id = 999;
        };

        Event::dispatch(new Login('web', $fakeUser, false));

        $this->assertTrue(true);
    }
}
