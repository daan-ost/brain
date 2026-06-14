<?php

use App\Http\Controllers\EmailConfirmationController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PostmarkInboundWebhookController;
use App\Http\Controllers\PostmarkWebhookController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SesWebhookController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - Basewebsite
|--------------------------------------------------------------------------
| Core platform routes for auth, profile, organizations, and payments.
| PDF-specific routes have been removed.
*/

// Filament admin login POST fallback (when Livewire JS fails to load)
Route::post('/beheer/login', function (\Illuminate\Http\Request $request) {
    // Get data from Livewire's nested format or regular format
    $data = $request->input('data', $request->all());

    $email = $data['email'] ?? $request->input('email');
    $password = $data['password'] ?? $request->input('password');
    $remember = $data['remember'] ?? $request->boolean('remember');

    if (! $email || ! $password) {
        return back()->withErrors(['email' => 'Email and password are required.']);
    }

    if (Auth::guard('admin')->attempt(['email' => $email, 'password' => $password], $remember)) {
        $user = Auth::guard('admin')->user();
        if ($user->is_admin) {
            $request->session()->regenerate();

            return redirect()->intended('/beheer');
        }
        Auth::guard('admin')->logout();
    }

    return back()->withErrors([
        'email' => 'The provided credentials do not match our records.',
    ])->onlyInput('email');
})->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('filament.admin.auth.login.post');

// =============================================================================
// PUBLIC PAGES
// =============================================================================

// Homepage
Route::get('/', function () {
    return view('pages.home');
})->name('home');

// Legal pages
Route::view('/terms', 'legal.terms')->name('terms');
Route::view('/privacy', 'legal.privacy')->name('privacy');
Route::view('/contact', 'pages.contact')->name('contact');

// Blog placeholder routes
Route::get('/blog', function () {
    return view('pages.blog.index', ['entries' => collect()]);
})->name('blog.index');

Route::get('/blog/{slug}', function ($slug) {
    abort(404, 'Blog post not found');
})->name('blog.show');

// =============================================================================
// DUTCH (NL) LANGUAGE ROUTES
// =============================================================================

Route::prefix('nl')->middleware('web')->group(function () {
    // NL Homepage
    Route::get('/', function () {
        return view('pages.home');
    })->name('nl.home');

    // Blog placeholder routes - Dutch
    Route::get('/blog', function () {
        return view('pages.blog.index', ['entries' => collect()]);
    })->name('nl.blog.index');

    Route::get('/blog/{slug}', function ($slug) {
        abort(404, 'Blog post not found');
    })->name('nl.blog.show');

    // Legal pages - Dutch
    Route::view('/voorwaarden', 'legal.terms')->name('nl.terms');
    Route::view('/privacybeleid', 'legal.privacy')->name('nl.privacy');
    Route::view('/contact', 'pages.contact')->name('nl.contact');

    // Pricing - Dutch
    Route::get('/prijs', function () {
        return view('pricing.wizard');
    })->name('nl.pricing');
});

// =============================================================================
// PRICING & CHECKOUT
// =============================================================================

Route::get('/pricing', function () {
    return view('pricing.wizard');
})->name('pricing');

Route::get('/checkout', function () {
    return view('checkout.wizard');
})->name('checkout');

Route::post('/checkout/order', [App\Http\Controllers\CheckoutController::class, 'createOrder'])->name('checkout.order');
Route::post('/checkout/pay', [App\Http\Controllers\CheckoutController::class, 'startPayment'])->name('checkout.pay');
Route::get('/checkout/return', [App\Http\Controllers\CheckoutController::class, 'return'])->name('checkout.return');
Route::get('/activation', [App\Http\Controllers\CheckoutController::class, 'activation'])->name('activation');

// =============================================================================
// WEBHOOKS (no auth/CSRF)
// =============================================================================

Route::post('/webhooks/postmark', [PostmarkWebhookController::class, 'handle'])
    ->withoutMiddleware(['web'])
    ->middleware('throttle:100,1');

Route::post('/webhooks/postmark/inbound', [PostmarkInboundWebhookController::class, 'handle'])
    ->withoutMiddleware(['web'])
    ->middleware('throttle:30,1')
    ->name('webhooks.postmark.inbound');

Route::post('/webhooks/mollie', [App\Http\Controllers\MollieWebhookController::class, 'handle'])
    ->withoutMiddleware(['web'])
    ->middleware('throttle:50,1')
    ->name('webhooks.mollie');

Route::post('/webhooks/stripe', [App\Http\Controllers\StripeWebhookController::class, 'handle'])
    ->withoutMiddleware(['web'])
    ->middleware('throttle:300,1')
    ->name('webhooks.stripe');

Route::post('/webhooks/ses', [SesWebhookController::class, 'handle'])
    ->withoutMiddleware(['web'])
    ->middleware('throttle:100,1')
    ->name('webhooks.ses');

// =============================================================================
// NEWSLETTER
// =============================================================================

Route::get('/newsletter/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe'])
    ->name('newsletter.unsubscribe');

// =============================================================================
// EMAIL CONFIRMATION
// =============================================================================

Route::get('/email/confirm/{user}/{hash}', [EmailConfirmationController::class, 'confirm'])
    ->middleware('signed')
    ->name('email.confirm');

Route::get('/password/setup/{user}/{hash}', [EmailConfirmationController::class, 'showPasswordSetup'])
    ->middleware('signed')
    ->name('password.setup');

Route::post('/password/setup/{user}/{hash}', [EmailConfirmationController::class, 'storePassword'])
    ->middleware('signed')
    ->name('password.setup.store');

// =============================================================================
// API ROUTES (basic)
// =============================================================================

Route::get('/api/test', function () {
    return response()->json(['status' => 'API is working', 'timestamp' => now()]);
})->name('api.test');

Route::get('/api/orders/{order}', [App\Http\Controllers\CheckoutController::class, 'getOrderStatus'])->name('api.order.status');

// Announcement routes
Route::post('/announcements/dismiss', [App\Http\Controllers\AnnouncementController::class, 'dismiss'])->name('announcements.dismiss');

// =============================================================================
// TWO-FACTOR AUTHENTICATION ROUTES
// =============================================================================

Route::middleware('auth')->group(function () {
    Route::get('/two-factor-challenge', [App\Http\Controllers\TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [App\Http\Controllers\TwoFactorChallengeController::class, 'store']);
});

// =============================================================================
// AUTHENTICATED USER ROUTES
// =============================================================================

Route::middleware(['auth', 'two_factor'])->group(function () {

    // -------------------------------------------------------------------------
    // DASHBOARD
    // -------------------------------------------------------------------------

    Route::get('/dashboard', function () {
        return view('pages.dashboard');
    })->name('dashboard');

    // -------------------------------------------------------------------------
    // PROFILE ROUTES
    // -------------------------------------------------------------------------

    Route::controller(ProfileController::class)->group(function () {
        Route::get('/profile', 'edit')->name('profile.edit');
        Route::get('/profile/account', 'account')->name('profile.account');
        Route::get('/profile/password', 'password')->name('profile.password');
        Route::get('/profile/api-tokens', 'apiTokens')->name('profile.api-tokens');
        Route::get('/profile/webhooks', 'webhooks')->name('profile.webhooks');
        Route::patch('/profile', 'update')->name('profile.update');
        Route::delete('/profile', 'destroy')->name('profile.destroy');
    });

    // Email change verification routes
    Route::controller(App\Http\Controllers\EmailChangeController::class)->group(function () {
        Route::get('/email/change/verify/{token}', 'verify')->name('email.change.verify');
        Route::post('/email/change/cancel', 'cancel')->name('email.change.cancel');
        Route::post('/email/change/resend', 'resend')->name('email.change.resend');
    });

    // Settings routes - Email preferences
    Route::controller(App\Http\Controllers\SettingsController::class)->group(function () {
        Route::get('/profile/email-preferences', 'emailPreferences')->name('profile.email-preferences');
    });

    // Profile sub-pages
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/credits', fn () => redirect()->route('profile.plans'))->name('credits'); // Redirect to plans
        Route::get('/plans', [App\Http\Controllers\Profile\PlanController::class, 'index'])->name('plans');
        Route::post('/plans/{license}/cancel-renewal', [App\Http\Controllers\Profile\PlanController::class, 'cancelRenewal'])->name('plans.cancel-renewal');
        Route::post('/plans/billing-portal', [App\Http\Controllers\Profile\PlanController::class, 'billingPortal'])->name('plans.billing-portal');

        // Invoice routes
        Route::get('/invoice', [App\Http\Controllers\Profile\InvoiceController::class, 'index'])->name('invoice');
        Route::get('/invoices', [App\Http\Controllers\Profile\InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{order}/download', [App\Http\Controllers\Profile\InvoiceController::class, 'download'])->name('invoices.download');

        // Messages routes
        Route::get('/messages', [App\Http\Controllers\Profile\MessageController::class, 'index'])->name('messages');
        Route::post('/messages', [App\Http\Controllers\Profile\MessageController::class, 'store'])->name('messages.store');
        Route::get('/messages/{thread}', [App\Http\Controllers\Profile\MessageController::class, 'show'])->name('messages.show');
        Route::post('/messages/{thread}/reply', [App\Http\Controllers\Profile\MessageController::class, 'reply'])->name('messages.reply');
        Route::get('/messages/{thread}/check', [App\Http\Controllers\Profile\MessageController::class, 'checkNew'])->name('messages.check');
        Route::get('/messages-unread-count', [App\Http\Controllers\Profile\MessageController::class, 'unreadCount'])->name('messages.unread-count');
    });

    // Thread attachment download (signed URL)
    Route::get('/thread/attachment/{path}', function (string $path) {
        $messageService = app(\App\Services\MessageService::class);
        $response = $messageService->downloadAttachment($path);

        if (! $response) {
            abort(404);
        }

        return $response;
    })->name('thread.attachment.download')->middleware('signed');

    // Feedback submission
    Route::post('/feedback', [App\Http\Controllers\FeedbackController::class, 'store'])
        ->name('feedback.store')
        ->middleware('throttle:10,1');

    // -------------------------------------------------------------------------
    // ORGANIZATION ROUTES
    // -------------------------------------------------------------------------

    Route::get('/profile/organization', [App\Http\Controllers\Organization\OrganizationController::class, 'show'])->name('profile.organization');
    Route::patch('/profile/organization', [App\Http\Controllers\Organization\OrganizationController::class, 'update'])->name('profile.organization.update');
    Route::post('/profile/organization', [App\Http\Controllers\Organization\OrganizationController::class, 'store'])->name('profile.organization.create');

    Route::prefix('profile/organization')->name('profile.organization.')->group(function () {
        Route::get('/users', [App\Http\Controllers\Organization\OrganizationUserController::class, 'index'])->name('users');
        Route::patch('/users/{user}/make-admin', [App\Http\Controllers\Organization\OrganizationUserController::class, 'makeAdmin'])->name('users.make-admin');
        Route::patch('/users/{user}/make-member', [App\Http\Controllers\Organization\OrganizationUserController::class, 'demoteToMember'])->name('users.make-member');
        Route::delete('/users/{user}', [App\Http\Controllers\Organization\OrganizationUserController::class, 'removeUser'])->name('users.remove');

        // Invitation routes
        Route::post('/users/invite', [App\Http\Controllers\Organization\InvitationController::class, 'store'])->name('users.invite');
        Route::delete('/invitations/{invitation}', [App\Http\Controllers\Organization\InvitationController::class, 'revoke'])->name('invitations.revoke');
        Route::post('/invitations/{invitation}/resend', [App\Http\Controllers\Organization\InvitationController::class, 'resend'])->name('invitations.resend');

        Route::get('/domains', [App\Http\Controllers\Organization\OrganizationDomainController::class, 'index'])->name('domains');
        Route::post('/domains', [App\Http\Controllers\Organization\OrganizationDomainController::class, 'store'])->name('domains.store');
        Route::put('/domains/{domain}', [App\Http\Controllers\Organization\OrganizationDomainController::class, 'update'])->name('domains.update');
        Route::delete('/domains/{domain}', [App\Http\Controllers\Organization\OrganizationDomainController::class, 'destroy'])->name('domains.destroy');

        Route::get('/transactions', [App\Http\Controllers\Organization\OrganizationTransactionController::class, 'index'])->name('transactions');

        Route::get('/sender-email', fn () => view('profile.organization-sender-email'))
            ->name('sender-email')
            ->middleware(\App\Http\Middleware\FeatureFlag::class.':send_email_functionality');
    });

    // Email confirmation actions
    Route::post('/email/resend', [EmailConfirmationController::class, 'resend'])->name('email.resend');
    Route::patch('/email/change', [EmailConfirmationController::class, 'changeEmail'])->name('email.change');

    // -------------------------------------------------------------------------
    // DEMO ITEMS (feature-flagged)
    // -------------------------------------------------------------------------

    Route::prefix('demo-items')->name('demo-items.')->middleware(\App\Http\Middleware\FeatureFlag::class.':demo_crud')->group(function () {
        Route::get('/', fn () => view('demo-items.index'))->name('index');
        Route::get('/create', fn () => view('demo-items.create'))->name('create');
        Route::get('/{demoItem}', fn (\App\Models\DemoItem $demoItem) => view('demo-items.show', compact('demoItem')))->name('show');
        Route::get('/{demoItem}/edit', fn (\App\Models\DemoItem $demoItem) => view('demo-items.edit', compact('demoItem')))->name('edit');
    });

    // -------------------------------------------------------------------------
    // TRADING (admin) — trades overview + faithful rule-engine replay
    // -------------------------------------------------------------------------
    Route::get('/trades', \App\Livewire\Trades\Index::class)->name('trades.index');
    Route::get('/coin-explorer', \App\Livewire\Trades\CoinExplorer::class)->name('trades.explorer');

    Route::prefix('engine')->name('engine.')->group(function () {
        Route::get('/', [\App\Http\Controllers\EngineController::class, 'index'])->name('index');
        Route::get('/signal/{signal}', [\App\Http\Controllers\EngineController::class, 'signal'])->name('signal');
    });

    // -------------------------------------------------------------------------
    // LIVE MONITOR
    // -------------------------------------------------------------------------

    Route::get('/live-monitor', fn () => view('pages.live-monitor'))->name('live-monitor');
});

// =============================================================================
// LANGUAGE SWITCHING
// =============================================================================

Route::match(['get', 'post'], '/language/switch', [LanguageController::class, 'switch'])->name('language.switch');

// =============================================================================
// PUBLIC INVITATION ROUTES
// =============================================================================

Route::get('/invitations/{token}/accept', [App\Http\Controllers\Organization\InvitationController::class, 'show'])
    ->name('invitations.accept.show');

Route::post('/invitations/{token}/accept', [App\Http\Controllers\Organization\InvitationController::class, 'accept'])
    ->middleware('auth')
    ->name('invitations.accept');

// =============================================================================
// API DOCUMENTATION
// =============================================================================

Route::get('/api/docs', function () {
    return redirect('/api/docs/v1');
})->name('api.docs');

Route::get('/api/docs/v1', function () {
    return view('api-docs.v1');
})->name('api.docs.v1');

// =============================================================================
// AUTH ROUTES
// =============================================================================

require __DIR__.'/auth.php';

// =============================================================================
// DEVELOPMENT ROUTES (only in local/testing environments)
// =============================================================================

if (app()->environment(['local', 'testing'])) {
    Route::prefix('dev')->middleware(['web', 'auth'])->group(function () {
        // Dev Dashboard
        Route::get('/dashboard', function () {
            return view('dev.dashboard');
        })->name('dev.dashboard');

        // Dev Mailbox
        Route::get('/mailbox', function () {
            $mailbox = new \App\Services\DevMailboxService;
            $emails = $mailbox->all();

            return view('dev.mailbox', [
                'emails' => $emails,
                'count' => count($emails),
            ]);
        })->name('dev.mailbox');

        Route::post('/mailbox/clear', function () {
            $mailbox = new \App\Services\DevMailboxService;
            $count = $mailbox->clear();

            return back()->with('success', "Cleared {$count} email(s) from mailbox.");
        })->name('dev.mailbox.clear');

        // Toggle mocks (email)
        Route::post('/toggle-mocks', function (\Illuminate\Http\Request $request) {
            $service = $request->input('service');
            $forceReal = (bool) $request->input('force_real');

            if ($service === 'email') {
                session(['dev_force_real_email' => $forceReal]);
                $status = $forceReal ? 'Real emails enabled' : 'Mock emails enabled (dev mailbox)';
            }

            return back()->with('success', $status ?? 'Unknown service');
        })->name('dev.toggle-mocks');

        // Queue management
        Route::post('/toggle-queue', function (\Illuminate\Http\Request $request) {
            $driver = $request->input('driver', 'sync');

            // Note: In a real scenario, you'd update .env or use a config override
            // For development, we just show the current state
            return back()->with('success', "Queue driver toggle requested: {$driver}. Please update QUEUE_CONNECTION in .env.");
        })->name('dev.toggle-queue');

        Route::post('/queue-worker', function (\Illuminate\Http\Request $request) {
            $action = $request->input('action');

            return back()->with('success', "Queue worker action: {$action}. Use 'php artisan queue:work' in terminal.");
        })->name('dev.queue-worker');

        Route::post('/process-queue', function () {
            \Illuminate\Support\Facades\Artisan::call('queue:work', ['--once' => true]);

            return back()->with('success', 'Processed one job from queue.');
        })->name('dev.process-queue');

        Route::post('/queue-clear', function () {
            \Illuminate\Support\Facades\Artisan::call('queue:clear');

            return back()->with('success', 'Queue cleared.');
        })->name('dev.queue-clear');

        // Announcement management
        Route::post('/clear-announcements', function (\Illuminate\Http\Request $request) {
            $clearAll = $request->input('clear_all', false);

            $count = \App\Models\UserAnnouncement::count();
            \App\Models\UserAnnouncement::truncate();

            if ($clearAll) {
                \Illuminate\Support\Facades\Cache::forget('active_announcement');
            }

            return back()->with('success', "Cleared {$count} announcement record(s)".($clearAll ? ' and cache' : ''));
        })->name('dev.clear-announcements');

        // Dev Docs
        Route::get('/docs', function () {
            return view('dev.docs.index');
        })->name('dev.docs');

        // Test scenarios
        Route::get('/tests', function () {
            return view('dev.tests.index');
        })->name('dev.tests');
    });
}

// =============================================================================
// LOCAL DEVELOPMENT ROUTES
// =============================================================================

if (app()->environment('local')) {
    Route::get('/test-organization-invitation-email', function () {
        $user = \App\Models\User::first();
        $organization = \App\Models\Organization::first();

        if (! $user || ! $organization) {
            return 'Please create a user and organization first.';
        }

        $invitation = \App\Models\Invitation::create([
            'organization_id' => $organization->id,
            'email' => 'test@example.com',
            'invited_by' => $user->id,
            'role' => 'member',
            'status' => 'pending',
        ]);

        \Illuminate\Support\Facades\Mail::to('test@example.com')
            ->send(new \App\Mail\OrganizationInvitation($invitation));

        return 'Test invitation email dispatched! Invitation ID: '.$invitation->id;
    });
}
