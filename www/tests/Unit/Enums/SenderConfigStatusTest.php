<?php

use App\Enums\SenderConfigStatus;

it('has correct values', function () {
    expect(SenderConfigStatus::Active->value)->toBe('active');
    expect(SenderConfigStatus::PendingVerification->value)->toBe('pending_verification');
    expect(SenderConfigStatus::Verified->value)->toBe('verified');
    expect(SenderConfigStatus::Failed->value)->toBe('failed');
});

it('has labels', function () {
    expect(SenderConfigStatus::Active->label())->toBe('Active');
    expect(SenderConfigStatus::PendingVerification->label())->toBe('Pending Verification');
    expect(SenderConfigStatus::Verified->label())->toBe('Verified');
    expect(SenderConfigStatus::Failed->label())->toBe('Failed');
});

it('has badge colors', function () {
    expect(SenderConfigStatus::Active->badgeColor())->toBe('success');
    expect(SenderConfigStatus::PendingVerification->badgeColor())->toBe('warning');
    expect(SenderConfigStatus::Verified->badgeColor())->toBe('success');
    expect(SenderConfigStatus::Failed->badgeColor())->toBe('danger');
});
