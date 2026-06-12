<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$landingPages = config('landing_pages');
$defaults = config('workflow_steps.defaults');

echo "\n=== Checking Workflow Presets ===\n\n";

$missing = [];

foreach ($landingPages as $slug => $config) {
    if (! is_array($config) || ! isset($config['workflow_preset'])) {
        continue;
    }

    $preset = $config['workflow_preset'];

    if (! isset($defaults[$preset])) {
        $missing[] = [
            'slug' => $slug,
            'preset' => $preset,
            'conversion_type' => $config['conversion_type'] ?? 'unknown',
        ];
        echo "❌ {$slug}: Missing workflow preset '{$preset}'\n";
    } else {
        echo "✅ {$slug}: '{$preset}' exists\n";
    }
}

echo "\n=== Summary ===\n";
echo 'Total landing pages checked: '.count(array_filter($landingPages, fn ($v) => is_array($v) && isset($v['workflow_preset'])))."\n";
echo 'Missing workflow presets: '.count($missing)."\n";

if (count($missing) > 0) {
    echo "\n=== Missing Presets ===\n";
    foreach ($missing as $item) {
        echo "• {$item['preset']} (used by {$item['slug']})\n";
    }
}

echo "\n";
