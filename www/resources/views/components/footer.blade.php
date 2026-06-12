<footer class="bg-gray-900 text-gray-300">
    <div class="container mx-auto px-4 py-12">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
            <!-- Brand -->
            <div class="col-span-1 md:col-span-2">
                <h3 class="text-white text-xl font-bold mb-4">{{ config('app.name', 'Basewebsite') }}</h3>
                <p class="text-gray-400 mb-4">
                    Your SaaS platform description goes here.
                </p>
            </div>

            <!-- Links -->
            <div>
                <h4 class="text-white font-semibold mb-4">Links</h4>
                <ul class="space-y-2">
                    <li><a href="{{ url('/') }}" class="hover:text-white transition-colors">Home</a></li>
                    <li><a href="{{ url('/pricing') }}" class="hover:text-white transition-colors">Pricing</a></li>
                    <li><a href="{{ url('/contact') }}" class="hover:text-white transition-colors">Contact</a></li>
                </ul>
            </div>

            <!-- Legal -->
            <div>
                <h4 class="text-white font-semibold mb-4">Legal</h4>
                <ul class="space-y-2">
                    <li><a href="{{ url('/privacy') }}" class="hover:text-white transition-colors">Privacy Policy</a></li>
                    <li><a href="{{ url('/terms') }}" class="hover:text-white transition-colors">Terms of Service</a></li>
                </ul>
            </div>
        </div>

        <div class="border-t border-gray-800 mt-8 pt-8 text-center text-gray-500">
            <p>&copy; {{ date('Y') }} {{ config('app.name', 'Basewebsite') }}. All rights reserved.</p>
        </div>
    </div>
</footer>
