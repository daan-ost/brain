<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Scenarios - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div class="mb-8">
            <a href="/dev/dashboard" class="text-blue-600 hover:underline text-sm">&larr; Back to Dev Dashboard</a>
            <h1 class="text-3xl font-bold text-gray-900 mt-4">Test Scenarios</h1>
            <p class="text-gray-600 mt-1">Manual testing scenarios and utilities</p>
        </div>

        <div class="grid gap-6">
            <!-- Authentication Tests -->
            <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Authentication</h2>
                <div class="grid md:grid-cols-2 gap-4">
                    <a href="{{ route('login') }}" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition">
                        <div class="font-medium text-gray-900">Login Flow</div>
                        <div class="text-sm text-gray-500 mt-1">Test standard login process</div>
                    </a>
                    <a href="{{ route('register') }}" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition">
                        <div class="font-medium text-gray-900">Registration Flow</div>
                        <div class="text-sm text-gray-500 mt-1">Test user registration</div>
                    </a>
                    <a href="{{ route('password.request') }}" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition">
                        <div class="font-medium text-gray-900">Password Reset</div>
                        <div class="text-sm text-gray-500 mt-1">Test forgot password flow</div>
                    </a>
                    @auth
                    <a href="{{ route('profile.account') }}" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition">
                        <div class="font-medium text-gray-900">Profile Settings</div>
                        <div class="text-sm text-gray-500 mt-1">Test profile management</div>
                    </a>
                    @endauth
                </div>
            </div>

            <!-- Organization Tests -->
            @auth
            <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Organizations</h2>
                <div class="grid md:grid-cols-2 gap-4">
                    <a href="{{ route('profile.organization') }}" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition">
                        <div class="font-medium text-gray-900">Organization Overview</div>
                        <div class="text-sm text-gray-500 mt-1">View and manage organization</div>
                    </a>
                    <a href="{{ route('profile.organization.users') }}" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition">
                        <div class="font-medium text-gray-900">Team Management</div>
                        <div class="text-sm text-gray-500 mt-1">Invite and manage users</div>
                    </a>
                    <a href="{{ route('profile.organization.domains') }}" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition">
                        <div class="font-medium text-gray-900">Domain Management</div>
                        <div class="text-sm text-gray-500 mt-1">Configure auto-enrollment domains</div>
                    </a>
                </div>
            </div>
            @endauth

            <!-- Payments Tests -->
            <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Payments</h2>
                <div class="grid md:grid-cols-2 gap-4">
                    <a href="{{ route('pricing') }}" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition">
                        <div class="font-medium text-gray-900">Pricing Page</div>
                        <div class="text-sm text-gray-500 mt-1">View pricing options</div>
                    </a>
                    <a href="{{ route('checkout') }}" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition">
                        <div class="font-medium text-gray-900">Checkout Flow</div>
                        <div class="text-sm text-gray-500 mt-1">Test payment process</div>
                    </a>
                    @auth
                    <a href="{{ route('profile.invoices.index') }}" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition">
                        <div class="font-medium text-gray-900">Invoice History</div>
                        <div class="text-sm text-gray-500 mt-1">View past invoices</div>
                    </a>
                    <a href="{{ route('profile.credits') }}" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition">
                        <div class="font-medium text-gray-900">Credits Overview</div>
                        <div class="text-sm text-gray-500 mt-1">View credit balance and history</div>
                    </a>
                    @endauth
                </div>
            </div>

            <!-- Email Tests -->
            <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Email Testing</h2>
                <div class="grid md:grid-cols-2 gap-4">
                    <a href="/dev/mailbox" class="p-4 border border-gray-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 transition">
                        <div class="font-medium text-gray-900">Dev Mailbox</div>
                        <div class="text-sm text-gray-500 mt-1">View captured emails</div>
                    </a>
                    @if(app()->environment('local'))
                    <a href="/test-organization-invitation-email" class="p-4 border border-gray-200 rounded-lg hover:border-orange-400 hover:bg-orange-50 transition">
                        <div class="font-medium text-gray-900">Test Invitation Email</div>
                        <div class="text-sm text-gray-500 mt-1">Send test organization invite</div>
                    </a>
                    @endif
                </div>
            </div>
        </div>

        <!-- Info -->
        <div class="mt-8 p-4 bg-blue-50 border border-blue-200 rounded-lg">
            <p class="text-sm text-blue-800">
                <strong>Note:</strong> These test scenarios are for development and QA purposes only.
                Additional test scenarios can be added to this page as needed.
            </p>
        </div>
    </div>
</body>
</html>
