<?php

return [
    // Page
    'title' => 'Webhooks',
    'description' => 'Receive real-time notifications when events occur in your account.',

    // List
    'no_webhooks' => 'No webhooks configured',
    'no_webhooks_description' => 'Get started by adding your first webhook endpoint.',
    'add_first_webhook' => 'Add your first webhook',
    'add_webhook' => 'Add webhook',
    'usage_count' => 'Using :count of :max webhooks',

    // Status
    'inactive' => 'Inactive',
    'failing' => 'Failing',
    'active' => 'Active',

    // Actions
    'edit' => 'Edit',
    'delete' => 'Delete',
    'delete_confirm' => 'Are you sure you want to delete this webhook?',
    'send_test' => 'Send test event',
    'view_deliveries' => 'View deliveries',

    // Form
    'edit_webhook' => 'Edit webhook',
    'endpoint_url' => 'Endpoint URL',
    'description_label' => 'Description',
    'description_placeholder' => 'e.g., Production webhook',
    'secret_label' => 'Secret',
    'secret_placeholder' => 'Your secret key for HMAC verification',
    'secret_help' => 'Used to sign webhook payloads for verification. Optional but recommended.',
    'leave_empty_to_keep' => 'leave empty to keep current',
    'events_label' => 'Events',
    'save_changes' => 'Save changes',
    'create_webhook' => 'Create webhook',
    'cancel' => 'Cancel',

    // Events
    'event_execution_started' => 'When a conversion starts processing',
    'event_execution_progress' => 'Progress updates during conversion',
    'event_execution_completed' => 'When a conversion completes successfully',
    'event_execution_failed' => 'When a conversion fails',

    // Messages
    'created' => 'Webhook created successfully.',
    'updated' => 'Webhook updated successfully.',
    'deleted' => 'Webhook deleted successfully.',
    'not_found' => 'Webhook not found.',
    'limit_reached' => 'Maximum number of webhooks reached (:max).',
    'error_saving' => 'An error occurred while saving the webhook.',
    'test_sent' => 'Test event queued for delivery.',
    'test_failed' => 'Failed to send test event.',
    'test_inactive' => 'Cannot send test to inactive webhook.',
    'https_required' => 'HTTPS is required for webhook URLs.',

    // Deliveries modal
    'recent_deliveries' => 'Recent deliveries',
    'no_deliveries' => 'No deliveries yet.',
    'event' => 'Event',
    'status' => 'Status',
    'response' => 'Response',
    'time' => 'Time',
    'success' => 'Success',
    'failed' => 'Failed',
    'retrying' => 'Retrying',
    'pending' => 'Pending',
    'close' => 'Close',
    'last_triggered' => 'Last triggered',
];
