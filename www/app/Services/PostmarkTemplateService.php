<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class PostmarkTemplateService
{
    protected Client $client;

    protected string $baseUrl = 'https://api.postmarkapp.com';

    protected string $stagingToken;

    protected string $productionToken;

    protected int $stagingServerId;

    protected int $productionServerId;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $this->stagingToken = config('postmark.staging_server_token');
        $this->productionToken = config('postmark.production_server_token');
        $this->stagingServerId = config('postmark.staging_server_id');
        $this->productionServerId = config('postmark.production_server_id');
    }

    /**
     * Get all templates from Postmark
     */
    public function getTemplates(bool $useProduction = false): array
    {
        try {
            $response = $this->client->get($this->baseUrl.'/templates', [
                'headers' => [
                    'X-Postmark-Server-Token' => $useProduction ? $this->productionToken : $this->stagingToken,
                ],
                'query' => [
                    'offset' => 0,
                    'count' => 500,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['Templates'] ?? [];
        } catch (RequestException $e) {
            Log::error('Postmark API Error - Get Templates: '.$e->getMessage());
            throw new \Exception('Failed to fetch templates from Postmark: '.$e->getMessage());
        }
    }

    /**
     * Get all layout templates from Postmark
     */
    public function getLayoutTemplates(bool $useProduction = false): array
    {
        try {
            $response = $this->client->get($this->baseUrl.'/templates', [
                'headers' => [
                    'X-Postmark-Server-Token' => $useProduction ? $this->productionToken : $this->stagingToken,
                ],
                'query' => [
                    'TemplateType' => 'Layout',
                    'offset' => 0,
                    'count' => 500,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['Templates'] ?? [];
        } catch (RequestException $e) {
            Log::error('Postmark API Error - Get Layout Templates: '.$e->getMessage());
            throw new \Exception('Failed to fetch layout templates from Postmark: '.$e->getMessage());
        }
    }

    /**
     * Get a specific template by ID
     */
    public function getTemplate(string $templateId, bool $useProduction = false): array
    {
        try {
            $response = $this->client->get($this->baseUrl.'/templates/'.$templateId, [
                'headers' => [
                    'X-Postmark-Server-Token' => $useProduction ? $this->productionToken : $this->stagingToken,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::error('Postmark API Error - Get Template: '.$e->getMessage());
            throw new \Exception('Failed to fetch template from Postmark: '.$e->getMessage());
        }
    }

    /**
     * Create a new template in Postmark
     */
    public function createTemplate(array $templateData, bool $useProduction = false): array
    {
        try {
            $response = $this->client->post($this->baseUrl.'/templates', [
                'headers' => [
                    'X-Postmark-Server-Token' => $useProduction ? $this->productionToken : $this->stagingToken,
                ],
                'json' => $templateData,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::error('Postmark API Error - Create Template: '.$e->getMessage());
            throw new \Exception('Failed to create template in Postmark: '.$e->getMessage());
        }
    }

    /**
     * Update a template in Postmark
     */
    public function updateTemplate(string $templateId, array $templateData, bool $useProduction = false): array
    {
        try {
            $response = $this->client->put($this->baseUrl.'/templates/'.$templateId, [
                'headers' => [
                    'X-Postmark-Server-Token' => $useProduction ? $this->productionToken : $this->stagingToken,
                ],
                'json' => $templateData,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::error('Postmark API Error - Update Template: '.$e->getMessage());
            throw new \Exception('Failed to update template in Postmark: '.$e->getMessage());
        }
    }

    /**
     * Delete a template from Postmark
     */
    public function deleteTemplate(string $templateId, bool $useProduction = false): bool
    {
        try {
            $this->client->delete($this->baseUrl.'/templates/'.$templateId, [
                'headers' => [
                    'X-Postmark-Server-Token' => $useProduction ? $this->productionToken : $this->stagingToken,
                ],
            ]);

            return true;
        } catch (RequestException $e) {
            Log::error('Postmark API Error - Delete Template: '.$e->getMessage());
            throw new \Exception('Failed to delete template from Postmark: '.$e->getMessage());
        }
    }

    /**
     * Validate a template with test data
     */
    public function validateTemplate(array $templateData, array $testData = []): array
    {
        try {
            $payload = array_merge($templateData, [
                'TestRenderModel' => $testData ?: $this->getDefaultTestData(),
            ]);

            $response = $this->client->post($this->baseUrl.'/templates/validate', [
                'headers' => [
                    'X-Postmark-Server-Token' => $this->stagingToken,
                ],
                'json' => $payload,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::error('Postmark API Error - Validate Template: '.$e->getMessage());
            throw new \Exception('Failed to validate template: '.$e->getMessage());
        }
    }

    /**
     * Send a test email using the template
     */
    public function sendTestEmail(string $templateAlias, string $toEmail, array $templateModel = []): array
    {
        try {
            $response = $this->client->post($this->baseUrl.'/email/withTemplate', [
                'headers' => [
                    'X-Postmark-Server-Token' => $this->stagingToken,
                ],
                'json' => [
                    'TemplateAlias' => $templateAlias,
                    'To' => $toEmail,
                    'From' => config('postmark.from_email', 'test@example.com'),
                    'TemplateModel' => $templateModel ?: $this->getDefaultTestData(),
                    'TrackOpens' => false,
                    'TrackLinks' => 'None',
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::error('Postmark API Error - Send Test Email: '.$e->getMessage());
            throw new \Exception('Failed to send test email: '.$e->getMessage());
        }
    }

    /**
     * Push template from staging to production
     */
    public function pushToProduction(string $templateAlias): array
    {
        try {
            $response = $this->client->put($this->baseUrl.'/templates/push', [
                'headers' => [
                    'X-Postmark-Server-Token' => $this->stagingToken,
                ],
                'json' => [
                    'SourceServerID' => $this->stagingServerId,
                    'DestinationServerID' => $this->productionServerId,
                    'PerformChanges' => true,
                    'TemplateAlias' => $templateAlias,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::error('Postmark API Error - Push to Production: '.$e->getMessage());
            throw new \Exception('Failed to push template to production: '.$e->getMessage());
        }
    }

    /**
     * Get server information
     */
    public function getServerInfo(bool $useProduction = false): array
    {
        try {
            $response = $this->client->get($this->baseUrl.'/server', [
                'headers' => [
                    'X-Postmark-Server-Token' => $useProduction ? $this->productionToken : $this->stagingToken,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::error('Postmark API Error - Get Server Info: '.$e->getMessage());
            throw new \Exception('Failed to get server information: '.$e->getMessage());
        }
    }

    /**
     * Get default test data for template rendering
     */
    private function getDefaultTestData(): array
    {
        return [
            'product_url' => 'https://example.com/product',
            'product_name' => 'Sample Product',
            'name' => 'John Doe',
            'user_name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'company' => 'Example Corp',
            'action_url' => 'https://example.com/action',
            'login_url' => 'https://example.com/login',
            'username' => 'johndoe',
            'trial_length' => '30 days',
            'trial_start_date' => now()->format('F j, Y'),
            'trial_end_date' => now()->addDays(30)->format('F j, Y'),
            'support_email' => 'support@example.com',
            'live_chat_url' => 'https://example.com/chat',
            'sender_name' => 'Example Team',
            'help_url' => 'https://example.com/help',
            'invite_sender_name' => 'John Doe',
            'invite_sender_organization_name' => 'Example Corp',
        ];
    }

    /**
     * Format template data for Postmark API
     */
    public function formatTemplateForApi(array $templateData): array
    {
        return [
            'Name' => $templateData['name'] ?? '',
            'Alias' => $templateData['alias'] ?? '',
            'Subject' => $templateData['subject'] ?? '',
            'HtmlBody' => $templateData['html_body'] ?? '',
            'TextBody' => $templateData['text_body'] ?? '',
            'TemplateType' => $templateData['template_type'] ?? 'Standard',
            'LayoutTemplate' => $templateData['layout_template_alias'] ?? null,
        ];
    }

    /**
     * Check if service is properly configured
     */
    public function isConfigured(): bool
    {
        return ! empty($this->stagingToken) && ! empty($this->stagingServerId);
    }

    /**
     * Test connection to Postmark API
     */
    public function testConnection(bool $useProduction = false): array
    {
        try {
            $serverInfo = $this->getServerInfo($useProduction);

            return [
                'success' => true,
                'server_name' => $serverInfo['Name'] ?? 'Unknown',
                'server_id' => $serverInfo['ID'] ?? 0,
                'color' => $serverInfo['Color'] ?? 'blue',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get email statistics for IT Dashboard
     * Uses Postmark's outbound message statistics API
     */
    public function getUsageStats(): array
    {
        try {
            $useProduction = true; // Get stats from production server

            // Get outbound stats for today
            $today = now()->format('Y-m-d');
            $statsToday = $this->getOutboundStats($today, $today, $useProduction);

            // Get outbound stats for yesterday
            $yesterday = now()->subDay()->format('Y-m-d');
            $statsYesterday = $this->getOutboundStats($yesterday, $yesterday, $useProduction);

            // Get outbound stats for last 7 days
            $last7Days = now()->subDays(7)->format('Y-m-d');
            $statsLast7Days = $this->getOutboundStats($last7Days, $today, $useProduction);

            // Get outbound stats for this month
            $monthStart = now()->startOfMonth()->format('Y-m-d');
            $statsThisMonth = $this->getOutboundStats($monthStart, $today, $useProduction);

            return [
                'emails_today' => $statsToday['Sent'] ?? 0,
                'emails_yesterday' => $statsYesterday['Sent'] ?? 0,
                'emails_last_7_days' => $statsLast7Days['Sent'] ?? 0,
                'emails_this_month' => $statsThisMonth['Sent'] ?? 0,
                'bounces_today' => $statsToday['Bounced'] ?? 0,
                'bounces_this_month' => $statsThisMonth['Bounced'] ?? 0,
                'bounce_rate_today' => $statsToday['Sent'] > 0
                    ? round(($statsToday['Bounced'] / $statsToday['Sent']) * 100, 2)
                    : 0,
                'bounce_rate_month' => $statsThisMonth['Sent'] > 0
                    ? round(($statsThisMonth['Bounced'] / $statsThisMonth['Sent']) * 100, 2)
                    : 0,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to fetch Postmark usage stats', ['error' => $e->getMessage()]);

            return [
                'emails_today' => 0,
                'emails_yesterday' => 0,
                'emails_last_7_days' => 0,
                'emails_this_month' => 0,
                'bounces_today' => 0,
                'bounces_this_month' => 0,
                'bounce_rate_today' => 0,
                'bounce_rate_month' => 0,
            ];
        }
    }

    /**
     * Get outbound message statistics from Postmark
     */
    private function getOutboundStats(string $fromDate, string $toDate, bool $useProduction = false): array
    {
        try {
            $response = $this->client->get($this->baseUrl.'/stats/outbound', [
                'headers' => [
                    'X-Postmark-Server-Token' => $useProduction ? $this->productionToken : $this->stagingToken,
                ],
                'query' => [
                    'fromdate' => $fromDate,
                    'todate' => $toDate,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::error('Postmark API Error - Get Outbound Stats', [
                'from' => $fromDate,
                'to' => $toDate,
                'error' => $e->getMessage(),
            ]);

            return [
                'Sent' => 0,
                'Bounced' => 0,
                'SMTPApiErrors' => 0,
                'BounceRate' => 0,
            ];
        }
    }

    /**
     * Get details of a specific outbound message
     * Note: Postmark only retains message content for ~45 days
     */
    public function getMessageDetails(string $messageId, bool $useProduction = true): array
    {
        try {
            $response = $this->client->get($this->baseUrl.'/messages/outbound/'.$messageId.'/details', [
                'headers' => [
                    'X-Postmark-Server-Token' => $useProduction ? $this->productionToken : $this->stagingToken,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::error('Postmark API Error - Get Message Details', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('Failed to fetch message details: '.$e->getMessage());
        }
    }

    /**
     * Search outbound messages by recipient email
     * Note: Postmark only retains messages for ~45 days
     */
    public function searchOutboundMessages(string $recipientEmail, int $count = 50, bool $useProduction = true): array
    {
        try {
            $response = $this->client->get($this->baseUrl.'/messages/outbound', [
                'headers' => [
                    'X-Postmark-Server-Token' => $useProduction ? $this->productionToken : $this->stagingToken,
                ],
                'query' => [
                    'count' => $count,
                    'offset' => 0,
                    'recipient' => $recipientEmail,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['Messages'] ?? [];
        } catch (RequestException $e) {
            Log::error('Postmark API Error - Search Outbound Messages', [
                'recipient' => $recipientEmail,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get message opens for a specific message
     */
    public function getMessageOpens(string $messageId, bool $useProduction = true): array
    {
        try {
            $response = $this->client->get($this->baseUrl.'/messages/outbound/opens/'.$messageId, [
                'headers' => [
                    'X-Postmark-Server-Token' => $useProduction ? $this->productionToken : $this->stagingToken,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::warning('Postmark API - Could not get message opens', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
