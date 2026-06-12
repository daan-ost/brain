<?php

return [
    // Page
    'title' => 'Webhooks',
    'description' => 'Ontvang real-time notificaties wanneer events plaatsvinden in je account.',

    // List
    'no_webhooks' => 'Geen webhooks geconfigureerd',
    'no_webhooks_description' => 'Begin met het toevoegen van je eerste webhook endpoint.',
    'add_first_webhook' => 'Voeg je eerste webhook toe',
    'add_webhook' => 'Webhook toevoegen',
    'usage_count' => ':count van :max webhooks in gebruik',

    // Status
    'inactive' => 'Inactief',
    'failing' => 'Faalt',
    'active' => 'Actief',

    // Actions
    'edit' => 'Bewerken',
    'delete' => 'Verwijderen',
    'delete_confirm' => 'Weet je zeker dat je deze webhook wilt verwijderen?',
    'send_test' => 'Test event versturen',
    'view_deliveries' => 'Bekijk afleveringen',

    // Form
    'edit_webhook' => 'Webhook bewerken',
    'endpoint_url' => 'Endpoint URL',
    'description_label' => 'Beschrijving',
    'description_placeholder' => 'bijv. Productie webhook',
    'secret_label' => 'Secret',
    'secret_placeholder' => 'Je geheime sleutel voor HMAC verificatie',
    'secret_help' => 'Wordt gebruikt om webhook payloads te ondertekenen voor verificatie. Optioneel maar aanbevolen.',
    'leave_empty_to_keep' => 'leeg laten om huidige te behouden',
    'events_label' => 'Events',
    'save_changes' => 'Wijzigingen opslaan',
    'create_webhook' => 'Webhook aanmaken',
    'cancel' => 'Annuleren',

    // Events
    'event_execution_started' => 'Wanneer een conversie start met verwerken',
    'event_execution_progress' => 'Voortgangsupdates tijdens conversie',
    'event_execution_completed' => 'Wanneer een conversie succesvol is afgerond',
    'event_execution_failed' => 'Wanneer een conversie mislukt',

    // Messages
    'created' => 'Webhook succesvol aangemaakt.',
    'updated' => 'Webhook succesvol bijgewerkt.',
    'deleted' => 'Webhook succesvol verwijderd.',
    'not_found' => 'Webhook niet gevonden.',
    'limit_reached' => 'Maximum aantal webhooks bereikt (:max).',
    'error_saving' => 'Er is een fout opgetreden bij het opslaan van de webhook.',
    'test_sent' => 'Test event in wachtrij geplaatst.',
    'test_failed' => 'Kon test event niet versturen.',
    'test_inactive' => 'Kan geen test versturen naar inactieve webhook.',
    'https_required' => 'HTTPS is vereist voor webhook URLs.',

    // Deliveries modal
    'recent_deliveries' => 'Recente afleveringen',
    'no_deliveries' => 'Nog geen afleveringen.',
    'event' => 'Event',
    'status' => 'Status',
    'response' => 'Response',
    'time' => 'Tijd',
    'success' => 'Succes',
    'failed' => 'Mislukt',
    'retrying' => 'Opnieuw proberen',
    'pending' => 'In behandeling',
    'close' => 'Sluiten',
    'last_triggered' => 'Laatst getriggerd',
];
