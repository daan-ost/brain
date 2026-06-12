<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Job Type Mapping
    |--------------------------------------------------------------------------
    |
    | Map job class names to human-readable names and badge colors.
    | Badge colors: primary, secondary, success, info, warning, danger
    |
    */

    'mapping' => [
        // Conversion Jobs (Green/Success)
        'App\\Jobs\\ConvertDocxToPdf' => [
            'name' => 'Word → PDF',
            'badge' => 'success',
        ],
        'App\\Jobs\\GenericConvertToPdfJob' => [
            'name' => 'PDF Conversie',
            'badge' => 'success',
        ],
        'App\\Jobs\\ConvertImagesToPdf' => [
            'name' => 'Afbeeldingen → PDF',
            'badge' => 'success',
        ],

        // Email Jobs (Blue/Info)
        'App\\Jobs\\ConvertEmailToPdfJob' => [
            'name' => 'Email → PDF',
            'badge' => 'info',
        ],
        'App\\Jobs\\SendInvoiceEmail' => [
            'name' => 'Factuur Email',
            'badge' => 'info',
        ],
        'App\\Jobs\\SendPostmarkTemplateEmail' => [
            'name' => 'Template Email',
            'badge' => 'info',
        ],

        // Workflow Jobs (Purple/Primary)
        'App\\Jobs\\ProcessWorkflowExecutionJob' => [
            'name' => 'Workflow Uitvoering',
            'badge' => 'primary',
        ],

        // Merge Jobs (Teal/Success variant)
        'App\\Jobs\\GenericMergeJob' => [
            'name' => 'Bestanden Samenvoegen',
            'badge' => 'success',
        ],

        // System Jobs (Orange/Warning)
        'App\\Jobs\\CoverPageJob' => [
            'name' => 'Voorpagina Toevoegen',
            'badge' => 'warning',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Badge Color
    |--------------------------------------------------------------------------
    |
    | Fallback color for unknown job types
    |
    */

    'default_badge' => 'secondary',
];
