<?php

use App\Enums\SenderLevel;

it('has correct values', function () {
    expect(SenderLevel::ReplyTo->value)->toBe('reply_to');
    expect(SenderLevel::SenderSignature->value)->toBe('sender_signature');
    expect(SenderLevel::DomainAuth->value)->toBe('domain_auth');
});

it('has labels', function () {
    expect(SenderLevel::ReplyTo->label())->toBe('Reply-To');
    expect(SenderLevel::SenderSignature->label())->toBe('Sender Signature');
    expect(SenderLevel::DomainAuth->label())->toBe('Domain Authentication');
});

it('has badge colors', function () {
    expect(SenderLevel::ReplyTo->badgeColor())->toBe('secondary');
    expect(SenderLevel::SenderSignature->badgeColor())->toBe('warning');
    expect(SenderLevel::DomainAuth->badgeColor())->toBe('success');
});

it('has descriptions', function () {
    foreach (SenderLevel::cases() as $case) {
        expect($case->description())->toBeString()->not->toBeEmpty();
    }
});
