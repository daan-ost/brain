<?php

namespace App\Http\Controllers;

use App\Services\NewsletterService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Handles newsletter public actions such as unsubscribing.
 */
class NewsletterController extends Controller
{
    public function __construct(
        private NewsletterService $newsletterService
    ) {}

    /**
     * Handle newsletter unsubscribe request via token.
     *
     * @param  Request  $request  The HTTP request
     * @param  string  $token  The user's unique unsubscribe token
     * @return View The success or failure view
     */
    public function unsubscribe(Request $request, string $token): View
    {
        $user = $this->newsletterService->unsubscribeByToken($token);

        if (! $user) {
            return view('newsletter.unsubscribe-failed', [
                'reason' => 'invalid_token',
            ]);
        }

        return view('newsletter.unsubscribe-success', [
            'user' => $user,
        ]);
    }
}
