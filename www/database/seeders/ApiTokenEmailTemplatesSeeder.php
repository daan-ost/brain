<?php

namespace Database\Seeders;

use App\Models\PostmarkTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeder for API Token Email Templates
 *
 * Creates Postmark templates for API token creation and revocation notifications.
 * These templates are used to send security alerts to users when tokens are created or revoked.
 */
class ApiTokenEmailTemplatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'API Token Updated (NL)',
                'alias' => 'api-token-updated__nl',
                'subject' => 'API Token {{action}} - Beveiligingswaarschuwing',
                'html_body' => file_get_contents(resource_path('postmark-templates/api-token-updated-nl.html')),
                'text_body' => $this->generateTextBody('nl'),
                'template_type' => 'Standard',
                'active' => true,
            ],
            [
                'name' => 'API Token Updated (EN)',
                'alias' => 'api-token-updated__en',
                'subject' => 'API Token {{action}} - Security Alert',
                'html_body' => file_get_contents(resource_path('postmark-templates/api-token-updated-en.html')),
                'text_body' => $this->generateTextBody('en'),
                'template_type' => 'Standard',
                'active' => true,
            ],
        ];

        foreach ($templates as $templateData) {
            PostmarkTemplate::updateOrCreate(
                ['alias' => $templateData['alias']],
                $templateData
            );
        }

        $this->command->info('API Token email templates seeded successfully.');
    }

    /**
     * Generate plain text body for email template
     *
     * @param string $locale The locale (nl or en)
     * @return string Plain text email body
     */
    private function generateTextBody(string $locale): string
    {
        if ($locale === 'nl') {
            return <<<'TEXT'
API Token {{action}}

Beste {{user_name}},

Er is zojuist een API token {{action}} in uw account.

Details:
- Token naam: {{token_name}}
- Actie: {{action}}
- Datum/tijd: {{action_datetime}}
- IP-adres: {{ip_address}}

⚠️ BEVEILIGINGSWAARSCHUWING
Als u deze actie niet zelf heeft uitgevoerd, kan uw account gecompromitteerd zijn.
Neem dan onmiddellijk contact op met onze support.

Deze email is automatisch verstuurd om u op de hoogte te houden van beveiligingsgevoelige acties op uw account.

Hulp nodig?
Neem contact op met ons support team: {{support_email}}
TEXT;
        } else {
            return <<<'TEXT'
API Token {{action}}

Dear {{user_name}},

An API token has just been {{action}} in your account.

Details:
- Token name: {{token_name}}
- Action: {{action}}
- Date/time: {{action_datetime}}
- IP address: {{ip_address}}

⚠️ SECURITY WARNING
If you did not perform this action yourself, your account may be compromised.
Please contact our support team immediately.

This email was sent automatically to keep you informed about security-sensitive actions on your account.

Need help?
Contact our support team: {{support_email}}
TEXT;
        }
    }
}
