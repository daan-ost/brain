<header class="bg-gradient-to-r from-blue-600 to-blue-800 border-b border-blue-700">
    <div class="container mx-auto px-4 py-4">
        <div class="flex items-center justify-between">
            <!-- Logo -->
            <div class="flex items-center">
                <a href="{{ url('/') }}" class="text-white text-xl font-bold hover:opacity-80 transition-opacity">
                    {{ config('app.name', 'Basewebsite') }}
                </a>
            </div>

            <!-- Desktop Navigation -->
            <nav class="hidden md:flex items-center space-x-6">
                <a href="{{ url('/') }}" class="text-white hover:text-white/80 transition-colors font-medium">
                    {{ __('ui.nav_home') }}
                </a>
                <a href="{{ url('/pricing') }}" class="text-white hover:text-white/80 transition-colors font-medium">
                    {{ __('ui.nav_pricing') }}
                </a>
            </nav>

            <!-- Right Side: Language + Auth -->
            <div class="hidden md:flex items-center space-x-4">
                <!-- Language Selector -->
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open"
                        class="flex items-center space-x-1 text-white hover:bg-white/10 px-3 py-2 rounded-md transition-colors">
                        @if(app()->getLocale() === 'nl')
                            <span class="text-lg">🇳🇱</span>
                            <span class="text-sm font-medium">NL</span>
                        @else
                            <span class="text-lg">🇬🇧</span>
                            <span class="text-sm font-medium">EN</span>
                        @endif
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div x-show="open" @click.away="open = false"
                        x-transition
                        class="absolute top-full right-0 mt-2 w-36 bg-white rounded-lg shadow-xl overflow-hidden"
                        style="display: none; z-index: 9999;">
                        <form action="{{ route('language.switch') }}" method="POST">
                            @csrf
                            <input type="hidden" name="redirect" value="{{ url()->current() }}">
                            <input type="hidden" name="locale" value="en">
                            <button type="submit"
                                class="w-full flex items-center space-x-3 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 transition-colors {{ app()->getLocale() === 'en' ? 'bg-blue-50 text-blue-700' : '' }}">
                                <span class="text-lg">🇬🇧</span>
                                <span>English</span>
                                @if(app()->getLocale() === 'en')
                                    <svg class="w-4 h-4 ml-auto text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                    </svg>
                                @endif
                            </button>
                        </form>
                        <form action="{{ route('language.switch') }}" method="POST">
                            @csrf
                            <input type="hidden" name="redirect" value="{{ url()->current() }}">
                            <input type="hidden" name="locale" value="nl">
                            <button type="submit"
                                class="w-full flex items-center space-x-3 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 transition-colors {{ app()->getLocale() === 'nl' ? 'bg-blue-50 text-blue-700' : '' }}">
                                <span class="text-lg">🇳🇱</span>
                                <span>Nederlands</span>
                                @if(app()->getLocale() === 'nl')
                                    <svg class="w-4 h-4 ml-auto text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                    </svg>
                                @endif
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Auth Buttons -->
                @guest
                <a href="{{ route('login') }}"
                    class="text-white hover:bg-white/10 px-4 py-2 rounded-md transition-colors">
                    {{ __('ui.nav_login') }}
                </a>
                <a href="{{ route('register') }}"
                    class="bg-white text-blue-600 hover:bg-white/90 shadow-sm px-4 py-2 rounded-md transition-colors font-semibold">
                    {{ __('ui.nav_signup') }}
                </a>
                @endguest

                @auth
                <!-- Profile Dropdown -->
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open"
                        class="flex items-center justify-center w-10 h-10 rounded-full bg-white/20 hover:bg-white/30 transition-colors">
                        <div class="w-8 h-8 rounded-full bg-white flex items-center justify-center">
                            <svg class="w-5 h-5 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                            </svg>
                        </div>
                    </button>

                    <div x-show="open" @click.away="open = false"
                        x-transition
                        class="absolute top-full right-0 mt-2 w-48 p-2 bg-white rounded-xl shadow-xl"
                        style="display: none; z-index: 9999;">
                        <a href="{{ route('dashboard') }}"
                            class="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-lg">
                            {{ __('ui.nav_dashboard') }}
                        </a>
                        <a href="{{ route('profile.edit') }}"
                            class="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-lg">
                            {{ __('ui.nav_profile') }}
                        </a>
                        <div class="border-t border-gray-200 my-1"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"
                                class="block w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-lg">
                                {{ __('ui.nav_logout') }}
                            </button>
                        </form>
                    </div>
                </div>
                @endauth
            </div>

            <!-- Mobile Menu Button -->
            <div class="md:hidden relative" x-data="{ open: false }">
                <button @click="open = !open" :aria-expanded="open" aria-label="Toggle navigation menu" class="text-white min-h-[44px] min-w-[44px] flex items-center justify-center">
                    <svg x-show="!open" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                    <svg x-show="open" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: none;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>

                <!-- Mobile Menu -->
                <div class="absolute left-0 right-0 top-full bg-blue-700 px-4 pb-4" x-show="open" x-transition style="display: none;">
                    <nav class="flex flex-col space-y-2 pt-2">
                        <a href="{{ url('/') }}" class="text-white hover:bg-white/10 px-4 py-3 rounded-md transition-colors">{{ __('ui.nav_home') }}</a>
                        <a href="{{ url('/pricing') }}" class="text-white hover:bg-white/10 px-4 py-3 rounded-md transition-colors">{{ __('ui.nav_pricing') }}</a>

                        <!-- Mobile Language Selector -->
                        <div class="border-t border-white/20 pt-2 mt-2">
                            <div class="flex space-x-2">
                                <form action="{{ route('language.switch') }}" method="POST" class="flex-1">
                                    @csrf
                                    <input type="hidden" name="redirect" value="{{ url()->current() }}">
                                    <input type="hidden" name="locale" value="en">
                                    <button type="submit" class="w-full flex items-center justify-center space-x-2 px-4 py-2 rounded-md transition-colors {{ app()->getLocale() === 'en' ? 'bg-white text-blue-600' : 'bg-white/10 text-white hover:bg-white/20' }}">
                                        <span>🇬🇧</span>
                                        <span>EN</span>
                                    </button>
                                </form>
                                <form action="{{ route('language.switch') }}" method="POST" class="flex-1">
                                    @csrf
                                    <input type="hidden" name="redirect" value="{{ url()->current() }}">
                                    <input type="hidden" name="locale" value="nl">
                                    <button type="submit" class="w-full flex items-center justify-center space-x-2 px-4 py-2 rounded-md transition-colors {{ app()->getLocale() === 'nl' ? 'bg-white text-blue-600' : 'bg-white/10 text-white hover:bg-white/20' }}">
                                        <span>🇳🇱</span>
                                        <span>NL</span>
                                    </button>
                                </form>
                            </div>
                        </div>

                        @guest
                        <div class="border-t border-white/20 pt-2 mt-2 space-y-2">
                            <a href="{{ route('login') }}" class="block text-white hover:bg-white/10 px-4 py-3 rounded-md transition-colors">{{ __('ui.nav_login') }}</a>
                            <a href="{{ route('register') }}" class="block bg-white text-blue-600 hover:bg-white/90 px-4 py-3 rounded-md transition-colors font-semibold text-center">{{ __('ui.nav_signup') }}</a>
                        </div>
                        @endguest

                        @auth
                        <div class="border-t border-white/20 pt-2 mt-2 space-y-2">
                            <a href="{{ route('dashboard') }}" class="block text-white hover:bg-white/10 px-4 py-3 rounded-md transition-colors">{{ __('ui.nav_dashboard') }}</a>
                            <a href="{{ route('profile.edit') }}" class="block text-white hover:bg-white/10 px-4 py-3 rounded-md transition-colors">{{ __('ui.nav_profile') }}</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="w-full text-left text-white hover:bg-white/10 px-4 py-3 rounded-md transition-colors">{{ __('ui.nav_logout') }}</button>
                            </form>
                        </div>
                        @endauth
                    </nav>
                </div>
            </div>
        </div>
    </div>
</header>
