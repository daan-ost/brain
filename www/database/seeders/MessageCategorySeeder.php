<?php

namespace Database\Seeders;

use App\Models\MessageCategory;
use Illuminate\Database\Seeder;

class MessageCategorySeeder extends Seeder
{
    /**
     * Seed the message categories table with base categories
     */
    public function run(): void
    {
        $this->command->info('Seeding message categories...');

        $categories = [
            [
                'slug' => 'conversion-feedback',
                'name_en' => 'Conversion feedback',
                'name_nl' => 'Conversie feedback',
                'order' => 1,
                'is_visible' => true,
                'settings_json' => [
                    'icon' => 'heroicon-o-document-text',
                    'color' => '#4FA3EB',
                    'llm_allowed' => true,
                    'auto_assign_admin' => null,
                    'default_status' => 'open',
                    'template_messages' => [
                        'en' => [
                            'Thank you for your feedback!',
                            'Could you provide more details about what went wrong?',
                        ],
                        'nl' => [
                            'Bedankt voor uw feedback!',
                            'Kunt u meer details geven over wat er mis ging?',
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'bug',
                'name_en' => 'Bug report',
                'name_nl' => 'Bug melden',
                'order' => 2,
                'is_visible' => true,
                'settings_json' => [
                    'icon' => 'heroicon-o-bug-ant',
                    'color' => '#E74C3C',
                    'llm_allowed' => true,
                    'auto_assign_admin' => null,
                    'default_status' => 'open',
                    'priority' => 'high',
                    'template_messages' => [
                        'en' => [
                            'Could you describe what you were trying to do?',
                            'Could you add a screenshot of the error message?',
                        ],
                        'nl' => [
                            'Kunt u aangeven wat u precies probeerde te doen?',
                            'Kunt u een screenshot toevoegen van de foutmelding?',
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'support',
                'name_en' => 'Support',
                'name_nl' => 'Ondersteuning',
                'order' => 3,
                'is_visible' => true,
                'settings_json' => [
                    'icon' => 'heroicon-o-question-mark-circle',
                    'color' => '#3498DB',
                    'llm_allowed' => true,
                    'auto_assign_admin' => null,
                    'default_status' => 'open',
                    'template_messages' => [
                        'en' => [
                            'How can we help you today?',
                            'Thank you for contacting support!',
                        ],
                        'nl' => [
                            'Hoe kunnen we u vandaag helpen?',
                            'Bedankt voor het contact met de helpdesk!',
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'sales',
                'name_en' => 'Sales',
                'name_nl' => 'Verkoop',
                'order' => 4,
                'is_visible' => true,
                'settings_json' => [
                    'icon' => 'heroicon-o-currency-euro',
                    'color' => '#27AE60',
                    'llm_allowed' => false,
                    'auto_assign_admin' => null,
                    'default_status' => 'open',
                    'template_messages' => [
                        'en' => [
                            'Thank you for your interest!',
                            'We will get back to you shortly.',
                        ],
                        'nl' => [
                            'Bedankt voor uw interesse!',
                            'We nemen zo snel mogelijk contact met u op.',
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'pricing',
                'name_en' => 'Pricing',
                'name_nl' => 'Prijzen',
                'order' => 5,
                'is_visible' => true,
                'settings_json' => [
                    'icon' => 'heroicon-o-banknotes',
                    'color' => '#9B59B6',
                    'llm_allowed' => false,
                    'auto_assign_admin' => null,
                    'default_status' => 'open',
                    'template_messages' => [
                        'en' => [
                            'Thank you for your pricing inquiry!',
                            'You can find our current pricing at /pricing.',
                        ],
                        'nl' => [
                            'Bedankt voor uw vraag over prijzen!',
                            'U vindt onze huidige prijzen op /prijzen.',
                        ],
                    ],
                ],
            ],
        ];

        foreach ($categories as $categoryData) {
            MessageCategory::updateOrCreate(
                ['slug' => $categoryData['slug']],
                $categoryData
            );
        }

        $this->command->info('Done: '.count($categories).' message categories seeded');
    }
}
