<?php

namespace App\Livewire\Profile;

use App\Jobs\SendPostmarkTemplateEmail;
use App\Services\AnalyticsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Component;

/**
 * Livewire component for managing API tokens
 *
 * Allows users to create, view, and revoke personal access tokens for API authentication.
 * Includes duplicate name validation and displays newly created tokens once for security.
 */
class ApiTokenManager extends Component
{
    /** @var string The name for the new token being created */
    public $tokenName = '';

    /** @var string|null The plain text value of a newly created token (shown once) */
    public $newTokenValue = null;

    /** @var bool Whether to show the token display modal */
    public $showTokenModal = false;

    /**
     * Get validation rules for token creation
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'tokenName' => [
                'required',
                'string',
                'max:255',
                Rule::unique('personal_access_tokens', 'name')
                    ->where('tokenable_id', Auth::id())
                    ->where('tokenable_type', get_class(Auth::user())),
            ],
        ];
    }

    /**
     * Get custom validation error messages
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'tokenName.required' => __('profile.token_name_required'),
            'tokenName.unique' => __('profile.token_name_duplicate'),
            'tokenName.max' => __('profile.token_name_too_long'),
        ];
    }

    /**
     * Create a new API token for the authenticated user
     *
     * Validates the token name, creates the token, and stores the plain text value
     * for one-time display. Logs analytics, dispatches events, and sends confirmation email.
     *
     * @return void
     */
    public function createToken(): void
    {
        $this->validate();

        try {
            $user = Auth::user();
            $token = $user->createToken($this->tokenName);
            $ipAddress = request()->ip();
            $tokenName = $this->tokenName;

            // Store the plain text token to show once
            $this->newTokenValue = $token->plainTextToken;
            $this->showTokenModal = true;

            AnalyticsService::log('api_token_created', [
                'token_id' => $token->accessToken->id,
                'token_name' => $tokenName,
            ]);

            // Send confirmation email
            $this->sendTokenActionEmail($user, $tokenName, 'created', $ipAddress);

            // Reset form using Livewire's reset method
            $this->reset('tokenName');

            $this->dispatch('token-created');
        } catch (\Exception $e) {
            $this->addError('tokenName', __('profile.token_creation_failed'));
            \Log::error('Token creation failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Revoke (delete) an existing API token
     *
     * Verifies the token belongs to the current user before deletion.
     * Logs analytics, dispatches events, and sends confirmation email.
     *
     * @param int $tokenId The ID of the token to revoke
     * @return void
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If token not found
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If unauthorized
     */
    public function revokeToken(int $tokenId): void
    {
        $token = PersonalAccessToken::findOrFail($tokenId);

        // Verify token belongs to current user
        if ($token->tokenable_id !== Auth::id()) {
            abort(403, 'Unauthorized');
        }

        $user = Auth::user();
        $tokenName = $token->name;
        $ipAddress = request()->ip();

        $token->delete();

        AnalyticsService::log('api_token_revoked', [
            'token_id' => $tokenId,
            'token_name' => $tokenName,
        ]);

        // Send confirmation email
        $this->sendTokenActionEmail($user, $tokenName, 'revoked', $ipAddress);

        $this->dispatch('token-revoked');
    }

    /**
     * Close the token display modal
     *
     * Clears the stored plain text token value for security.
     *
     * @return void
     */
    public function closeTokenModal(): void
    {
        $this->showTokenModal = false;
        $this->newTokenValue = null;
    }

    /**
     * Send email notification for token action
     *
     * Sends a security alert email to the user when a token is created or revoked.
     * Email is sent in the user's preferred language with token details and IP address.
     *
     * @param \App\Models\User $user The user to send the email to
     * @param string $tokenName The name of the token
     * @param string $action The action performed ('created' or 'revoked')
     * @param string $ipAddress The IP address from which the action was performed
     * @return void
     */
    private function sendTokenActionEmail($user, string $tokenName, string $action, string $ipAddress): void
    {
        try {
            // Get user's locale
            $locale = $user->preferred_language ?? app()->getLocale();
            if (! in_array($locale, ['en', 'nl'])) {
                $locale = 'en';
            }

            // Translate action based on locale
            $actionTranslated = $this->translateAction($action, $locale);

            // Build template data
            $templateData = [
                'user_name' => $user->name,
                'token_name' => $tokenName,
                'action' => $actionTranslated,
                'action_datetime' => now()->format('d-m-Y H:i:s'),
                'ip_address' => $ipAddress,
                'support_email' => config('postmark.from_email'),
            ];

            // Dispatch email job
            SendPostmarkTemplateEmail::dispatch(
                templateAlias: "api-token-updated__{$locale}",
                templateModel: $templateData,
                to: $user->email,
                toName: $user->name,
                tag: 'api-token-updated',
                messageStream: 'outbound'
            );

            // Log analytics event for email sending
            AnalyticsService::log('api_token_email_sent', [
                'token_name' => $tokenName,
                'action' => $action,
                'recipient_email' => $user->email,
                'ip_address' => $ipAddress,
            ]);

        } catch (\Exception $e) {
            // Log error but don't fail the token operation
            \Log::error('Failed to send API token email', [
                'user_id' => $user->id,
                'token_name' => $tokenName,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Translate action based on locale
     *
     * @param string $action The action ('created' or 'revoked')
     * @param string $locale The locale ('nl' or 'en')
     * @return string The translated action
     */
    private function translateAction(string $action, string $locale): string
    {
        $translations = [
            'nl' => [
                'created' => 'aangemaakt',
                'revoked' => 'ingetrokken',
            ],
            'en' => [
                'created' => 'created',
                'revoked' => 'revoked',
            ],
        ];

        return $translations[$locale][$action] ?? $action;
    }

    /**
     * Render the component view
     *
     * Loads all tokens for the authenticated user ordered by creation date.
     *
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        $tokens = Auth::user()->tokens()->orderBy('created_at', 'desc')->get();

        return view('livewire.profile.api-token-manager', [
            'tokens' => $tokens,
        ]);
    }
}
