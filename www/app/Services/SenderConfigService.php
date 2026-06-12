<?php

namespace App\Services;

use App\Enums\SenderConfigStatus;
use App\Enums\SenderLevel;
use App\Models\Organization;
use App\Models\OrganizationSenderConfig;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class SenderConfigService
{
    protected Client $client;

    protected string $accountApiUrl = 'https://api.postmarkapp.com';

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
        ]);
    }

    public function isBusinessEmail(string $email): bool
    {
        $domain = strtolower(substr($email, strrpos($email, '@') + 1));
        $blockedDomains = config('sender.blocked_email_domains', []);

        return ! in_array($domain, $blockedDomains);
    }

    public function resolveSender(Organization $org): array
    {
        $config = $org->senderConfig;

        $default = [
            'from' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'reply_to' => null,
        ];

        if (! $config || ! $config->isUsable()) {
            return $default;
        }

        return match ($config->sender_level) {
            SenderLevel::ReplyTo => [
                'from' => $default['from'],
                'from_name' => $config->from_name ?? $default['from_name'],
                'reply_to' => $config->reply_to_email,
            ],
            SenderLevel::SenderSignature, SenderLevel::DomainAuth => [
                'from' => $config->from_email ?? $default['from'],
                'from_name' => $config->from_name ?? $default['from_name'],
                'reply_to' => $config->reply_to_email,
            ],
        };
    }

    public function configureReplyTo(Organization $org, string $email, string $name): OrganizationSenderConfig
    {
        $this->cleanupPreviousPostmarkResources($org);

        return $org->senderConfig()->updateOrCreate(
            ['organization_id' => $org->id],
            [
                'sender_level' => SenderLevel::ReplyTo,
                'status' => SenderConfigStatus::Active,
                'from_email' => null,
                'from_name' => $name,
                'reply_to_email' => $email,
                'domain' => null,
                'postmark_signature_id' => null,
                'postmark_domain_id' => null,
                'dns_records' => null,
                'verified_at' => null,
                'failure_reason' => null,
            ]
        );
    }

    public function createSenderSignature(Organization $org, string $email, string $name): OrganizationSenderConfig
    {
        // Create new Postmark resource FIRST — cleanup only after success
        try {
            $response = $this->accountApiRequest('POST', '/senders', [
                'FromEmail' => $email,
                'Name' => $name,
                'ReplyToEmail' => $email,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create Postmark sender signature', [
                'organization_id' => $org->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Postmark resource created — now safe to cleanup old resource
        $this->cleanupPreviousPostmarkResources($org);

        return $org->senderConfig()->updateOrCreate(
            ['organization_id' => $org->id],
            [
                'sender_level' => SenderLevel::SenderSignature,
                'status' => SenderConfigStatus::PendingVerification,
                'from_email' => $email,
                'from_name' => $name,
                'reply_to_email' => $email,
                'domain' => null,
                'postmark_signature_id' => $response['ID'] ?? null,
                'postmark_domain_id' => null,
                'dns_records' => null,
                'verified_at' => null,
                'failure_reason' => null,
            ]
        );
    }

    public function checkSignatureStatus(OrganizationSenderConfig $config): OrganizationSenderConfig
    {
        if (! $config->postmark_signature_id) {
            return $config;
        }

        try {
            $response = $this->accountApiRequest('GET', "/senders/{$config->postmark_signature_id}");

            $confirmed = $response['Confirmed'] ?? false;

            $config->update([
                'status' => $confirmed ? SenderConfigStatus::Verified : SenderConfigStatus::PendingVerification,
                'verified_at' => $confirmed ? now() : null,
            ]);

            return $config->fresh();
        } catch (\Exception $e) {
            Log::error('Failed to check Postmark signature status', [
                'config_id' => $config->id,
                'signature_id' => $config->postmark_signature_id,
                'error' => $e->getMessage(),
            ]);

            // Don't mark as Failed on transient errors (rate limit, server error)
            if (! $this->isTransientError($e)) {
                $config->update([
                    'status' => SenderConfigStatus::Failed,
                    'failure_reason' => $e->getMessage(),
                ]);
            }

            return $config->fresh();
        }
    }

    public function resendSignatureVerification(OrganizationSenderConfig $config): void
    {
        if (! $config->postmark_signature_id) {
            return;
        }

        try {
            $this->accountApiRequest('POST', "/senders/{$config->postmark_signature_id}/resend");
        } catch (\Exception $e) {
            Log::error('Failed to resend Postmark signature verification', [
                'config_id' => $config->id,
                'signature_id' => $config->postmark_signature_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function createDomain(Organization $org, string $domain, string $senderEmail): OrganizationSenderConfig
    {
        // Create new Postmark resource FIRST — cleanup only after success
        try {
            $response = $this->accountApiRequest('POST', '/domains', [
                'Name' => $domain,
                'ReturnPathDomain' => "pm-bounces.{$domain}",
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create Postmark domain', [
                'organization_id' => $org->id,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Postmark resource created — now safe to cleanup old resource
        $this->cleanupPreviousPostmarkResources($org);

        $dnsRecords = $this->extractDnsRecords($response);

        return $org->senderConfig()->updateOrCreate(
            ['organization_id' => $org->id],
            [
                'sender_level' => SenderLevel::DomainAuth,
                'status' => SenderConfigStatus::PendingVerification,
                'from_email' => $senderEmail,
                'from_name' => $org->name,
                'reply_to_email' => $senderEmail,
                'domain' => $domain,
                'postmark_signature_id' => null,
                'postmark_domain_id' => $response['ID'] ?? null,
                'dns_records' => $dnsRecords,
                'verified_at' => null,
                'failure_reason' => null,
            ]
        );
    }

    public function verifyDomainDns(OrganizationSenderConfig $config): OrganizationSenderConfig
    {
        if (! $config->postmark_domain_id) {
            return $config;
        }

        try {
            // Verify DKIM
            $dkimResponse = $this->accountApiRequest('PUT', "/domains/{$config->postmark_domain_id}/verifyDkim");
            // Verify Return-Path
            $returnPathResponse = $this->accountApiRequest('PUT', "/domains/{$config->postmark_domain_id}/verifyReturnPath");

            $dkimVerified = $dkimResponse['DKIMVerified'] ?? false;
            $returnPathVerified = $returnPathResponse['ReturnPathDomainVerified'] ?? false;

            $allVerified = $dkimVerified && $returnPathVerified;

            // Get fresh domain details for updated DNS records
            $domainDetails = $this->accountApiRequest('GET', "/domains/{$config->postmark_domain_id}");
            $dnsRecords = $this->extractDnsRecords($domainDetails);

            $config->update([
                'status' => $allVerified ? SenderConfigStatus::Verified : SenderConfigStatus::PendingVerification,
                'verified_at' => $allVerified ? now() : null,
                'dns_records' => $dnsRecords,
                'failure_reason' => $allVerified ? null : 'DNS records not fully verified yet.',
            ]);

            return $config->fresh();
        } catch (\Exception $e) {
            Log::error('Failed to verify Postmark domain DNS', [
                'config_id' => $config->id,
                'domain_id' => $config->postmark_domain_id,
                'error' => $e->getMessage(),
            ]);

            // Don't mark as Failed on transient errors (rate limit, server error)
            if (! $this->isTransientError($e)) {
                $config->update([
                    'status' => SenderConfigStatus::Failed,
                    'failure_reason' => $e->getMessage(),
                ]);
            }

            return $config->fresh();
        }
    }

    public function removeConfig(OrganizationSenderConfig $config): void
    {
        try {
            if ($config->postmark_signature_id) {
                $this->accountApiRequest('DELETE', "/senders/{$config->postmark_signature_id}");
            }

            if ($config->postmark_domain_id) {
                $this->accountApiRequest('DELETE', "/domains/{$config->postmark_domain_id}");
            }
        } catch (\Exception $e) {
            Log::error('Failed to remove Postmark sender config', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);
        }

        $config->delete();
    }

    public static function extractPostmarkError(\Exception $e): ?string
    {
        if ($e instanceof \GuzzleHttp\Exception\ClientException && $e->getResponse()) {
            try {
                $body = json_decode((string) $e->getResponse()->getBody(), true);

                return $body['Message'] ?? null;
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Returns true for transient HTTP errors (429 rate limit, 5xx server errors)
     * that should NOT permanently mark a config as Failed.
     */
    private function isTransientError(\Exception $e): bool
    {
        if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse()) {
            $status = $e->getResponse()->getStatusCode();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    /**
     * Clean up any existing Postmark resources (signature/domain) before switching levels.
     */
    private function cleanupPreviousPostmarkResources(Organization $org): void
    {
        $existing = $org->senderConfig()->first();
        if (! $existing) {
            return;
        }

        try {
            if ($existing->postmark_signature_id) {
                $this->accountApiRequest('DELETE', "/senders/{$existing->postmark_signature_id}");
            }
            if ($existing->postmark_domain_id) {
                $this->accountApiRequest('DELETE', "/domains/{$existing->postmark_domain_id}");
            }
        } catch (\Exception $e) {
            Log::warning('Failed to cleanup previous Postmark resources', [
                'config_id' => $existing->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function accountApiRequest(string $method, string $url, array $data = []): array
    {
        $options = [
            'headers' => [
                'X-Postmark-Account-Token' => config('postmark.account_token'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ];

        if (! empty($data)) {
            $options['json'] = $data;
        }

        $response = $this->client->request($method, $this->accountApiUrl . $url, $options);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    /**
     * Extract DNS records from Postmark domain response.
     * New domains have DKIM data in DKIMPendingHost/DKIMPendingTextValue.
     * Once verified, Postmark moves data to DKIMHost/DKIMTextValue.
     */
    private function extractDnsRecords(array $response): array
    {
        $records = [];

        // DKIM: use Pending fields for new domains, fall back to active fields
        $dkimHost = $response['DKIMPendingHost'] ?? $response['DKIMHost'] ?? '';
        $dkimValue = $response['DKIMPendingTextValue'] ?? $response['DKIMTextValue'] ?? '';

        if ($dkimHost || $dkimValue) {
            $records[] = [
                'type' => 'TXT',
                'name' => $dkimHost,
                'value' => $dkimValue,
                'verified' => $response['DKIMVerified'] ?? false,
            ];
        }

        if (isset($response['ReturnPathDomain'])) {
            $records[] = [
                'type' => 'CNAME',
                'name' => $response['ReturnPathDomain'] ?? '',
                'value' => $response['ReturnPathDomainCNAMEValue'] ?? 'pm.mtasv.net',
                'verified' => $response['ReturnPathDomainVerified'] ?? false,
            ];
        }

        return $records;
    }
}
