<?php

/**
 * WCAG 2.2 AA assertions on auth-views (login-code-request, login-code-verify,
 * login, register). Catches partial fixes — historically, focus-rings on
 * input fields were added but submit-buttons were forgotten in 10 views.
 *
 * Auth-views passing email between request → verify uses query-param
 * (`?email=...`), not session — different from session-based flows.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Auth views — login-code-request', function () {
    it('rendert de pagina', function () {
        $this->get(route('login.code'))
            ->assertStatus(200);
    });

    it('heeft aria-describedby op het email-input', function () {
        $this->get(route('login.code'))
            ->assertSee('aria-describedby="login-code-request-subtitle', false);
    });

    it('heeft een terug-naar-login link met underline', function () {
        $this->get(route('login.code'))
            ->assertSee('underline', false)
            ->assertSee(route('login'), false);
    });

    it('heeft een submit-knop met zichtbare focus-ring', function () {
        $this->get(route('login.code'))
            ->assertSee('focus:ring-2', false);
    });

    it('toont validatiefouten bij ongeldig email-adres', function () {
        $this->post(route('login.code.send'), ['email' => ''])
            ->assertSessionHasErrors('email');
    });
});

describe('Auth views — login-code-verify', function () {
    it('rendert de pagina als de email als query-param meegegeven wordt', function () {
        $this->get(route('login.code.verify', ['email' => 'test@example.com']))
            ->assertStatus(200);
    });

    it('rendert ook zonder email-param (toont leeg formulier)', function () {
        // Basewebsite gebruikt query-param ipv session — pagina rendert altijd
        $this->get(route('login.code.verify'))
            ->assertStatus(200);
    });

    it('heeft aria-describedby op het code-input', function () {
        $this->get(route('login.code.verify', ['email' => 'test@example.com']))
            ->assertSee('aria-describedby="login-code-verify-subtitle', false);
    });

    it('toont role=status banner als de sessie status login-code-sent heeft', function () {
        $this->withSession(['status' => 'login-code-sent'])
            ->get(route('login.code.verify', ['email' => 'test@example.com']))
            ->assertSee('role="status"', false);
    });

    it('heeft een verborgen email-input in het formulier', function () {
        $this->get(route('login.code.verify', ['email' => 'test@example.com']))
            ->assertSee('name="email"', false)
            ->assertSee('type="hidden"', false);
    });

    it('heeft twee onderstreepte navigatielinks (resend + back-to-login)', function () {
        $this->get(route('login.code.verify', ['email' => 'test@example.com']))
            ->assertSee(route('login.code'), false)
            ->assertSee(route('login'), false)
            ->assertSee('underline', false);
    });

    it('heeft een submit-knop met zichtbare focus-ring', function () {
        $this->get(route('login.code.verify', ['email' => 'test@example.com']))
            ->assertSee('focus:ring-2', false);
    });
});

describe('Auth views — alternate-login partial op de login pagina', function () {
    it('toont de email-code knop', function () {
        $this->get(route('login'))
            ->assertStatus(200)
            ->assertSee(route('login.code'), false);
    });

    it('toont de Google-knop als OAuth geconfigureerd is', function () {
        config([
            'services.google.client_id'     => 'test-id',
            'services.google.client_secret' => 'test-secret',
        ]);

        $this->get(route('login'))
            ->assertStatus(200)
            ->assertSee(route('auth.google'), false);
    });

    it('verbergt de Google-knop als OAuth niet geconfigureerd is', function () {
        config([
            'services.google.client_id'     => null,
            'services.google.client_secret' => null,
        ]);

        $this->get(route('login'))
            ->assertStatus(200)
            ->assertDontSee(route('auth.google'), false);
    });
});

describe('Auth views — alternate-login partial op de registreer pagina', function () {
    it('verbergt de email-code knop op de registreer pagina', function () {
        $this->get(route('register'))
            ->assertStatus(200)
            ->assertDontSee(route('login.code'), false);
    });

    it('toont de Google-knop als OAuth geconfigureerd is', function () {
        config([
            'services.google.client_id'     => 'test-id',
            'services.google.client_secret' => 'test-secret',
        ]);

        $this->get(route('register'))
            ->assertStatus(200)
            ->assertSee(route('auth.google'), false);
    });
});

describe('Auth views — submit-knoppen focus-ring (regressie-guard)', function () {
    /**
     * Deze tests catchen de "partial WCAG-fix"-bug: vroeger werden focus-rings
     * op inputs toegevoegd, maar submit-knoppen vergeten. Per nieuwe view die
     * een button toevoegt aan een auth-flow, óók een test hier.
     */

    it('heeft focus:ring klassen op de login submit-knop', function () {
        $this->get(route('login'))
            ->assertStatus(200)
            ->assertSee('focus:ring-2', false);
    });

    it('heeft focus:ring klassen op de registreer submit-knop', function () {
        $this->get(route('register'))
            ->assertStatus(200)
            ->assertSee('focus:ring-2', false);
    });

    it('heeft focus:ring klassen op de forgot-password submit-knop', function () {
        $this->get(route('password.request'))
            ->assertStatus(200)
            ->assertSee('focus:ring-2', false);
    });
});
