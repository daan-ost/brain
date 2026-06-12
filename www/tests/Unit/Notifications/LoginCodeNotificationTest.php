<?php

namespace Tests\Unit\Notifications;

use App\Jobs\SendPostmarkTemplateEmail;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class LoginCodeNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Read a private property from a job instance (the SendPostmarkTemplateEmail
     * job uses promoted `private` constructor params, so we need reflection).
     */
    private function jobProperty(SendPostmarkTemplateEmail $job, string $name): mixed
    {
        $ref = new \ReflectionClass($job);
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($job);
    }

    public function test_dispatches_postmark_job_with_nl_template_for_dutch_user(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'email'              => 'nl@example.com',
            'name'               => 'NL User',
            'preferred_language' => 'nl',
        ]);

        $notification = new LoginCodeNotification('111222');
        $result = $notification->toArray($user);

        $this->assertSame(['code_sent' => true, 'locale' => 'nl'], $result);

        Bus::assertDispatched(SendPostmarkTemplateEmail::class, function ($job) {
            return $this->jobProperty($job, 'templateAlias') === 'login_code__nl'
                && $this->jobProperty($job, 'templateModel')['code'] === '111222'
                && $this->jobProperty($job, 'templateModel')['expires_minutes'] === 15
                && $this->jobProperty($job, 'to') === 'nl@example.com'
                && $this->jobProperty($job, 'tag') === 'login-code';
        });
    }

    public function test_dispatches_en_template_for_english_user(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'email'              => 'en@example.com',
            'preferred_language' => 'en',
        ]);

        (new LoginCodeNotification('999000'))->toArray($user);

        Bus::assertDispatched(SendPostmarkTemplateEmail::class, function ($job) {
            return $this->jobProperty($job, 'templateAlias') === 'login_code__en';
        });
    }

    public function test_falls_back_to_en_for_unsupported_locale(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'email'              => 'fr@example.com',
            'preferred_language' => 'fr',
        ]);

        $result = (new LoginCodeNotification('555444'))->toArray($user);

        $this->assertSame('en', $result['locale']);
        Bus::assertDispatched(SendPostmarkTemplateEmail::class, function ($job) {
            return $this->jobProperty($job, 'templateAlias') === 'login_code__en';
        });
    }

    public function test_falls_back_to_nl_when_preferred_language_is_null(): void
    {
        Bus::fake();

        // preferred_language has a NOT NULL DB constraint with default 'nl';
        // we use a stdClass-like notifiable to test the helper logic in isolation.
        $notifiable = new \stdClass();
        $notifiable->id    = 999;
        $notifiable->name  = 'No Prefs';
        $notifiable->email = 'noprefs@example.com';
        $notifiable->preferred_language = null;

        $result = (new LoginCodeNotification('123123'))->toArray($notifiable);

        $this->assertSame('nl', $result['locale']);
    }

    public function test_via_returns_database_only(): void
    {
        $notification = new LoginCodeNotification('000000');
        $this->assertSame(['database'], $notification->via(new \stdClass()));
    }

    public function test_template_model_includes_required_postmark_variables(): void
    {
        Bus::fake();

        $user = User::factory()->create(['name' => 'Variable User', 'preferred_language' => 'nl']);
        (new LoginCodeNotification('424242'))->toArray($user);

        Bus::assertDispatched(SendPostmarkTemplateEmail::class, function ($job) {
            $model = $this->jobProperty($job, 'templateModel');
            $required = ['name', 'code', 'expires_minutes', 'subject',
                         'product_name', 'product_url', 'company_name', 'company_address'];
            foreach ($required as $key) {
                if (! array_key_exists($key, $model)) {
                    return false;
                }
            }
            return true;
        });
    }
}
