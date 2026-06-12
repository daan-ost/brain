<?php

use App\Enums\DemoItemPriority;

it('has correct labels', function () {
    expect(DemoItemPriority::Low->label())->toBe('Low');
    expect(DemoItemPriority::Medium->label())->toBe('Medium');
    expect(DemoItemPriority::High->label())->toBe('High');
    expect(DemoItemPriority::Urgent->label())->toBe('Urgent');
});

it('has correct badge colors', function () {
    expect(DemoItemPriority::Low->badgeColor())->toBe('gray');
    expect(DemoItemPriority::Medium->badgeColor())->toBe('info');
    expect(DemoItemPriority::High->badgeColor())->toBe('warning');
    expect(DemoItemPriority::Urgent->badgeColor())->toBe('danger');
});

it('has correct sort order', function () {
    expect(DemoItemPriority::Low->sortOrder())->toBe(1);
    expect(DemoItemPriority::Medium->sortOrder())->toBe(2);
    expect(DemoItemPriority::High->sortOrder())->toBe(3);
    expect(DemoItemPriority::Urgent->sortOrder())->toBe(4);
});

it('has ascending sort order from low to urgent', function () {
    $priorities = DemoItemPriority::cases();
    $sorted = collect($priorities)->sortBy(fn ($p) => $p->sortOrder())->values();

    expect($sorted[0])->toBe(DemoItemPriority::Low);
    expect($sorted[3])->toBe(DemoItemPriority::Urgent);
});
