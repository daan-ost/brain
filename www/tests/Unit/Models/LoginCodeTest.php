<?php

namespace Tests\Unit\Models;

use App\Models\LoginCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_isExpired_returns_true_for_past_expires_at(): void
    {
        $code = LoginCode::create([
            'email'      => 'test@example.com',
            'code'       => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($code->isExpired());
    }

    public function test_isExpired_returns_false_for_future_expires_at(): void
    {
        $code = LoginCode::create([
            'email'      => 'test@example.com',
            'code'       => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->assertFalse($code->isExpired());
    }

    public function test_isUsed_reflects_used_at_timestamp(): void
    {
        $code = LoginCode::create([
            'email'      => 'test@example.com',
            'code'       => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->assertFalse($code->isUsed());

        $code->update(['used_at' => now()]);
        $this->assertTrue($code->fresh()->isUsed());
    }

    public function test_isValid_requires_not_used_and_not_expired(): void
    {
        $valid = LoginCode::create([
            'email'      => 'a@example.com',
            'code'       => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
        ]);
        $expired = LoginCode::create([
            'email'      => 'b@example.com',
            'code'       => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
        ]);
        $used = LoginCode::create([
            'email'      => 'c@example.com',
            'code'       => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
            'used_at'    => now(),
        ]);

        $this->assertTrue($valid->isValid());
        $this->assertFalse($expired->isValid());
        $this->assertFalse($used->isValid());
    }

    public function test_prunable_query_targets_codes_older_than_seven_days(): void
    {
        $old = LoginCode::create([
            'email'      => 'old@example.com',
            'code'       => Hash::make('111111'),
            'expires_at' => now()->subDays(8),
        ]);
        $recent = LoginCode::create([
            'email'      => 'recent@example.com',
            'code'       => Hash::make('222222'),
            'expires_at' => now()->subDays(6),
        ]);
        $future = LoginCode::create([
            'email'      => 'future@example.com',
            'code'       => Hash::make('333333'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $prunable = (new LoginCode())->prunable()->pluck('id')->all();

        $this->assertContains($old->id, $prunable);
        $this->assertNotContains($recent->id, $prunable);
        $this->assertNotContains($future->id, $prunable);
    }

    public function test_datetime_casts_return_carbon_instances(): void
    {
        $code = LoginCode::create([
            'email'      => 'test@example.com',
            'code'       => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
            'used_at'    => now(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $code->expires_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $code->used_at);
    }
}
