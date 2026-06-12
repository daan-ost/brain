<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dev Dashboard - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-5xl">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Development Dashboard</h1>
            <p class="text-gray-600 mt-1">Manage mock services and development tools</p>
        </div>

        @if(session('success'))
            <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                <p class="text-green-800">{{ session('success') }}</p>
            </div>
        @endif

        <!-- Environment Info -->
        <div class="mb-6 p-6 bg-blue-50 border border-blue-200 rounded-lg">
            <h2 class="text-lg font-semibold text-blue-900 mb-2">Environment</h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="text-blue-700 font-medium">Environment:</span>
                    <span class="ml-2 px-2 py-1 bg-blue-100 rounded text-blue-900 font-mono">{{ config('app.env') }}</span>
                </div>
                <div>
                    <span class="text-blue-700 font-medium">Debug Mode:</span>
                    <span class="ml-2">{{ config('app.debug') ? 'Enabled' : 'Disabled' }}</span>
                </div>
                <div>
                    <span class="text-blue-700 font-medium">Queue Driver:</span>
                    <span class="ml-2 px-2 py-1 bg-blue-100 rounded text-blue-900 font-mono">{{ config('queue.default') }}</span>
                </div>
                <div>
                    <span class="text-blue-700 font-medium">User:</span>
                    <span class="ml-2 text-blue-900">{{ auth()->user()->email ?? 'Guest' }}</span>
                </div>
            </div>
        </div>

        <!-- Mock Services Status -->
        <div class="grid md:grid-cols-2 gap-6 mb-6">

            <!-- Email Mock Status -->
            <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-xl font-semibold text-gray-900">Email Service</h3>
                        <p class="text-sm text-gray-600 mt-1">Postmark / SMTP</p>
                    </div>
                    @php
                        $emailMockEnabled = !\App\Services\DevMailboxService::isEnabled();
                        $sessionForceReal = session('dev_force_real_email', false);
                    @endphp
                    <span class="px-3 py-1 rounded-full text-sm font-medium {{ $emailMockEnabled ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                        {{ $emailMockEnabled ? 'Real' : 'Mocked' }}
                    </span>
                </div>

                <div class="space-y-3">
                    <div class="text-sm text-gray-700">
                        <strong>Config:</strong>
                        @if(config('mail.send_real_emails'))
                            <span class="text-green-700">Send real emails</span>
                        @else
                            <span class="text-yellow-700">Use dev mailbox</span>
                        @endif
                    </div>

                    <div class="text-sm text-gray-700">
                        <strong>Session Override:</strong>
                        @if($sessionForceReal)
                            <span class="text-green-700">Force real (active)</span>
                        @else
                            <span class="text-gray-500">None</span>
                        @endif
                    </div>

                    <div class="pt-3 border-t border-gray-200 flex gap-2">
                        @if(!$emailMockEnabled)
                            <form action="/dev/toggle-mocks" method="POST" class="flex-1">
                                @csrf
                                <input type="hidden" name="service" value="email">
                                <input type="hidden" name="force_real" value="1">
                                <button type="submit" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition text-sm font-medium">
                                    Use Real Emails
                                </button>
                            </form>
                        @else
                            <form action="/dev/toggle-mocks" method="POST" class="flex-1">
                                @csrf
                                <input type="hidden" name="service" value="email">
                                <input type="hidden" name="force_real" value="0">
                                <button type="submit" class="w-full px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition text-sm font-medium">
                                    Use Mock (Dev Mailbox)
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Queue Driver -->
            <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-xl font-semibold text-gray-900">Queue Driver</h3>
                        <p class="text-sm text-gray-600 mt-1">Job processing</p>
                    </div>
                    @php
                        $queueDriver = config('queue.default');
                        $isSync = $queueDriver === 'sync';

                        // Get queue stats
                        $pendingJobs = 0;
                        $workerRunning = false;

                        if (!$isSync && $queueDriver === 'database') {
                            try {
                                $pendingJobs = DB::table('jobs')->count();

                                // Check if queue worker is running
                                $output = shell_exec('pgrep -f "queue:work" 2>/dev/null');
                                $workerRunning = !empty(trim($output ?? ''));
                            } catch (\Exception $e) {
                                // Silently handle errors
                            }
                        }
                    @endphp
                    <span class="px-3 py-1 rounded-full text-sm font-medium {{ $isSync ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                        {{ $isSync ? 'Sync' : 'Queued' }}
                    </span>
                </div>

                <div class="space-y-3">
                    <div class="text-sm text-gray-700">
                        <strong>Current Driver:</strong>
                        <span class="ml-2 px-2 py-1 bg-gray-100 rounded font-mono text-gray-800">{{ $queueDriver }}</span>
                    </div>

                    @if(!$isSync)
                        <div class="text-sm text-gray-700">
                            <strong>Pending Jobs:</strong>
                            <span class="ml-2 px-2 py-1 {{ $pendingJobs > 0 ? 'bg-orange-100 text-orange-800' : 'bg-green-100 text-green-800' }} rounded font-mono">
                                {{ $pendingJobs }}
                            </span>
                        </div>

                        <div class="text-sm text-gray-700">
                            <strong>Worker Status:</strong>
                            <span class="ml-2 px-2 py-1 {{ $workerRunning ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }} rounded">
                                {{ $workerRunning ? 'Running' : 'Stopped' }}
                            </span>
                        </div>
                    @else
                        <div class="text-sm text-gray-700">
                            <strong>Behavior:</strong>
                            <span class="text-green-700">Jobs processed immediately</span>
                        </div>
                    @endif

                    <!-- Driver Toggle -->
                    <div class="pt-3 border-t border-gray-200 flex gap-2">
                        @if($isSync)
                            <form action="/dev/toggle-queue" method="POST" class="flex-1">
                                @csrf
                                <input type="hidden" name="driver" value="database">
                                <button type="submit" class="w-full px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition text-sm font-medium">
                                    Switch to Database Queue
                                </button>
                            </form>
                        @else
                            <form action="/dev/toggle-queue" method="POST" class="flex-1">
                                @csrf
                                <input type="hidden" name="driver" value="sync">
                                <button type="submit" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition text-sm font-medium">
                                    Switch to Sync (Instant)
                                </button>
                            </form>
                        @endif
                    </div>

                    <!-- Queue Management (only for database queue) -->
                    @if(!$isSync && $queueDriver === 'database')
                        <div class="pt-2 space-y-2">
                            <!-- Worker Control -->
                            <div class="flex gap-2">
                                @if($workerRunning)
                                    <form action="/dev/queue-worker" method="POST" class="flex-1">
                                        @csrf
                                        <input type="hidden" name="action" value="stop">
                                        <button type="submit" class="w-full px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition text-sm font-medium">
                                            Stop Worker
                                        </button>
                                    </form>
                                    <form action="/dev/queue-worker" method="POST" class="flex-1">
                                        @csrf
                                        <input type="hidden" name="action" value="restart">
                                        <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-sm font-medium">
                                            Restart Worker
                                        </button>
                                    </form>
                                @else
                                    <form action="/dev/queue-worker" method="POST" class="flex-1">
                                        @csrf
                                        <input type="hidden" name="action" value="start">
                                        <button type="submit" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition text-sm font-medium">
                                            Start Worker
                                        </button>
                                    </form>
                                    <form action="/dev/process-queue" method="POST" class="flex-1">
                                        @csrf
                                        <button type="submit" class="w-full px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition text-sm font-medium">
                                            Process Once
                                        </button>
                                    </form>
                                @endif
                            </div>

                            <!-- Clear Queue -->
                            @if($pendingJobs > 0)
                                <form action="/dev/queue-clear" method="POST" onsubmit="return confirm('Clear {{ $pendingJobs }} job(s) from queue?');">
                                    @csrf
                                    <button type="submit" class="w-full px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition text-sm font-medium">
                                        Clear Queue ({{ $pendingJobs }} jobs)
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Cookie Consent Tools -->
        <div class="mb-6">
            <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-xl font-semibold text-gray-900">Cookie Consent (Klaro)</h3>
                        <p class="text-sm text-gray-600 mt-1">Manage cookie consent state</p>
                    </div>
                    <span class="px-3 py-1 rounded-full text-sm font-medium" id="consent-status-badge">
                        <span class="consent-given hidden">Given</span>
                        <span class="consent-none">Not set</span>
                    </span>
                </div>

                <div class="space-y-3">
                    <div class="text-sm text-gray-700">
                        <strong>Storage Key:</strong>
                        <span class="ml-2 px-2 py-1 bg-gray-100 rounded font-mono text-gray-800">{{ config('app.name', 'app') }}_consent</span>
                    </div>

                    <div class="text-sm text-gray-700" id="consent-details">
                        <strong>Current Value:</strong>
                        <span class="ml-2 text-gray-500" id="consent-value">Loading...</span>
                    </div>

                    <div class="pt-3 border-t border-gray-200">
                        <button
                            id="reset-consent-btn"
                            onclick="resetCookieConsent()"
                            class="w-full px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition text-sm font-medium">
                            Reset Cookie Consent
                        </button>
                    </div>

                    <div class="text-xs text-gray-500 pt-2">
                        After resetting, refresh any page to see the cookie banner again
                    </div>
                </div>
            </div>
        </div>

        <!-- Announcement Tools -->
        <div class="mb-6">
            <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-xl font-semibold text-gray-900">Announcement Tools</h3>
                        <p class="text-sm text-gray-600 mt-1">Reset announcement seen status</p>
                    </div>
                    @php
                        $userAnnouncementCount = \App\Models\UserAnnouncement::count();
                        $activeAnnouncement = \App\Services\AnnouncementService::getActiveAnnouncement();
                    @endphp
                    <span class="px-3 py-1 rounded-full text-sm font-medium {{ $activeAnnouncement ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                        {{ $activeAnnouncement ? 'Active' : 'None' }}
                    </span>
                </div>

                <div class="space-y-3">
                    <div class="text-sm text-gray-700">
                        <strong>Active Announcement:</strong>
                        <span class="ml-2">{{ $activeAnnouncement ? $activeAnnouncement->getTitle('en') : 'No active announcement' }}</span>
                    </div>

                    <div class="text-sm text-gray-700">
                        <strong>User Seen Records:</strong>
                        <span class="ml-2 px-2 py-1 bg-gray-100 rounded font-mono text-gray-800">{{ $userAnnouncementCount }}</span>
                    </div>

                    <div class="pt-3 border-t border-gray-200 space-y-2">
                        <button
                            onclick="resetAnnouncementCookies()"
                            class="w-full px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition text-sm font-medium">
                            Clear Announcement Cookies (Browser)
                        </button>

                        <form action="/dev/clear-announcements" method="POST" onsubmit="return confirm('This will delete all {{ $userAnnouncementCount }} user announcement records. Continue?');">
                            @csrf
                            <button type="submit" class="w-full px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition text-sm font-medium">
                                Clear User Announcements Table ({{ $userAnnouncementCount }} records)
                            </button>
                        </form>

                        <form action="/dev/clear-announcements" method="POST">
                            @csrf
                            <input type="hidden" name="clear_all" value="1">
                            <button type="submit" onclick="resetAnnouncementCookies(); return confirm('Clear all cookies AND database records?');" class="w-full px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition text-sm font-medium">
                                Reset Everything (Cookies + Database + Cache)
                            </button>
                        </form>
                    </div>

                    <div class="text-xs text-gray-500 pt-2">
                        After resetting, the announcement modal will appear again on page refresh
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
            <h3 class="text-xl font-semibold text-gray-900 mb-4">Quick Links</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="/dev/mailbox" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition text-center">
                    <div class="text-2xl mb-2">📬</div>
                    <div class="text-sm font-medium text-gray-900">Dev Mailbox</div>
                </a>
                <a href="/dev/docs" class="p-4 border border-gray-200 rounded-lg hover:border-green-400 hover:bg-green-50 transition text-center">
                    <div class="text-2xl mb-2">📚</div>
                    <div class="text-sm font-medium text-gray-900">Dev Docs</div>
                </a>
                <a href="/profile/account" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition text-center">
                    <div class="text-2xl mb-2">👤</div>
                    <div class="text-sm font-medium text-gray-900">Profile Settings</div>
                </a>
                <a href="/beheer/dashboard" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition text-center">
                    <div class="text-2xl mb-2">⚙️</div>
                    <div class="text-sm font-medium text-gray-900">Admin Panel</div>
                </a>
            </div>
        </div>

        <!-- Info Footer -->
        <div class="mt-8 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
            <p class="text-sm text-yellow-800">
                <strong>Security Notice:</strong>
                This dashboard is only accessible in development/testing environments. Production environment is hard-blocked and will return 404.
            </p>
        </div>
    </div>

    <script>
        // Cookie Consent Functions
        const consentKey = '{{ config('app.name', 'app') }}_consent'.toLowerCase().replace(/\s+/g, '_');

        function resetCookieConsent() {
            localStorage.removeItem(consentKey);
            updateConsentUI();
            alert('Cookie consent has been reset. Refresh any page to see the banner again.');
        }

        function updateConsentUI() {
            const consent = localStorage.getItem(consentKey);
            const badge = document.getElementById('consent-status-badge');
            const valueEl = document.getElementById('consent-value');

            const givenEls = badge.querySelectorAll('.consent-given');
            const noneEls = badge.querySelectorAll('.consent-none');

            if (consent) {
                // Parse and display consent info
                try {
                    const parsed = JSON.parse(consent);
                    valueEl.innerHTML = '<code class="text-xs bg-gray-100 p-1 rounded break-all">' + consent.substring(0, 100) + (consent.length > 100 ? '...' : '') + '</code>';
                } catch (e) {
                    valueEl.textContent = consent.substring(0, 50) + '...';
                }

                givenEls.forEach(el => el.classList.remove('hidden'));
                noneEls.forEach(el => el.classList.add('hidden'));
                badge.classList.add('bg-green-100', 'text-green-800');
                badge.classList.remove('bg-gray-100', 'text-gray-800');
            } else {
                valueEl.textContent = 'No consent stored';

                givenEls.forEach(el => el.classList.add('hidden'));
                noneEls.forEach(el => el.classList.remove('hidden'));
                badge.classList.add('bg-gray-100', 'text-gray-800');
                badge.classList.remove('bg-green-100', 'text-green-800');
            }
        }

        // Initialize consent UI on page load
        document.addEventListener('DOMContentLoaded', updateConsentUI);

        // Announcement Cookie Functions
        function resetAnnouncementCookies() {
            // Get all cookies and find announcement_seen_* cookies
            const cookies = document.cookie.split(';');
            let clearedCount = 0;

            cookies.forEach(cookie => {
                const cookieName = cookie.split('=')[0].trim();
                if (cookieName.startsWith('announcement_seen_')) {
                    // Delete the cookie by setting expiry to past
                    document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
                    clearedCount++;
                }
            });

            if (clearedCount > 0) {
                alert(`Cleared ${clearedCount} announcement cookie(s). Refresh any page to see the announcement again.`);
            } else {
                alert('No announcement cookies found to clear.');
            }
        }
    </script>
</body>
</html>
