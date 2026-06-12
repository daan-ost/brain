<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Transaction Debug Check ===\n\n";

$user = \App\Models\User::latest()->first();

if (! $user) {
    echo "No users found!\n";
    exit;
}

echo "User: {$user->name} (ID: {$user->id})\n";
echo "Email: {$user->email}\n\n";

$license = $user->getCurrentLicense();
echo 'License: '.($license ? $license->tier : 'none')."\n";
$isFreeUser = ! $license || $license->tier === 'free';
echo 'Is free user: '.($isFreeUser ? 'YES' : 'NO')."\n\n";

$batch = $user->batches()->latest()->first();

if (! $batch) {
    echo "No batches found for this user!\n";
    exit;
}

echo "Latest Batch ID: {$batch->id}\n";
echo "Status: {$batch->status}\n";
echo "Expires at: {$batch->expires_at}\n";

$isAvailable = $batch->status === 'done' && (! $batch->expires_at || $batch->expires_at->isFuture());
echo 'Is available: '.($isAvailable ? 'YES' : 'NO')."\n\n";

if ($isAvailable) {
    echo "--- Actions that should be visible ---\n";
    echo "1. Download: YES\n";
    echo "2. Share: YES\n";

    // Check Next Step
    $hasNextSteps = false;
    if ($batch->canHaveNextStep()) {
        $resolver = app(\App\Services\NextStepResolver::class);
        $availableSteps = $resolver->getAvailableNextSteps($batch);
        $hasNextSteps = ! empty($availableSteps);
    }
    echo '3. Next Step: '.($hasNextSteps ? 'YES' : 'NO (disabled)')."\n";

    // Check Delete
    echo '4. Delete: YES';
    if ($isFreeUser) {
        echo ' (shows upgrade modal for free users)';
    } else {
        echo ' (allows direct deletion)';
    }
    echo "\n";
} else {
    echo "Actions dropdown should NOT be visible (batch not available)\n";
}
