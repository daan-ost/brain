<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credits System Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration defines the credits system rules for the PDF Engine.
    | It controls how credits are charged, which actions are free, and
    | organization selection logic for payment.
    |
    */

    'default_credits_per_document' => 1,

    /*
    |--------------------------------------------------------------------------
    | Non-Charging Actions
    |--------------------------------------------------------------------------
    |
    | These workflow steps do NOT consume credits when they are the only
    | steps in a workflow. If any charging action is present in the workflow,
    | the entire workflow is charged.
    |
    | Note: Currently no flatten or OCR-only steps exist, but this list
    | is prepared for future non-charging operations.
    |
    */

    'non_charging_actions' => [
        'ocr',
        'flatten',
        'metadata_only',
    ],

    /*
    |--------------------------------------------------------------------------
    | Merge Operations (Fixed Credit Cost)
    |--------------------------------------------------------------------------
    |
    | These workflow steps process multiple inputs in a single ConvertAPI call,
    | so they have a fixed credit cost regardless of input document count.
    | Unlike convert operations which charge per document.
    |
    */

    'merge_operations' => [
        'merge_pdfs',       // Multiple PDFs → Single PDF (1 API call)
        'images_to_pdf',     // Multiple images → Single PDF (1 API call)
    ],

    /*
    |--------------------------------------------------------------------------
    | Organization Selection Rules
    |--------------------------------------------------------------------------
    |
    | Rules for selecting which organization or user account to charge.
    |
    */

    'organization_selection' => [
        // Sort organizations by joined_at (earliest first), then by organization_id
        'sort_criteria' => ['joined_at' => 'asc', 'id' => 'asc'],

        // Only consider organizations with positive balance
        'minimum_balance' => 1,

        // No mixing in POC5A - either org OR user pays, never both
        'allow_mixing' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Credit Charging Rules
    |--------------------------------------------------------------------------
    |
    | Rules for when and how to charge credits.
    |
    */

    'charging_rules' => [
        // Charge once per document processed
        'charge_per_document' => true,

        // Block execution if insufficient credits
        'block_on_insufficient_credits' => true,

        // Credit calculation: credits_per_document × documents_count
        'calculation_method' => 'per_document',
    ],

];
