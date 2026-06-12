<?php

/**
 * Complete State Assertions for Scenario Tests
 *
 * These helper functions ensure that state transitions are fully tested,
 * including all fields that downstream code (views, APIs) depends on.
 *
 * @see .claude/skills/automated_testing/skill.md - "Complete State Assertions" section
 */

namespace Tests\Helpers;

use App\Enums\OrderStatus;
use App\Models\ApiV1ProcessResult;
use App\Models\ApiV1Session;
use App\Models\ApiV1SessionFile;
use App\Models\CreditLedger;
use App\Models\Invitation;
use App\Models\LicenseNotification;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationCreditLedger;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use App\Models\Workflow;
use Carbon\Carbon;

// ==================== ORDER ASSERTIONS ====================

/**
 * Assert that an order is in a complete "paid" state.
 * This checks all fields needed for invoice display and audit.
 */
function assertOrderIsPaidComplete(Order $order): void
{
    expect($order->status)->toBe(OrderStatus::Paid);
    expect($order->paid_at)->not->toBeNull('paid_at must be set when order is paid');
    expect($order->paid_at)->toBeInstanceOf(Carbon::class);
}

/**
 * Assert order is paid with payment method (for Mollie payments)
 */
function assertOrderIsPaidWithPaymentMethod(Order $order): void
{
    assertOrderIsPaidComplete($order);
    expect($order->payment_method)->not->toBeNull('payment_method must be set for Mollie payments');
}

/**
 * Assert that an order is in pending/initiated state.
 */
function assertOrderIsPending(Order $order): void
{
    expect($order->status)->toBeIn([OrderStatus::Initiated, OrderStatus::Pending]);
    expect($order->paid_at)->toBeNull('paid_at must be null for pending orders');
}

/**
 * Assert that an order is canceled.
 */
function assertOrderIsCanceled(Order $order): void
{
    expect($order->status)->toBe(OrderStatus::Canceled);
}

/**
 * Assert that an order has a valid invoice.
 */
function assertOrderHasInvoice(Order $order): void
{
    expect($order->invoice_number)->not->toBeNull('invoice_number must be set');
    expect($order->invoice_date)->not->toBeNull('invoice_date must be set');
}

// ==================== USER LICENSE ASSERTIONS ====================

/**
 * Assert that a user license is in a complete "active" state.
 */
function assertUserLicenseIsActive(UserLicense $license): void
{
    expect($license->status)->toBe('active');
    expect($license->starts_at)->not->toBeNull('starts_at must be set for active license');
    expect($license->is_current)->toBeTrue('is_current must be true for active license');
    expect($license->source)->not->toBeNull('source must be set');
}

/**
 * Assert that a user license is pending payment.
 */
function assertUserLicenseIsPending(UserLicense $license): void
{
    expect($license->status)->toBe('pending');
}

/**
 * Assert that a user license is expired.
 */
function assertUserLicenseIsExpired(UserLicense $license): void
{
    expect($license->status)->toBe('expired');
    expect($license->is_current)->toBeFalse('is_current must be false for expired license');
}

/**
 * Assert that a user license is canceled.
 */
function assertUserLicenseIsCanceled(UserLicense $license): void
{
    expect($license->status)->toBe('canceled');
}

// ==================== ORGANIZATION LICENSE ASSERTIONS ====================

/**
 * Assert that an organization license is in a complete "active" state.
 */
function assertOrganizationLicenseIsActive(OrganizationLicense $license): void
{
    expect($license->status)->toBe('active');
    expect($license->starts_at)->not->toBeNull('starts_at must be set for active license');
    expect($license->is_current)->toBeTrue('is_current must be true for active license');
    expect($license->source)->not->toBeNull('source must be set');
}

/**
 * Assert that an organization license is pending payment.
 */
function assertOrganizationLicenseIsPending(OrganizationLicense $license): void
{
    expect($license->status)->toBe('pending');
    expect($license->payment_status)->toBe('unpaid');
}

/**
 * Assert that an organization license is expired.
 */
function assertOrganizationLicenseIsExpired(OrganizationLicense $license): void
{
    expect($license->status)->toBe('expired');
    expect($license->is_current)->toBeFalse('is_current must be false for expired license');
}

/**
 * Assert that an organization license has invoice billing configured.
 */
function assertOrganizationLicenseHasInvoice(OrganizationLicense $license): void
{
    expect($license->billing_method)->toBe('invoice');
    expect($license->invoice_number)->not->toBeNull('invoice_number must be set');
}

/**
 * Assert organization license is paid (for invoice billing)
 */
function assertOrganizationLicenseIsPaid(OrganizationLicense $license): void
{
    expect($license->payment_status)->toBe('paid');
    expect($license->paid_at)->not->toBeNull('paid_at must be set when license is paid');
}

// ==================== ORGANIZATION ASSERTIONS ====================

/**
 * Assert that a user is a complete member of an organization.
 */
function assertOrganizationMemberComplete(Organization $org, User $user, string $expectedRole): void
{
    $member = $org->users()->where('user_id', $user->id)->first();
    expect($member)->not->toBeNull("User {$user->id} should be member of organization {$org->id}");
    expect($member->pivot->role)->toBe($expectedRole);
    expect($member->pivot->joined_at)->not->toBeNull('joined_at must be set');
}

/**
 * Assert that an organization has a valid credit pool.
 */
function assertOrganizationHasCreditPool(Organization $org, ?int $expectedBalance = null): void
{
    $org->refresh();
    expect($org->creditPool)->not->toBeNull('Organization must have a credit pool');
    expect($org->creditPool->balance_credits)->toBeGreaterThanOrEqual(0);

    if ($expectedBalance !== null) {
        expect($org->creditPool->balance_credits)->toBe($expectedBalance);
    }
}

/**
 * Assert user is admin of organization
 */
function assertUserIsOrgAdmin(Organization $org, User $user): void
{
    assertOrganizationMemberComplete($org, $user, 'admin');
}

/**
 * Assert user is member of organization
 */
function assertUserIsOrgMember(Organization $org, User $user): void
{
    assertOrganizationMemberComplete($org, $user, 'member');
}

// ==================== WORKFLOW ASSERTIONS ====================

/**
 * Assert that a workflow is complete and valid.
 */
function assertWorkflowIsComplete(Workflow $workflow): void
{
    expect($workflow->name)->not->toBeNull('Workflow name must be set');
    expect($workflow->user_id)->not->toBeNull('Workflow must have an owner');
    expect($workflow->steps)->toBeArray();
}

/**
 * Assert that a workflow execution is complete.
 */
function assertWorkflowExecutionComplete($execution): void
{
    expect($execution->status)->toBeIn(['completed', 'failed', 'pending', 'processing']);
    expect($execution->workflow_id)->not->toBeNull('Execution must reference a workflow');
}

/**
 * Assert workflow execution succeeded
 */
function assertWorkflowExecutionSucceeded($execution): void
{
    expect($execution->status)->toBe('completed');
    expect($execution->completed_at)->not->toBeNull('completed_at must be set');
}

// ==================== CREDIT ASSERTIONS ====================

/**
 * Assert that a credit ledger entry is complete.
 */
function assertCreditLedgerEntryComplete(CreditLedger $entry): void
{
    expect($entry->user_id)->not->toBeNull('user_id must be set');
    expect($entry->delta)->not->toBeNull('delta must be set');
    expect($entry->reason)->not->toBeNull('reason must be set');
    expect($entry->balance_after)->not->toBeNull('balance_after must be set');
}

/**
 * Assert that an organization credit ledger entry is complete.
 */
function assertOrgCreditLedgerEntryComplete(OrganizationCreditLedger $entry): void
{
    expect($entry->organization_id)->not->toBeNull('organization_id must be set');
    expect($entry->delta)->not->toBeNull('delta must be set');
    expect($entry->reason)->not->toBeNull('reason must be set');
    expect($entry->balance_after)->not->toBeNull('balance_after must be set');
}

/**
 * Assert user has expected credits
 */
function assertUserHasCredits(User $user, int $expected): void
{
    $user->refresh();
    expect($user->credits)->toBe($expected);
}

/**
 * Assert organization has expected credits
 */
function assertOrgHasCredits(Organization $org, int $expected): void
{
    assertOrganizationHasCreditPool($org, $expected);
}

// ==================== INVITATION ASSERTIONS ====================

/**
 * Assert that an invitation is in a complete "pending" state.
 */
function assertInvitationIsPending(Invitation $invitation): void
{
    expect($invitation->status)->toBe('pending');
    expect($invitation->token)->not->toBeNull('token must be set for pending invitation');
    expect($invitation->expires_at)->not->toBeNull('expires_at must be set');
    expect($invitation->expires_at)->toBeGreaterThan(now(), 'expires_at must be in the future');
}

/**
 * Assert that an invitation has been accepted.
 */
function assertInvitationIsAccepted(Invitation $invitation): void
{
    expect($invitation->status)->toBe('accepted');
    expect($invitation->accepted_at)->not->toBeNull('accepted_at must be set when invitation is accepted');
}

/**
 * Assert that an invitation has been revoked.
 */
function assertInvitationIsRevoked(Invitation $invitation): void
{
    expect($invitation->status)->toBe('revoked');
}

/**
 * Assert that an invitation has been rejected.
 */
function assertInvitationIsRejected(Invitation $invitation): void
{
    expect($invitation->status)->toBe('rejected');
}

/**
 * Assert that an invitation has expired.
 */
function assertInvitationIsExpiredState(Invitation $invitation): void
{
    expect($invitation->status)->toBe('expired');
}

// ==================== NOTIFICATION ASSERTIONS ====================

/**
 * Assert that a license notification is complete.
 */
function assertLicenseNotificationComplete(LicenseNotification $notification): void
{
    expect($notification->notification_type)->not->toBeNull('notification_type must be set');
    expect($notification->sent_at)->not->toBeNull('sent_at must be set');
    // Must have either user_license_id or organization_license_id
    $hasLicenseRef = $notification->user_license_id !== null || $notification->organization_license_id !== null;
    expect($hasLicenseRef)->toBeTrue('notification must reference a user_license or organization_license');
}

// ==================== API V1 SESSION ASSERTIONS ====================

/**
 * Assert that an API session is in a complete valid state.
 */
function assertApiSessionIsValid(ApiV1Session $session): void
{
    expect($session->user_id)->not->toBeNull('user_id must be set');
    expect($session->encryption_key)->not->toBeNull('encryption_key must be set');
    expect($session->validate_key)->not->toBeNull('validate_key must be set');
    expect($session->expiration_time_seconds)->toBeGreaterThan(0, 'expiration_time_seconds must be positive');
    expect($session->expires_at)->toBeGreaterThan(now(), 'session must not be expired');
}

/**
 * Assert that an API session has a workflow execution attached.
 */
function assertApiSessionHasExecution(ApiV1Session $session): void
{
    assertApiSessionIsValid($session);
    expect($session->workflow_execution_id)->not->toBeNull('workflow_execution_id must be set after processing');
    expect($session->workflowExecution)->not->toBeNull('workflowExecution relation must exist');
}

/**
 * Assert that an API session file is complete.
 */
function assertApiSessionFileComplete(ApiV1SessionFile $file): void
{
    expect($file->session_id)->not->toBeNull('session_id must be set');
    expect($file->original_filename)->not->toBeNull('original_filename must be set');
    expect($file->extension)->not->toBeNull('extension must be set');
    expect($file->storage_path)->not->toBeNull('storage_path must be set');
    expect($file->is_partial)->toBeFalse('file must not be partial (upload must be complete)');
}

/**
 * Assert that an API process result is in pending state.
 */
function assertApiProcessResultPending(ApiV1ProcessResult $result): void
{
    expect($result->session_id)->not->toBeNull('session_id must be set');
    expect($result->workflow_execution_id)->not->toBeNull('workflow_execution_id must be set');
    expect($result->validate_key)->not->toBeNull('validate_key must be set');
    expect($result->expires_at)->not->toBeNull('expires_at must be set');
    expect($result->credits_charged)->toBeGreaterThanOrEqual(0, 'credits_charged must be set');
}

/**
 * Assert that an API process result is complete with download ready.
 */
function assertApiProcessResultComplete(ApiV1ProcessResult $result): void
{
    assertApiProcessResultPending($result);
    expect($result->result_url)->not->toBeNull('result_url must be set when processing is complete');
}

/**
 * Assert credits were properly charged for API processing.
 */
function assertApiCreditsCharged(ApiV1ProcessResult $result, int $expectedCredits, string $expectedSource = 'user'): void
{
    expect($result->credits_charged)->toBe($expectedCredits, "Expected {$expectedCredits} credits charged");
    expect($result->credits_source)->toBe($expectedSource, "Expected credits source to be {$expectedSource}");
    expect($result->credits_source_id)->not->toBeNull('credits_source_id must be set');
}
