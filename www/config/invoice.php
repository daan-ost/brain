<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invoice Company Information
    |--------------------------------------------------------------------------
    |
    | These values are used to populate the company/seller information on
    | generated invoices. They should be configured in your .env file.
    |
    */

    'company_name' => env('INVOICE_COMPANY_NAME', 'Your Company'),
    'company_legal_name' => env('INVOICE_COMPANY_LEGAL_NAME', ''), // Legal entity name (e.g., "Interus")
    'company_address' => env('INVOICE_COMPANY_ADDRESS', ''),
    'company_postal_code' => env('INVOICE_COMPANY_POSTAL_CODE', ''),
    'company_city' => env('INVOICE_COMPANY_CITY', ''),
    'company_country' => env('INVOICE_COMPANY_COUNTRY', 'The Netherlands'),
    'company_vat_id' => env('INVOICE_COMPANY_VAT_ID', ''),
    'company_coc' => env('INVOICE_COMPANY_COC', ''),
    'company_email' => env('INVOICE_COMPANY_EMAIL', ''),
    'company_phone' => env('INVOICE_COMPANY_PHONE', ''),
    'company_website' => env('INVOICE_COMPANY_WEBSITE', ''),
    'company_iban' => env('INVOICE_COMPANY_IBAN', ''),
    'company_bic' => env('INVOICE_COMPANY_BIC', ''),
    'company_bank_name' => env('INVOICE_COMPANY_BANK_NAME', ''), // e.g., "ABN AMRO Oosterbeek"

    /*
    |--------------------------------------------------------------------------
    | Invoice Settings
    |--------------------------------------------------------------------------
    */

    'default_due_days' => env('INVOICE_DEFAULT_DUE_DAYS', 14),

];
