<?php

use App\Enums\DemoItemStatus;

it('has correct labels', function () {
    expect(DemoItemStatus::Draft->label())->toBe('Draft');
    expect(DemoItemStatus::Active->label())->toBe('Active');
    expect(DemoItemStatus::Completed->label())->toBe('Completed');
    expect(DemoItemStatus::Cancelled->label())->toBe('Cancelled');
});

it('has correct badge colors', function () {
    expect(DemoItemStatus::Draft->badgeColor())->toBe('gray');
    expect(DemoItemStatus::Active->badgeColor())->toBe('success');
    expect(DemoItemStatus::Completed->badgeColor())->toBe('info');
    expect(DemoItemStatus::Cancelled->badgeColor())->toBe('danger');
});

it('allows draft to transition to active', function () {
    expect(DemoItemStatus::Draft->canTransitionTo(DemoItemStatus::Active))->toBeTrue();
});

it('allows draft to transition to cancelled', function () {
    expect(DemoItemStatus::Draft->canTransitionTo(DemoItemStatus::Cancelled))->toBeTrue();
});

it('does not allow draft to transition to completed', function () {
    expect(DemoItemStatus::Draft->canTransitionTo(DemoItemStatus::Completed))->toBeFalse();
});

it('allows active to transition to completed', function () {
    expect(DemoItemStatus::Active->canTransitionTo(DemoItemStatus::Completed))->toBeTrue();
});

it('allows active to transition to cancelled', function () {
    expect(DemoItemStatus::Active->canTransitionTo(DemoItemStatus::Cancelled))->toBeTrue();
});

it('does not allow active to transition to draft', function () {
    expect(DemoItemStatus::Active->canTransitionTo(DemoItemStatus::Draft))->toBeFalse();
});

it('allows completed to transition to active (reopen)', function () {
    expect(DemoItemStatus::Completed->canTransitionTo(DemoItemStatus::Active))->toBeTrue();
});

it('does not allow completed to transition to draft', function () {
    expect(DemoItemStatus::Completed->canTransitionTo(DemoItemStatus::Draft))->toBeFalse();
});

it('allows cancelled to transition to draft (reactivate)', function () {
    expect(DemoItemStatus::Cancelled->canTransitionTo(DemoItemStatus::Draft))->toBeTrue();
});

it('does not allow cancelled to transition to active', function () {
    expect(DemoItemStatus::Cancelled->canTransitionTo(DemoItemStatus::Active))->toBeFalse();
});

it('returns correct allowed transitions for each status', function () {
    expect(DemoItemStatus::Draft->allowedTransitions())->toBe([DemoItemStatus::Active, DemoItemStatus::Cancelled]);
    expect(DemoItemStatus::Active->allowedTransitions())->toBe([DemoItemStatus::Completed, DemoItemStatus::Cancelled]);
    expect(DemoItemStatus::Completed->allowedTransitions())->toBe([DemoItemStatus::Active]);
    expect(DemoItemStatus::Cancelled->allowedTransitions())->toBe([DemoItemStatus::Draft]);
});
