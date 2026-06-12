<?php

return [
    // Page title
    'page_title' => 'Checkout',

    // Stepper
    'step_product_selection' => 'Product Selection',
    'step_secure_checkout' => 'Secure Checkout',
    'step_activation' => 'Activation',

    // Order Summary
    'order_summary' => 'Order Summary',
    'license' => 'License',
    'license_type' => 'License type',
    'license_type_onetime' => 'One-time',
    'license_type_recurring' => 'Recurring',
    'credits' => 'Credits',
    'valid_for' => 'Valid for',
    'end_date' => 'End date',
    'billing_cycle' => 'Billing Cycle',
    'renewal_date' => 'Contract renewal date',
    'billed_yearly' => 'Billed yearly',
    'billed_monthly' => 'Billed monthly',
    'months' => 'months',
    'month' => 'month',
    'year' => 'year',
    'years' => 'years',

    // Pricing
    'subtotal_excl_vat' => 'Subtotal (excl. VAT)',
    'vat' => 'VAT (:rate%)',
    'total_incl_vat' => 'Total (incl. VAT)',
    'total_excl_vat' => 'Total (excl. VAT)',
    'vat_reverse_charge_note' => 'Note: As an EU business with a valid VAT ID, reverse charge applies. You will account for VAT in your own country.',

    // Who is purchasing
    'who_is_purchasing' => 'Who is purchasing?',
    'personal_purchase' => 'Personal Purchase',
    'buy_for_yourself' => 'Buy for yourself',
    'organization_purchase' => 'Organization Purchase',
    'buy_for_organization' => 'Buy for your organization',
    'invoice_payment_organization_only' => 'Invoice payment - organization purchase',
    'select_organization_for_invoice' => 'Select the organization that will receive the invoice:',
    'no_organizations_for_invoice' => 'Invoice payment is only available for organizations. Please create an organization first or choose a different payment method.',

    // Billing Information
    'billing_information' => 'Billing Information',
    'country' => 'Country',
    'buyer_type' => 'Buyer Type',
    'individual' => 'Individual',
    'company' => 'Company',
    'email_address' => 'Invoice email address',
    'email_address_hint' => 'The invoice will be sent to this email address.',
    'full_name' => 'Full name',
    'company_name' => 'Company name',
    'company_registration_number' => 'Company registration number',
    'internal_reference' => 'Internal reference',
    'street_address' => 'Street address',
    'postal_code' => 'Postal code',
    'city' => 'City',
    'state_province' => 'State/Province',
    'state_placeholder' => 'e.g. California, New York, Ontario',
    'vat_number' => 'VAT number',
    'vat_number_optional' => 'VAT number (optional)',
    'vat_id' => 'VAT ID',
    'edit_in_organization_settings' => 'Edit in organization settings',

    // Payment Method
    'payment_method' => 'Payment Method',
    'online_payment' => 'Online Payment',
    'online_payment_description' => 'Pay with credit card, iDEAL, or other methods',
    'invoice_payment' => 'Invoice Payment',
    'invoice_payment_description' => 'Receive an invoice (only for organizations)',
    'pay_by_invoice' => 'Pay by Invoice',
    'pay_by_invoice_description' => 'Your license will be activated after payment. The invoice will be emailed and can also be downloaded from your profile.',
    'trusted_invoice_title' => 'Immediate Activation',
    'trusted_invoice_description' => 'Your license will be activated immediately. An invoice will be created and emailed. You can also download it from your account.',
    'trusted_confirm_activation' => 'Your license will be activated immediately and an invoice will be generated. Continue?',
    'activate_license' => 'Activate License',

    // Buttons
    'continue_to_payment' => 'Continue to Payment',
    'complete_payment' => 'Complete Payment',
    'submit_license_request' => 'Submit License Request',
    'back_to_pricing' => 'Back to Pricing',
    'back' => 'Back',
    'processing' => 'Processing...',

    // Validation
    'field_required' => 'This field is required',
    'invalid_email' => 'Please enter a valid email address',
    'invalid_vat' => 'Please enter a valid VAT number',
    'valid_vat_no_charge' => 'Valid VAT ID - No VAT will be charged',

    // Validation messages
    'validation' => [
        'email_required' => 'Email address is required.',
        'email_invalid' => 'Please enter a valid email address.',
        'full_name_required' => 'Full name is required.',
        'company_name_required' => 'Company name is required.',
        'street_required' => 'Street address is required.',
        'postal_code_required' => 'Postal code is required.',
        'city_required' => 'City is required.',
        'state_required' => 'State/Province is required.',
    ],

    // One-time credits
    'onetime_credits' => 'One-time :count credits (:months months)',

    // Invoice/Activation page
    'license_request_submitted' => 'Invoice Generated!',
    'license_request_submitted_description' => 'Your invoice has been generated. Once payment is received, your license will be activated and credits will be added to your account.',
    'invoice_details' => 'Invoice Details',
    'invoice_number' => 'Invoice Number',
    'status' => 'Status',
    'pending_review' => 'Pending Payment',
    'what_happens_next' => 'What happens next?',
    'next_step_1' => 'Download the invoice using the button below',
    'next_step_2' => 'Process the payment according to the invoice instructions',
    'next_step_3' => 'Once payment is received, your license will be automatically activated',
    'next_step_4' => 'Credits will be added to your account and you\'ll have full access',
    'email_confirmation' => 'You\'ll receive an email confirmation with these details.',
    'view_license_status' => 'View License Status',
    'continue_with_free_account' => 'Continue with Free Account',

    // Success page
    'payment_successful' => 'Payment Successful!',
    'payment_successful_message' => 'Payment successful! Your license has been activated.',
    'license_activated_with_credits' => 'License activated successfully! Invoice has been generated. :credits credits have been added to your account.',
    'license_added' => 'License Added!',
    'license_activated_check' => 'License successfully activated',
    'invoice_generated_check' => 'Invoice has been generated',
    'credits_added_check' => ':credits credits have been added to your account',
    'pay_invoice_todo' => 'Pay your invoice',
    'order_id' => 'Order ID',
    'amount' => 'Amount',
    'credits_added' => 'Credits Added',
    'valid_until' => 'Valid Until',
    'subscription' => 'Subscription',
    'annual_auto_renewal' => 'Annual (Auto-renewal)',
    'next_billing' => 'Next Billing',
    'start_converting_files' => 'Start Converting Files',
    'view_all_plans' => 'View All Plans',
    'download_invoice' => 'Download Invoice',

    // Error/pending states
    'payment_failed' => 'Payment Failed',
    'payment_failed_message' => 'Payment was not completed. Please try again.',
    'activation_status_unknown_message' => 'Unable to determine activation status.',
    'processing_payment' => 'Processing your payment...',
    'processing_payment_message' => 'Please wait while we confirm your payment with our secure payment provider. This usually takes just a few seconds.',
    'order_status_initiated' => 'Order status: initiated',
    'check_status' => 'Check Status',
    'continue_to_dashboard' => 'Continue to Dashboard',
    'try_again' => 'Try Again',
    'choose_different_plan' => 'Choose Different Plan',
    'activation_status_unknown' => 'Activation Status Unknown',
    'view_pricing' => 'View Pricing',
    'go_to_dashboard' => 'Go to Dashboard',

    // Authentication and verification
    'login_required' => 'Please log in to continue with your purchase.',
    'verification_required' => 'Please verify your email address before making a purchase.',

    // Error messages
    'errors' => [
        'license_not_found' => 'The selected license is no longer available.',
        'organization_not_found' => 'Organization not found.',
        'admin_required' => 'Only organization admins can make payments for the organization.',
        'payment_failed' => 'Payment creation failed. Please try again.',
    ],
];
