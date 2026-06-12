<?php

namespace Database\Seeders;

use App\Models\Workflow;
use Illuminate\Database\Seeder;

class SystemWorkflowsSeeder extends Seeder
{
    /**
     * Seed system workflows from workflow_steps.php presets.
     *
     * This creates Workflow records with user_id = null for all
     * workflow presets defined in config/workflow_steps.php
     */
    public function run(): void
    {
        $this->command->info('Seeding system workflows from workflow presets...');

        $presets = config('workflow_steps.defaults', []);

        if (empty($presets)) {
            $this->command->warn('No workflow presets found in config/workflow_steps.php');

            return;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($presets as $presetKey => $presetConfig) {
            // Skip presets that don't have __download suffix (these are usually internal defaults)
            // But we can include them too if needed - let's include all for now
            if (! isset($presetConfig['name']) || ! isset($presetConfig['steps'])) {
                $this->command->warn("Skipping preset '{$presetKey}': missing name or steps");
                $skipped++;

                continue;
            }

            $workflowName = $presetConfig['name'];
            $steps = $presetConfig['steps'] ?? [];

            // Check if system workflow with this name already exists
            $existingWorkflow = Workflow::whereNull('user_id')
                ->where('name', $workflowName)
                ->first();

            if ($existingWorkflow) {
                // Update existing workflow if steps have changed
                $stepsHash = md5(json_encode($steps));
                $existingStepsHash = md5(json_encode($existingWorkflow->steps));

                if ($stepsHash !== $existingStepsHash) {
                    $existingWorkflow->update([
                        'steps_json' => $steps,
                        'status' => 'active',
                    ]);
                    $updated++;
                    $this->command->info("Updated system workflow: {$workflowName}");
                } else {
                    $skipped++;
                    $this->command->line("Skipped (unchanged): {$workflowName}");
                }
            } else {
                // Create new system workflow
                Workflow::create([
                    'user_id' => null, // System workflow
                    'name' => $workflowName,
                    'steps_json' => $steps,
                    'is_default' => false,
                    'status' => 'active',
                    'output_type' => $this->determineOutputType($steps),
                    'delivery_method' => 'download',
                    'credits_per_document' => $this->determineCreditsPerDocument($steps),
                ]);
                $created++;
                $this->command->info("Created system workflow: {$workflowName}");
            }
        }

        $this->command->info('System workflows seeding complete!');
        $this->command->info("  - Created: {$created}");
        $this->command->info("  - Updated: {$updated}");
        $this->command->info("  - Skipped: {$skipped}");
        $this->command->info('  - Total: '.($created + $updated + $skipped));
    }

    /**
     * Determine output type from workflow steps
     */
    private function determineOutputType(array $steps): string
    {
        if (empty($steps)) {
            return 'single';
        }

        // Check if last step is zip_output
        $lastStep = end($steps);
        if (isset($lastStep['type']) && $lastStep['type'] === 'zip_output') {
            return 'zip';
        }

        // Check if workflow has multiple outputs (split, etc.)
        foreach ($steps as $step) {
            if (isset($step['type']) && $step['type'] === 'pdf_to_split') {
                return 'zip'; // Split typically outputs ZIP
            }
        }

        return 'single';
    }

    /**
     * Determine credits per document from workflow steps
     */
    private function determineCreditsPerDocument(array $steps): int
    {
        if (empty($steps)) {
            return 1;
        }

        // Get first step config to determine credits
        $firstStep = $steps[0] ?? null;
        if (! $firstStep || ! isset($firstStep['type'])) {
            return 1;
        }

        $stepType = $firstStep['type'];
        $stepConfig = config("workflow_steps.steps.{$stepType}", []);

        return $stepConfig['credits_per_document'] ?? 1;
    }
}
