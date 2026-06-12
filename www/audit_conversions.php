<?php

/**
 * ConvertAPI Parameter Audit Script
 * Checks all conversions for common issues found in PDF-to-CSV debugging
 */

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$config = config('conversion_options');
$options = $config['options'] ?? [];
// Mappings are nested under 'convertapi' key
$mappings = $config['provider_mappings']['convertapi'] ?? [];

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║     AUDIT RAPPORT: ConvertAPI Parameter Validatie            ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// 1. Check hidden options with mappings (pointless - option is hidden but still mapped)
echo "┌─────────────────────────────────────────────────────────────┐\n";
echo "│ 1. VERBORGEN OPTIES MET PROVIDER MAPPING                    │\n";
echo "│    (zinloos: optie verborgen maar toch gemapped)            │\n";
echo "└─────────────────────────────────────────────────────────────┘\n";
$issues1 = [];
foreach ($options as $convType => $convOptions) {
    foreach ($convOptions as $optKey => $opt) {
        if (isset($opt['show_in_frontend']) && $opt['show_in_frontend'] === false) {
            if (isset($mappings[$convType][$optKey])) {
                $issues1[] = "  ⚠️  {$convType}.{$optKey} -> ".$mappings[$convType][$optKey];
            }
        }
    }
}
if (count($issues1) > 0) {
    echo implode("\n", $issues1)."\n";
} else {
    echo "  ✅ Geen issues gevonden\n";
}
echo "\n";

// 2. Check boolean options - might need string transformation
echo "┌─────────────────────────────────────────────────────────────┐\n";
echo "│ 2. BOOLEAN OPTIES MET MAPPING                               │\n";
echo "│    (check of ConvertAPI string verwacht ipv bool)           │\n";
echo "└─────────────────────────────────────────────────────────────┘\n";
$booleans = [];
foreach ($options as $convType => $convOptions) {
    foreach ($convOptions as $optKey => $opt) {
        if (($opt['data_type'] ?? '') === 'boolean' && isset($mappings[$convType][$optKey])) {
            $apiParam = $mappings[$convType][$optKey];
            $booleans[] = "  ⚠️  {$convType}.{$optKey} -> {$apiParam}";
        }
    }
}
if (count($booleans) > 0) {
    echo implode("\n", $booleans)."\n";
    echo "\n  👆 Controleer deze in ConvertAPI docs - verwachten ze true/false of string?\n";
} else {
    echo "  ✅ Geen boolean mappings gevonden\n";
}
echo "\n";

// 3. Check visible options WITHOUT mapping (will be ignored by ConvertAPI)
echo "┌─────────────────────────────────────────────────────────────┐\n";
echo "│ 3. ZICHTBARE OPTIES ZONDER MAPPING                          │\n";
echo "│    (KRITIEK: gebruiker ziet optie maar wordt genegeerd!)    │\n";
echo "└─────────────────────────────────────────────────────────────┘\n";
$noMapping = [];
foreach ($options as $convType => $convOptions) {
    foreach ($convOptions as $optKey => $opt) {
        $isVisible = ! isset($opt['show_in_frontend']) || $opt['show_in_frontend'] !== false;
        if ($isVisible && ! isset($mappings[$convType][$optKey])) {
            $noMapping[] = "  ❌ {$convType}.{$optKey}";
        }
    }
}
if (count($noMapping) > 0) {
    echo implode("\n", $noMapping)."\n";
    echo "\n  👆 ACTIE VEREIST: Voeg mapping toe of verberg optie!\n";
} else {
    echo "  ✅ Alle zichtbare opties hebben mapping\n";
}
echo "\n";

// 4. Check for conversions with options but NO mappings at all
echo "┌─────────────────────────────────────────────────────────────┐\n";
echo "│ 4. CONVERSIES MET OPTIES MAAR GEEN MAPPINGS                 │\n";
echo "└─────────────────────────────────────────────────────────────┘\n";
$noMappingsAtAll = [];
foreach ($options as $convType => $convOptions) {
    if (! isset($mappings[$convType]) || empty($mappings[$convType])) {
        $visibleCount = 0;
        foreach ($convOptions as $opt) {
            if (! isset($opt['show_in_frontend']) || $opt['show_in_frontend'] !== false) {
                $visibleCount++;
            }
        }
        if ($visibleCount > 0) {
            $noMappingsAtAll[] = "  ❌ {$convType} ({$visibleCount} zichtbare opties, 0 mappings)";
        }
    }
}
if (count($noMappingsAtAll) > 0) {
    echo implode("\n", $noMappingsAtAll)."\n";
} else {
    echo "  ✅ Alle conversies met opties hebben mappings\n";
}
echo "\n";

// 5. Statistics
echo "┌─────────────────────────────────────────────────────────────┐\n";
echo "│ 5. STATISTIEKEN                                             │\n";
echo "└─────────────────────────────────────────────────────────────┘\n";
$totalOpts = 0;
$totalVisible = 0;
$totalHidden = 0;
foreach ($options as $convType => $convOptions) {
    $totalOpts += count($convOptions);
    foreach ($convOptions as $opt) {
        if (! isset($opt['show_in_frontend']) || $opt['show_in_frontend'] !== false) {
            $totalVisible++;
        } else {
            $totalHidden++;
        }
    }
}
echo '  Conversie types met opties: '.count($options)."\n";
echo '  Provider mapping configs:   '.count($mappings)."\n";
echo "  Totaal opties:              {$totalOpts}\n";
echo "  - Zichtbaar:                {$totalVisible}\n";
echo "  - Verborgen:                {$totalHidden}\n";
echo "\n";

// Summary
$totalIssues = count($issues1) + count($noMapping) + count($noMappingsAtAll);
echo "╔══════════════════════════════════════════════════════════════╗\n";
if ($totalIssues > 0) {
    echo "║  ⚠️  TOTAAL: {$totalIssues} potentiële issues gevonden                     ║\n";
} else {
    echo "║  ✅ GEEN KRITIEKE ISSUES GEVONDEN                            ║\n";
}
echo '║  Boolean mappings te checken: '.count($booleans)."                              ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
