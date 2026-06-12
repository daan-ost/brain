<?php

declare(strict_types=1);

test('desktop-only-notice renders mobile message with title and description', function () {
    $html = $this->blade(
        '<x-desktop-only-notice title="Test Title" description="Test description text"><p>Desktop content</p></x-desktop-only-notice>'
    );

    $html->assertSeeText('Test Title');
    $html->assertSeeText('Test description text');
    $html->assertSee('sm:hidden', false);
    $html->assertSeeText('Desktop content');
});

test('desktop-only-notice renders hint text when provided', function () {
    $html = $this->blade(
        '<x-desktop-only-notice title="Title" description="Desc" hint="Hint text here"><p>Content</p></x-desktop-only-notice>'
    );

    $html->assertSeeText('Hint text here');
});

test('desktop-only-notice does not render hint when not provided', function () {
    $html = $this->blade(
        '<x-desktop-only-notice title="Title" description="Desc"><p>Content</p></x-desktop-only-notice>'
    );

    $html->assertDontSeeText('Hint text here');
});

test('desktop-only-notice hides desktop content on mobile with sm breakpoint', function () {
    $html = $this->blade(
        '<x-desktop-only-notice title="Title" description="Desc"><p>Desktop only</p></x-desktop-only-notice>'
    );

    $html->assertSee('sm:hidden', false);
    $html->assertSee('hidden sm:block', false);
});

test('desktop-only-notice includes accessibility role status on mobile message', function () {
    $html = $this->blade(
        '<x-desktop-only-notice title="Title" description="Desc"><p>Content</p></x-desktop-only-notice>'
    );

    $html->assertSee('role="status"', false);
});

test('desktop-only-notice renders desktop icon with aria-hidden', function () {
    $html = $this->blade(
        '<x-desktop-only-notice title="Title" description="Desc"><p>Content</p></x-desktop-only-notice>'
    );

    $html->assertSee('aria-hidden="true"', false);
});

test('desktop-only-notice escapes title and description to prevent XSS', function () {
    $html = $this->blade(
        '<x-desktop-only-notice title="<script>alert(1)</script>" description="<img onerror=alert(1)>"><p>Content</p></x-desktop-only-notice>'
    );

    // Verify dangerous HTML tags are escaped (rendered as entities, not executable HTML)
    $html->assertDontSee('<script>', false);
    $html->assertDontSee('<img onerror', false);
});
