@extends('layouts.homepage-standalone')

@section('title', 'Terms of Service')

@section('content')
<div class="min-h-screen bg-white">
    <!-- Header -->
    <header class="gradient-header py-6" style="background: linear-gradient(135deg, #9FD6D2 0%, #53B3AE 100%);">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between">
                <a href="#" class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-sm">
                        <span class="text-xl font-bold text-blue-600">P</span>
                    </div>
                </a>
                <a href="#" class="text-white hover:text-gray-200 transition-colors text-sm font-medium">
                    ← Back to Home
                </a>
            </div>
        </div>
    </header>

    <!-- Content -->
    <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="prose prose-lg max-w-none">
            <h1 class="text-4xl font-bold text-gray-900 mb-8">Terms of Service</h1>

            <div class="text-sm text-gray-500 mb-8">Last updated: January 1, 2024</div>

            <div class="space-y-8">
                <section>
                    <h2 class="text-2xl font-semibold text-gray-900 mb-4">1. Acceptance of Terms</h2>
                    <p class="text-gray-700 leading-relaxed mb-4">
                        By accessing and using {{ config('app.name') }} ("Service"), you accept and agree to be bound by the terms and
                        provision of this agreement.
                    </p>
                    <p class="text-gray-700 leading-relaxed">
                        If you do not agree to abide by the above, please do not use this service.
                    </p>
                </section>

                <section>
                    <h2 class="text-2xl font-semibold text-gray-900 mb-4">2. Use License</h2>
                    <p class="text-gray-700 leading-relaxed mb-4">
                        Permission is granted to temporarily download one copy of {{ config('app.name') }} per device for personal,
                        non-commercial transitory viewing only.
                    </p>
                    <p class="text-gray-700 leading-relaxed mb-4">This license shall not permit you to:</p>
                    <ul class="list-disc pl-6 space-y-2 text-gray-700">
                        <li>Modify or copy the materials</li>
                        <li>Use the materials for any commercial purpose or for any public display</li>
                        <li>Attempt to reverse engineer any software contained on the website</li>
                        <li>Remove any copyright or other proprietary notations from the materials</li>
                    </ul>
                </section>

                <section>
                    <h2 class="text-2xl font-semibold text-gray-900 mb-4">3. Disclaimer</h2>
                    <p class="text-gray-700 leading-relaxed mb-4">
                        The materials on {{ config('app.name') }} are provided on an 'as is' basis. {{ config('app.name') }} makes no warranties, expressed or
                        implied, and hereby disclaims and negates all other warranties including without limitation, implied
                        warranties or conditions of merchantability, fitness for a particular purpose, or non-infringement of
                        intellectual property or other violation of rights.
                    </p>
                </section>

                <section>
                    <h2 class="text-2xl font-semibold text-gray-900 mb-4">4. Limitations</h2>
                    <p class="text-gray-700 leading-relaxed">
                        In no event shall {{ config('app.name') }} or its suppliers be liable for any damages (including, without limitation,
                        damages for loss of data or profit, or due to business interruption) arising out of the use or inability
                        to use the materials on {{ config('app.name') }}, even if {{ config('app.name') }} or an authorized representative has been notified
                        orally or in writing of the possibility of such damage.
                    </p>
                </section>

                <section>
                    <h2 class="text-2xl font-semibold text-gray-900 mb-4">5. Privacy Policy</h2>
                    <p class="text-gray-700 leading-relaxed">
                        Your privacy is important to us. Please review our Privacy Policy, which also governs your use of the
                        Service, to understand our practices.
                    </p>
                </section>

                <section>
                    <h2 class="text-2xl font-semibold text-gray-900 mb-4">6. File Processing and Data Handling</h2>
                    <p class="text-gray-700 leading-relaxed mb-4">
                        When you upload files to our service:
                    </p>
                    <ul class="list-disc pl-6 space-y-2 text-gray-700">
                        <li>Files are processed securely using 256-bit SSL encryption</li>
                        <li>All files are automatically deleted from our servers within 24 hours</li>
                        <li>We do not store, share, or analyze your document content</li>
                        <li>Processing is done in compliance with GDPR and other data protection regulations</li>
                    </ul>
                </section>

                <section>
                    <h2 class="text-2xl font-semibold text-gray-900 mb-4">7. Service Availability</h2>
                    <p class="text-gray-700 leading-relaxed">
                        We strive to maintain 99.9% uptime but cannot guarantee uninterrupted service. Scheduled maintenance
                        will be announced in advance when possible.
                    </p>
                </section>

                <section>
                    <h2 class="text-2xl font-semibold text-gray-900 mb-4">8. Contact Information</h2>
                    <p class="text-gray-700 leading-relaxed">
                        If you have any questions about these Terms of Service, please contact us at:
                    </p>
                    <div class="bg-gray-50 rounded-lg p-6 mt-4">
                        <p class="text-gray-700 font-medium text-lg mb-2">{{ config('app.name') }} Support</p>
                        <p class="text-gray-600 mb-1">Email: {{ config('mail.from.address') }}</p>
                        <p class="text-gray-600 mb-1">Website: {{ env('INVOICE_COMPANY_WEBSITE', 'example.com') }}</p>
                        <p class="text-gray-600">Response time: Within 24 hours</p>
                    </div>
                </section>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gradient-to-br from-gray-900 via-gray-800 to-slate-900 text-white">
        <div class="border-b border-gray-700/30">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 text-center">
                <h2 class="text-3xl md:text-4xl font-bold mb-6 font-display">
                    Ready to Streamline Your<br />PDF Workflows?
                </h2>
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <button class="bg-orange-500 hover:bg-orange-600 text-white px-8 py-3 rounded-lg font-semibold transition-colors">
                        Get Started for Free
                    </button>
                    <button class="border-2 border-blue-500 hover:border-blue-400 text-blue-500 hover:text-blue-400 px-8 py-3 rounded-lg font-semibold transition-colors">
                        Contact Sales
                    </button>
                </div>
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="text-center">
                <div class="flex items-center justify-center space-x-2 mb-6">
                    <div class="w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center">
                        <span class="text-xl font-bold text-white">P</span>
                    </div>
                    <span class="font-bold text-xl text-white">{{ config('app.name') }}</span>
                </div>
                <p class="text-gray-300 text-sm">© 2011 - 2024 {{ config('app.name') }}. All rights reserved.</p>
            </div>
        </div>
    </footer>
</div>
@endsection
