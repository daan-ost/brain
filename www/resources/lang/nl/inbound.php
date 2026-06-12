<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Inbound Email Taalregels
    |--------------------------------------------------------------------------
    |
    | De volgende taalregels worden gebruikt voor de inbound email voorkeuren
    | en beheerinterface.
    |
    */

    'title' => 'Inbound Email',
    'description' => 'Ontvang en verwerk emails die naar jouw unieke emailadressen worden gestuurd.',

    'feature_disabled' => 'Inbound email is momenteel uitgeschakeld. Neem contact op met support voor meer informatie.',

    'enable_inbound' => 'Inbound Email Activeren',
    'enable_description' => 'Activeer inbound email verwerking om emails te ontvangen op jouw unieke adressen.',
    'enable_inbound_description' => 'Je kunt emails sturen naar :domain en deze worden automatisch verwerkt.',

    'enabled_successfully' => 'Inbound email is succesvol geactiveerd.',
    'disabled_successfully' => 'Inbound email is uitgeschakeld.',

    'your_email_addresses' => 'Jouw Emailadressen',
    'copy' => 'Kopieer',
    'copied' => 'Gekopieerd!',
    'copied_to_clipboard' => 'Emailadres gekopieerd naar klembord.',

    'advanced_options' => 'Geavanceerde Opties',

    'verify_sender' => 'Afzender Verifiëren',
    'verify_sender_description' => 'Accepteer alleen emails van jouw geregistreerde emailadres. Aanbevolen voor beveiliging.',
    'verify_sender_warning' => 'Het uitschakelen van afzenderverificatie staat iedereen met jouw unieke emailadres toe om emails te sturen. Dit kan een beveiligingsrisico zijn als jouw emailadres wordt gedeeld.',
    'verify_sender_updated' => 'Afzenderverificatie-instelling bijgewerkt.',
    'security_warning' => 'Waarschuwing',

    'admin_setup_required' => 'Beheerder Setup Vereist',
    'admin_setup_description' => 'Jouw website beheerder moet de Postmark inbound webhook configureren om deze functie correct te laten werken.',
    'learn_more' => 'Meer informatie over Postmark setup',

    // History tabel
    'recent_emails' => 'Recente Inbound Emails',
    'date' => 'Datum',
    'subject' => 'Onderwerp',
    'action' => 'Actie',
    'status' => 'Status',
    'status_processed' => 'Verwerkt',
    'status_processing' => 'Bezig',
    'status_received' => 'Ontvangen',
    'status_failed' => 'Mislukt',
    'status_bounced' => 'Geweigerd',
    'status_virus_detected' => 'Virus gedetecteerd',
    'days_remaining' => '{0} Verlopen|{1} 1 dag over|[2,*] :days dagen over',
];
