<?php

return [
    // Page title
    'page_title' => 'Afrekenen',

    // Stepper
    'step_product_selection' => 'Productselectie',
    'step_secure_checkout' => 'Veilig afrekenen',
    'step_activation' => 'Activatie',

    // Order Summary
    'order_summary' => 'Bestellingoverzicht',
    'license' => 'Licentie',
    'license_type' => 'Licentie type',
    'license_type_onetime' => 'Eenmalig',
    'license_type_recurring' => 'Herhalend',
    'credits' => 'Credits',
    'valid_for' => 'Geldig voor',
    'end_date' => 'Einddatum',
    'billing_cycle' => 'Factureringscyclus',
    'renewal_date' => 'Contract verlengingsdatum',
    'billed_yearly' => 'Jaarlijks gefactureerd',
    'billed_monthly' => 'Maandelijks gefactureerd',
    'months' => 'maanden',
    'month' => 'maand',
    'year' => 'jaar',
    'years' => 'jaar',

    // Pricing
    'subtotal_excl_vat' => 'Subtotaal (excl. BTW)',
    'vat' => 'BTW (:rate%)',
    'total_incl_vat' => 'Totaal (incl. BTW)',
    'total_excl_vat' => 'Totaal (excl. BTW)',
    'vat_reverse_charge_note' => 'Let op: Als EU-bedrijf met geldig BTW-ID is de verleggingsregeling van toepassing. U bent verantwoordelijk voor BTW in uw eigen land.',

    // Who is purchasing
    'who_is_purchasing' => 'Wie koopt dit?',
    'personal_purchase' => 'Persoonlijke aankoop',
    'buy_for_yourself' => 'Koop voor jezelf',
    'organization_purchase' => 'Organisatieaankoop',
    'buy_for_organization' => 'Koop voor je organisatie',
    'invoice_payment_organization_only' => 'Betalen per factuur - organisatieaankoop',
    'select_organization_for_invoice' => 'Selecteer de organisatie die de factuur ontvangt:',
    'no_organizations_for_invoice' => 'Betalen per factuur is alleen beschikbaar voor organisaties. Maak eerst een organisatie aan of kies een andere betaalmethode.',

    // Billing Information
    'billing_information' => 'Factuurgegevens',
    'country' => 'Land',
    'buyer_type' => 'Type koper',
    'individual' => 'Particulier',
    'company' => 'Bedrijf',
    'email_address' => 'E-mailadres voor factuur',
    'email_address_hint' => 'De factuur wordt naar dit e-mailadres gestuurd.',
    'full_name' => 'Volledige naam',
    'company_name' => 'Bedrijfsnaam',
    'company_registration_number' => 'KvK-nummer',
    'internal_reference' => 'Interne referentie',
    'street_address' => 'Straatnaam en huisnummer',
    'postal_code' => 'Postcode',
    'city' => 'Plaats',
    'state_province' => 'Staat/Provincie',
    'state_placeholder' => 'bijv. California, New York, Ontario',
    'vat_number' => 'BTW-nummer',
    'vat_number_optional' => 'BTW-nummer (optioneel)',
    'vat_id' => 'BTW-ID',
    'edit_in_organization_settings' => 'Wijzig in organisatie-instellingen',

    // Payment Method
    'payment_method' => 'Betaalmethode',
    'online_payment' => 'Online betaling',
    'online_payment_description' => 'Betaal met creditcard, iDEAL, of andere methoden',
    'invoice_payment' => 'Factuurbetaling',
    'invoice_payment_description' => 'Ontvang een factuur (alleen voor organisaties)',
    'pay_by_invoice' => 'Betalen per factuur',
    'pay_by_invoice_description' => 'Uw licentie wordt geactiveerd na betaling. De factuur wordt gemaild en is ook te downloaden in je profiel.',
    'trusted_invoice_title' => 'Directe activatie',
    'trusted_invoice_description' => 'Uw licentie wordt direct geactiveerd. Een factuur wordt aangemaakt en gemaild. Deze is ook te downloaden in uw account.',
    'trusted_confirm_activation' => 'Uw licentie wordt direct geactiveerd en een factuur wordt aangemaakt. Doorgaan?',
    'activate_license' => 'Activeer licentie',

    // Buttons
    'continue_to_payment' => 'Doorgaan naar betaling',
    'complete_payment' => 'Betaling voltooien',
    'submit_license_request' => 'Dien licentieverzoek in',
    'back_to_pricing' => 'Terug naar prijzen',
    'back' => 'Terug',
    'processing' => 'Verwerken...',

    // Validation
    'field_required' => 'Dit veld is verplicht',
    'invalid_email' => 'Voer een geldig e-mailadres in',
    'invalid_vat' => 'Voer een geldig BTW-nummer in',
    'valid_vat_no_charge' => 'Geldig BTW-nummer - Geen BTW verschuldigd',

    // Validation messages
    'validation' => [
        'email_required' => 'E-mailadres is verplicht.',
        'email_invalid' => 'Voer een geldig e-mailadres in.',
        'full_name_required' => 'Volledige naam is verplicht.',
        'company_name_required' => 'Bedrijfsnaam is verplicht.',
        'street_required' => 'Straatnaam en huisnummer is verplicht.',
        'postal_code_required' => 'Postcode is verplicht.',
        'city_required' => 'Plaats is verplicht.',
        'state_required' => 'Staat/Provincie is verplicht.',
    ],

    // One-time credits
    'onetime_credits' => 'Eenmalig :count credits (:months maanden)',

    // Invoice/Activation page
    'license_request_submitted' => 'Factuur gegenereerd!',
    'license_request_submitted_description' => 'Uw factuur is gegenereerd. Zodra de betaling is ontvangen, wordt uw licentie geactiveerd en worden credits toegevoegd aan uw account.',
    'invoice_details' => 'Factuurgegevens',
    'invoice_number' => 'Factuurnummer',
    'status' => 'Status',
    'pending_review' => 'In afwachting van betaling',
    'what_happens_next' => 'Wat gebeurt er hierna?',
    'next_step_1' => 'Download de factuur met de knop hieronder',
    'next_step_2' => 'Verwerk de betaling volgens de instructies op de factuur',
    'next_step_3' => 'Zodra de betaling is ontvangen, wordt uw licentie automatisch geactiveerd',
    'next_step_4' => 'Credits worden toegevoegd aan uw account en u krijgt volledige toegang',
    'email_confirmation' => 'U ontvangt een e-mailbevestiging met deze details.',
    'view_license_status' => 'Bekijk licentiestatus',
    'continue_with_free_account' => 'Doorgaan met gratis account',

    // Success page
    'payment_successful' => 'Betaling geslaagd!',
    'payment_successful_message' => 'Betaling geslaagd! Uw licentie is geactiveerd.',
    'license_activated_with_credits' => 'Licentie succesvol geactiveerd! Factuur is gegenereerd. :credits credits zijn toegevoegd aan uw account.',
    'license_added' => 'Licentie is toegevoegd!',
    'license_activated_check' => 'Licentie succesvol geactiveerd',
    'invoice_generated_check' => 'Factuur is gegenereerd',
    'credits_added_check' => ':credits credits zijn toegevoegd aan uw account',
    'pay_invoice_todo' => 'Betaal uw factuur',
    'order_id' => 'Bestelnummer',
    'amount' => 'Bedrag',
    'credits_added' => 'Credits toegevoegd',
    'valid_until' => 'Geldig tot',
    'subscription' => 'Abonnement',
    'annual_auto_renewal' => 'Jaarlijks (automatische verlenging)',
    'next_billing' => 'Volgende facturering',
    'start_converting_files' => 'Start bestanden converteren',
    'view_all_plans' => 'Bekijk alle pakketten',
    'download_invoice' => 'Download factuur',

    // Error/pending states
    'payment_failed' => 'Betaling mislukt',
    'payment_failed_message' => 'Betaling is niet voltooid. Probeer het opnieuw.',
    'activation_status_unknown_message' => 'Kan activeringsstatus niet bepalen.',
    'processing_payment' => 'Betaling verwerken',
    'processing_payment_message' => 'Even geduld terwijl we uw betaling bevestigen bij onze beveiligde betalingsprovider. Dit duurt meestal maar een paar seconden.',
    'order_status_initiated' => 'Bestelstatus: geïnitieerd',
    'check_status' => 'Controleer status',
    'continue_to_dashboard' => 'Ga naar dashboard',
    'try_again' => 'Probeer opnieuw',
    'choose_different_plan' => 'Kies een ander pakket',
    'activation_status_unknown' => 'Activatiestatus onbekend',
    'view_pricing' => 'Bekijk prijzen',
    'go_to_dashboard' => 'Ga naar dashboard',

    // Authentication and verification
    'login_required' => 'Log in om verder te gaan met uw aankoop.',
    'verification_required' => 'Verifieer eerst uw e-mailadres voordat u een aankoop kunt doen.',

    // Error messages
    'errors' => [
        'license_not_found' => 'De geselecteerde licentie is niet meer beschikbaar.',
        'organization_not_found' => 'Organisatie niet gevonden.',
        'admin_required' => 'Alleen organisatie-admins kunnen betalingen doen voor de organisatie.',
        'payment_failed' => 'Betaling mislukt. Probeer het opnieuw.',
    ],
];
