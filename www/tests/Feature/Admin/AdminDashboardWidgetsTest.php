<?php

declare(strict_types=1);

use Filament\Widgets\AccountWidget;

describe('Admin Dashboard Widgets', function () {

    describe('AccountWidget removal', function () {
        it('does not register AccountWidget in the admin panel', function () {
            $panel = filament()->getPanel('admin');
            $widgetClasses = collect($panel->getWidgets())
                ->map(fn ($widget) => is_string($widget) ? $widget : get_class($widget));

            expect($widgetClasses->contains(AccountWidget::class))->toBeFalse();
        });
    });
});
