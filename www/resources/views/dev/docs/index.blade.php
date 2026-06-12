<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dev Documentation - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div class="mb-8">
            <a href="/dev/dashboard" class="text-blue-600 hover:underline text-sm">&larr; Back to Dev Dashboard</a>
            <h1 class="text-3xl font-bold text-gray-900 mt-4">Development Documentation</h1>
            <p class="text-gray-600 mt-1">Internal documentation and guides</p>
        </div>

        <div class="bg-white rounded-lg shadow-md p-8 border border-gray-200">
            <div class="text-center py-12">
                <svg class="mx-auto h-16 w-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <h3 class="mt-4 text-lg font-medium text-gray-900">Documentation Coming Soon</h3>
                <p class="mt-2 text-sm text-gray-500">
                    Add your markdown documentation files to the <code class="bg-gray-100 px-2 py-1 rounded">/docs</code> folder.
                </p>
            </div>

            <div class="mt-8 border-t border-gray-200 pt-8">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Getting Started</h2>
                <ul class="space-y-3 text-gray-600">
                    <li class="flex items-start">
                        <span class="text-green-500 mr-2">1.</span>
                        Create a <code class="bg-gray-100 px-1 rounded">/docs</code> folder in your project root
                    </li>
                    <li class="flex items-start">
                        <span class="text-green-500 mr-2">2.</span>
                        Add markdown files (*.md) with your documentation
                    </li>
                    <li class="flex items-start">
                        <span class="text-green-500 mr-2">3.</span>
                        Organize files in subdirectories as needed
                    </li>
                    <li class="flex items-start">
                        <span class="text-green-500 mr-2">4.</span>
                        Implement a DocsController to render the markdown files
                    </li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
