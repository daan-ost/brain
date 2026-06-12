<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Nederlandse vertalingen voor Laravel validation regels.
    | Alleen de meest gebruikte regels zijn hier opgenomen.
    |
    */

    'confirmed' => 'Het :attribute veld komt niet overeen met de bevestiging.',
    'required' => 'Het :attribute veld is verplicht.',
    'email' => 'Het :attribute moet een geldig e-mailadres zijn.',
    'min' => [
        'string' => 'Het :attribute moet minimaal :min tekens bevatten.',
    ],
    'max' => [
        'string' => 'Het :attribute mag maximaal :max tekens bevatten.',
    ],

    // Email domain validation
    'email_domain_typo' => 'Bedoelde je :suggestion?',
    'gmail_username_too_short' => 'Gmail-adressen moeten minimaal 6 tekens hebben voor de @.',
    'email_domain_no_mx' => 'Dit e-maildomein lijkt geen e-mail te kunnen ontvangen. Controleer op typefouten.',
    'email_username_not_allowed' => 'Gebruik een persoonlijk e-mailadres, geen generiek adres.',
    'email_domain_not_allowed' => 'Gebruik een permanent e-mailadres, geen tijdelijk of wegwerp-adres.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    */

    'attributes' => [
        'password' => 'wachtwoord',
        'password_confirmation' => 'wachtwoord bevestiging',
        'email' => 'e-mailadres',
        'name' => 'naam',
        'first_name' => 'voornaam',
        'last_name' => 'achternaam',
    ],
];
