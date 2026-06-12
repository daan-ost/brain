<?php

/**
 * Script to validate all conversion configurations
 * Checks for missing conversion options or workflow presets
 */

// Load Laravel
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Load configurations
$landingPages = config('landing_pages');
$conversionOptions = config('conversion_options.options');

echo "\n=== Checking All Conversion Configurations ===\n\n";

$issues = [];
$totalChecked = 0;

foreach ($landingPages as $slug => $config) {
    // Skip non-conversion entries (like nl_slug_mapping, conversion_families)
    if (! is_array($config) || ! isset($config['conversion_type'])) {
        continue;
    }

    $totalChecked++;
    $conversionType = $config['conversion_type'];
    $hasIssues = false;
    $issueDetails = [];

    // Check 1: Does conversion_options have this conversion type?
    $hasOptions = isset($conversionOptions[$conversionType]);
    $optionsEmpty = $hasOptions && empty($conversionOptions[$conversionType]);

    // Check 2: Does landing page have show_conversion_options flag?
    $showOptionsFlag = $config['show_conversion_options'] ?? false;

    // Check 3: Is workflow_preset set?
    $hasWorkflowPreset = isset($config['workflow_preset']) && ! empty($config['workflow_preset']);

    // Determine if there's a configuration issue
    if ($hasOptions && ! $optionsEmpty && ! $showOptionsFlag) {
        $hasIssues = true;
        $issueDetails[] = "❌ Has conversion options but 'show_conversion_options' is missing/false";
    }

    if ($optionsEmpty && ! $showOptionsFlag) {
        $hasIssues = true;
        $issueDetails[] = "⚠️  Empty conversion options AND no 'show_conversion_options' flag";
    }

    if (! $hasOptions && ! $showOptionsFlag) {
        $hasIssues = true;
        $issueDetails[] = "⚠️  No conversion options defined AND no 'show_conversion_options' flag";
    }

    if (! $hasWorkflowPreset) {
        $hasIssues = true;
        $issueDetails[] = '⚠️  No workflow_preset defined';
    }

    // Report issues
    if ($hasIssues) {
        $issues[$slug] = [
            'conversion_type' => $conversionType,
            'slug' => $slug,
            'has_options' => $hasOptions,
            'options_empty' => $optionsEmpty,
            'show_flag' => $showOptionsFlag,
            'has_workflow' => $hasWorkflowPreset,
            'issues' => $issueDetails,
        ];

        echo "🔴 {$slug} ({$conversionType})\n";
        foreach ($issueDetails as $issue) {
            echo "   {$issue}\n";
        }
        echo "\n";
    } else {
        echo "✅ {$slug} - OK\n";
    }
}

// Summary
echo "\n=== Summary ===\n";
echo "Total conversions checked: {$totalChecked}\n";
echo 'Conversions with issues: '.count($issues)."\n";

if (count($issues) > 0) {
    echo "\n=== Recommended Fixes ===\n\n";

    foreach ($issues as $slug => $data) {
        echo "• {$slug}:\n";

        if ($data['has_options'] && ! $data['options_empty'] && ! $data['show_flag']) {
            echo "  → Add 'show_conversion_options' => true to landing_pages.php\n";
        }

        if (($data['options_empty'] || ! $data['has_options']) && ! $data['show_flag']) {
            echo "  → Either:\n";
            echo "     a) Add conversion options to config/conversion_options.php\n";
            echo "     b) Set 'show_conversion_options' => false (if no options needed)\n";
        }

        if (! $data['has_workflow']) {
            echo "  → Add 'workflow_preset' to landing_pages.php\n";
        }

        echo "\n";
    }
} else {
    echo "\n✨ All conversions are properly configured!\n";
}

echo "\n";
