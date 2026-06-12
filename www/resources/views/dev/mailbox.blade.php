<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dev Mailbox - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">📬 Dev Mailbox</h1>
                    <p class="text-gray-600 mt-1">Virtual inbox for development emails</p>
                </div>
                <div class="flex gap-2">
                    <a href="/dev/dashboard" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                        ← Dashboard
                    </a>
                    <button onclick="window.location.reload()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        🔄 Refresh
                    </button>
                    <form action="/dev/mailbox/clear" method="POST" onsubmit="return confirm('Clear all emails from mailbox?');">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                            🗑️ Clear All
                        </button>
                    </form>
                </div>
            </div>

            <!-- Status Banner -->
            <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="text-sm text-blue-800">
                    <strong>📨 {{ $count }} email(s)</strong> in mailbox
                    @if($count === 0)
                        - No emails yet. Trigger an action that sends an email (e.g., change email in profile settings).
                    @endif
                </p>
            </div>
        </div>

        <!-- Email List -->
        @if($count > 0)
            <div class="space-y-4">
                @foreach($emails as $email)
                    <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                        <!-- Email Header -->
                        <div class="p-6 border-b border-gray-200">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="px-2 py-1 bg-gray-100 text-gray-700 text-xs font-mono rounded">
                                            {{ $email['to'] }}
                                        </span>
                                        <button
                                            onclick="copyToClipboard('{{ $email['to'] }}', this)"
                                            class="px-2 py-1 bg-gray-100 text-gray-600 text-xs rounded hover:bg-gray-200 transition"
                                            title="Copy email address"
                                        >
                                            📋 Copy
                                        </button>
                                        @if($email['sensitive'])
                                            <span class="px-2 py-1 bg-orange-100 text-orange-700 text-xs rounded">
                                                🔒 Sensitive (1h)
                                            </span>
                                        @endif
                                    </div>
                                    <h3 class="text-xl font-semibold text-gray-900">{{ $email['subject'] }}</h3>
                                    <p class="text-sm text-gray-500 mt-1">
                                        {{ \Carbon\Carbon::parse($email['timestamp'])->diffForHumans() }}
                                        <span class="text-gray-400">•</span>
                                        {{ \Carbon\Carbon::parse($email['timestamp'])->format('Y-m-d H:i:s') }}
                                    </p>
                                </div>
                                <button
                                    onclick="toggleDetails('email-{{ $loop->index }}')"
                                    class="px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition"
                                >
                                    View Data
                                </button>
                            </div>
                        </div>

                        <!-- Email Content -->
                        <div class="p-6">
                            <!-- Verification Link (if present) -->
                            @if(isset($email['data']['verification_url']))
                                <div class="mb-4 flex gap-2">
                                    <a
                                        href="{{ $email['data']['verification_url'] }}"
                                        class="inline-block px-6 py-3 bg-green-600 text-white font-semibold rounded-lg hover:bg-green-700 transition shadow-md"
                                    >
                                        ✅ Click to Verify Email
                                    </a>
                                    <button
                                        onclick="copyToClipboard('{{ $email['data']['verification_url'] }}', this)"
                                        class="px-4 py-3 bg-gray-200 text-gray-700 font-semibold rounded-lg hover:bg-gray-300 transition"
                                        title="Copy link to clipboard"
                                    >
                                        📋 Copy Link
                                    </button>
                                </div>
                            @endif

                            <!-- Password Reset Link (if present) -->
                            @if(isset($email['data']['reset_url']))
                                <div class="mb-4 flex gap-2">
                                    <a
                                        href="{{ $email['data']['reset_url'] }}"
                                        class="inline-block px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition shadow-md"
                                    >
                                        🔑 Reset Password
                                    </a>
                                    <button
                                        onclick="copyToClipboard('{{ $email['data']['reset_url'] }}', this)"
                                        class="px-4 py-3 bg-gray-200 text-gray-700 font-semibold rounded-lg hover:bg-gray-300 transition"
                                        title="Copy link to clipboard"
                                    >
                                        📋 Copy Link
                                    </button>
                                </div>
                            @endif

                            <!-- Invitation Link (if present) -->
                            @if(isset($email['data']['template_model']['invitation_link']))
                                <div class="mb-4 flex gap-2">
                                    <a
                                        href="{{ $email['data']['template_model']['invitation_link'] }}"
                                        class="inline-block px-6 py-3 bg-purple-600 text-white font-semibold rounded-lg hover:bg-purple-700 transition shadow-md"
                                    >
                                        🏢 Accept Invitation
                                    </a>
                                    <button
                                        onclick="copyToClipboard('{{ $email['data']['template_model']['invitation_link'] }}', this)"
                                        class="px-4 py-3 bg-gray-200 text-gray-700 font-semibold rounded-lg hover:bg-gray-300 transition"
                                        title="Copy invitation link to clipboard"
                                    >
                                        📋 Copy Link
                                    </button>
                                </div>
                            @endif

                            <!-- Custom Action URL (if present) -->
                            @if(isset($email['data']['action_url']) && !isset($email['data']['verification_url']) && !isset($email['data']['reset_url']))
                                <div class="mb-4 flex gap-2">
                                    <a
                                        href="{{ $email['data']['action_url'] }}"
                                        class="inline-block px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition shadow-md"
                                    >
                                        {{ $email['data']['action_text'] ?? '🔗 Open Link' }}
                                    </a>
                                    <button
                                        onclick="copyToClipboard('{{ $email['data']['action_url'] }}', this)"
                                        class="px-4 py-3 bg-gray-200 text-gray-700 font-semibold rounded-lg hover:bg-gray-300 transition"
                                        title="Copy link to clipboard"
                                    >
                                        📋 Copy Link
                                    </button>
                                </div>
                            @endif

                            <!-- Collapsible JSON Data -->
                            <div id="email-{{ $loop->index }}" class="hidden mt-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <h4 class="text-sm font-semibold text-gray-700 mb-2">📋 Email Data (JSON)</h4>
                                <pre class="text-xs text-gray-800 overflow-x-auto">{{ json_encode($email['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <!-- Empty State -->
            <div class="bg-white rounded-lg shadow-md p-12 text-center">
                <div class="text-6xl mb-4">📭</div>
                <h3 class="text-2xl font-semibold text-gray-900 mb-2">Mailbox is empty</h3>
                <p class="text-gray-600 mb-6">
                    No emails have been captured yet. Try triggering an action that sends an email:
                </p>
                <ul class="text-left max-w-md mx-auto space-y-2 text-gray-700">
                    <li>• Change your email address in <a href="/profile/account" class="text-blue-600 hover:underline">Profile Settings</a></li>
                    <li>• Request a password reset</li>
                    <li>• Invite a team member to your organization</li>
                </ul>
            </div>
        @endif

        <!-- Footer Info -->
        <div class="mt-8 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
            <p class="text-sm text-yellow-800">
                <strong>ℹ️ Dev Mode Only:</strong>
                This mailbox only works in local/testing environments. Emails are stored in cache (24h retention, 1h for sensitive data). Production always sends real emails.
            </p>
        </div>
    </div>

    <script>
        function toggleDetails(emailId) {
            const element = document.getElementById(emailId);
            element.classList.toggle('hidden');
        }

        function copyToClipboard(text, button) {
            // Use the Clipboard API to copy text
            navigator.clipboard.writeText(text).then(function() {
                // Success feedback
                const originalText = button.innerHTML;
                button.innerHTML = '✅ Copied!';
                button.classList.add('bg-green-100', 'text-green-700');
                button.classList.remove('bg-gray-100', 'bg-gray-200', 'text-gray-600', 'text-gray-700');

                // Reset after 2 seconds
                setTimeout(function() {
                    button.innerHTML = originalText;
                    button.classList.remove('bg-green-100', 'text-green-700');
                    button.classList.add('bg-gray-100', 'text-gray-700');
                }, 2000);
            }).catch(function(err) {
                // Error feedback
                console.error('Failed to copy:', err);
                const originalText = button.innerHTML;
                button.innerHTML = '❌ Failed';
                button.classList.add('bg-red-100', 'text-red-700');

                // Reset after 2 seconds
                setTimeout(function() {
                    button.innerHTML = originalText;
                    button.classList.remove('bg-red-100', 'text-red-700');
                }, 2000);
            });
        }
    </script>
</body>
</html>
