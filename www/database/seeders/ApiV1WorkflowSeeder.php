<?php

namespace Database\Seeders;

use App\Models\Workflow;
use Illuminate\Database\Seeder;

/**
 * Seeder for API v1 system workflows.
 *
 * These workflows are required for the API v1 compatibility layer.
 * Run with: php artisan db:seed --class=ApiV1WorkflowSeeder
 */
class ApiV1WorkflowSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $workflows = [
            [
                'name' => 'PDF Passthrough (No Conversion) (API v1)',
                'steps_json' => [
                    [
                        'type' => 'copy_files',
                        'options' => [],
                        'action_type' => 'passthrough',
                    ],
                ],
                'status' => 'active',
                'credits_per_document' => 1,
                'is_default' => true,
            ],
            [
                'name' => 'Merge PDFs (API v1)',
                'steps_json' => [
                    [
                        'type' => 'merge_pdfs',
                        'options' => [],
                    ],
                ],
                'status' => 'active',
                'credits_per_document' => 1,
                'is_default' => true,
            ],
        ];

        foreach ($workflows as $workflowData) {
            Workflow::updateOrCreate(
                ['name' => $workflowData['name']],
                $workflowData
            );
        }

        $this->command->info('API v1 workflows seeded successfully.');
    }
}
