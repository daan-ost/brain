<?php

namespace Database\Factories;

use App\Enums\SenderConfigStatus;
use App\Enums\SenderLevel;
use App\Models\Organization;
use App\Models\OrganizationSenderConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrganizationSenderConfig>
 */
class OrganizationSenderConfigFactory extends Factory
{
    protected $model = OrganizationSenderConfig::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'sender_level' => SenderLevel::ReplyTo,
            'status' => SenderConfigStatus::Active,
            'from_email' => null,
            'from_name' => null,
            'reply_to_email' => $this->faker->companyEmail(),
            'domain' => null,
            'postmark_signature_id' => null,
            'postmark_domain_id' => null,
            'dns_records' => null,
            'verified_at' => null,
            'failure_reason' => null,
        ];
    }

    public function senderSignature(): static
    {
        return $this->state(fn () => [
            'sender_level' => SenderLevel::SenderSignature,
            'status' => SenderConfigStatus::PendingVerification,
            'from_email' => $this->faker->companyEmail(),
            'from_name' => $this->faker->company(),
            'reply_to_email' => null,
            'postmark_signature_id' => $this->faker->randomNumber(6),
        ]);
    }

    public function domainAuth(): static
    {
        return $this->state(fn () => [
            'sender_level' => SenderLevel::DomainAuth,
            'status' => SenderConfigStatus::PendingVerification,
            'from_email' => $this->faker->companyEmail(),
            'from_name' => $this->faker->company(),
            'domain' => $this->faker->domainName(),
            'postmark_domain_id' => $this->faker->randomNumber(6),
            'dns_records' => [
                ['type' => 'TXT', 'name' => 'example._domainkey', 'value' => 'dkim-value', 'verified' => false],
                ['type' => 'CNAME', 'name' => 'pm-bounces', 'value' => 'pm.mtasv.net', 'verified' => false],
            ],
        ]);
    }

    public function pendingVerification(): static
    {
        return $this->state(fn () => [
            'status' => SenderConfigStatus::PendingVerification,
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn () => [
            'status' => SenderConfigStatus::Verified,
            'verified_at' => now(),
        ]);
    }
}
