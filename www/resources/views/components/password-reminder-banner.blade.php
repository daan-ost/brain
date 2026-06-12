{{--
Password Reminder Banner
Shows for guests with verified email but no password set
--}}

<div
    id="password-reminder-banner"
    class="hidden bg-blue-50 border-b border-blue-200"
    style="display: none;"
>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-3 flex-1">
                <svg class="w-5 h-5 text-blue-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
                <div class="flex-1">
                    <p class="text-sm text-blue-800">
                        <span id="password-reminder-text">
                            {{ __('auth.complete_password_reminder') }}
                        </span>
                        <a
                            href="#"
                            id="password-reminder-link"
                            class="font-semibold underline hover:text-blue-900 ml-1"
                        >
                            {{ __('auth.set_password_now') }}
                        </a>
                    </p>
                </div>
            </div>
            <button
                type="button"
                id="dismiss-password-reminder"
                class="flex-shrink-0 text-blue-600 hover:text-blue-800 transition"
                aria-label="{{ __('auth.dismiss_reminder') }}"
            >
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>
        </div>
    </div>
</div>

@once
<script>
(function() {
    'use strict';

    const STORAGE_KEY = 'app_password_reminder_dismissed';
    const EMAIL_STORAGE_KEY = 'app_guest_email';

    // Check if banner should be shown
    async function checkAndShowBanner() {
        // Don't show if authenticated
        if (window.auth?.check) {
            return;
        }

        // Don't show if previously dismissed
        if (localStorage.getItem(STORAGE_KEY) === 'true') {
            return;
        }

        // Check if there's a saved email
        const savedEmail = localStorage.getItem(EMAIL_STORAGE_KEY);
        if (!savedEmail) {
            return;
        }

        try {
            // Check email status via API
            const response = await fetch(`/api/check-email-status?email=${encodeURIComponent(savedEmail)}`);
            if (!response.ok) {
                return;
            }

            const data = await response.json();

            // Show banner if email is verified but no password set yet
            // This covers the case where user confirmed email but hasn't completed password setup
            if (data.verified && data.status === 'verified' && !data.has_password) {
                showBanner(savedEmail);
            }
        } catch (error) {
            console.error('[PasswordReminder] Failed to check email status:', error);
        }
    }

    // Show the banner
    function showBanner(email) {
        const banner = document.getElementById('password-reminder-banner');
        if (!banner) return;

        // Set up the password reset link with pre-filled email
        const link = document.getElementById('password-reminder-link');
        if (link) {
            link.href = `/{{ app()->getLocale() }}/forgot-password?email=${encodeURIComponent(email)}`;
        }

        // Show banner
        banner.classList.remove('hidden');
        banner.style.display = 'block';

        // Set up dismiss button
        const dismissBtn = document.getElementById('dismiss-password-reminder');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', dismissBanner);
        }
    }

    // Dismiss the banner
    function dismissBanner() {
        const banner = document.getElementById('password-reminder-banner');
        if (banner) {
            banner.style.display = 'none';
            banner.classList.add('hidden');
        }

        // Save dismissal state
        localStorage.setItem(STORAGE_KEY, 'true');
    }

    // Initialize on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkAndShowBanner);
    } else {
        checkAndShowBanner();
    }
})();
</script>
@endonce
