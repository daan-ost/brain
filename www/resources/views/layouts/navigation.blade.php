<nav x-data="{ open: false }" class="bg-white border-b border-gray-100">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('uploads') }}">
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('uploads')" :active="request()->routeIs('uploads')">
                        {{ __('common.my_uploads') }}
                    </x-nav-link>
                    <x-nav-link :href="route('convert.overview')" :active="request()->routeIs('convert.overview')">
                        Convert
                    </x-nav-link>
                    <x-nav-link :href="route('merge.overview')" :active="request()->routeIs('merge.overview')">
                        Merge
                    </x-nav-link>
                    <x-nav-link :href="route('pricing')" :active="request()->routeIs('pricing*')">
                        Pricing
                    </x-nav-link>
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6 space-x-4">
                <!-- Language Switcher -->
                <x-dropdown align="right" width="32">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-2 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            @if(app()->getLocale() === 'nl')
                                🇳🇱
                            @else
                                🇬🇧
                            @endif
                            <div class="ms-1">
                                <svg class="fill-current h-3 w-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <form method="POST" action="{{ route('language.switch') }}">
                            @csrf
                            <input type="hidden" name="locale" value="en">
                            <input type="hidden" name="redirect" value="{{ url()->full() }}">
                            <button type="submit" class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out {{ app()->getLocale() === 'en' ? 'bg-gray-50 font-medium' : '' }}">
                                🇬🇧 English
                            </button>
                        </form>
                        <form method="POST" action="{{ route('language.switch') }}">
                            @csrf
                            <input type="hidden" name="locale" value="nl">
                            <input type="hidden" name="redirect" value="{{ url()->full() }}">
                            <button type="submit" class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out {{ app()->getLocale() === 'nl' ? 'bg-gray-50 font-medium' : '' }}">
                                🇳🇱 Nederlands
                            </button>
                        </form>
                    </x-slot>
                </x-dropdown>
                @auth
                    @php
                        $unreadMessageCount = Auth::user()->messageThreads()->where('unread_count_user', '>', 0)->count();
                    @endphp
                    @if($unreadMessageCount > 0)
                        <a href="{{ route('profile.messages') }}" class="relative inline-flex items-center p-2 text-gray-500 hover:text-gray-700 focus:outline-none transition ease-in-out duration-150" title="{{ __('messages.my_messages') }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            <span class="absolute -top-1 -right-1 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white bg-red-500 rounded-full">
                                {{ $unreadMessageCount > 9 ? '9+' : $unreadMessageCount }}
                            </span>
                        </a>
                    @endif
                    <x-dropdown align="right" width="48">
                        <x-slot name="trigger">
                            <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                                <div>{{ Auth::user()->name }}</div>

                                <div class="ms-1">
                                    <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </button>
                        </x-slot>

                        <x-slot name="content">
                            <x-dropdown-link :href="route('profile.edit')">
                                {{ __('auth.profile') }}
                            </x-dropdown-link>

                            <!-- Authentication -->
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                                    {{ __('auth.logout') }}
                                </button>
                            </form>
                        </x-slot>
                    </x-dropdown>
                @else
                    <div class="flex items-center space-x-4">
                        <a href="{{ route('login') }}" class="text-gray-500 hover:text-gray-700 px-3 py-2 text-sm font-medium">Login</a>
                        <a href="{{ route('register') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 text-sm font-medium rounded-md">Register</a>
                    </div>
                @endauth
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" :aria-expanded="open" aria-label="Toggle navigation menu" class="inline-flex items-center justify-center min-h-[44px] min-w-[44px] p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('uploads')" :active="request()->routeIs('uploads')">
                {{ __('common.my_uploads') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('convert.overview')" :active="request()->routeIs('convert.overview')">
                Convert
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('merge.overview')" :active="request()->routeIs('merge.overview')">
                Merge
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('pricing')" :active="request()->routeIs('pricing*')">
                Pricing
            </x-responsive-nav-link>
        </div>

        <!-- Responsive Language Switcher -->
        <div class="pt-2 pb-3 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800 mb-2">Language</div>
                <div class="space-y-1">
                    <form method="POST" action="{{ route('language.switch') }}">
                        @csrf
                        <input type="hidden" name="locale" value="en">
                        <input type="hidden" name="redirect" value="{{ url()->full() }}">
                        <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded {{ app()->getLocale() === 'en' ? 'bg-gray-50 font-medium' : '' }}">
                            🇬🇧 English
                        </button>
                    </form>
                    <form method="POST" action="{{ route('language.switch') }}">
                        @csrf
                        <input type="hidden" name="locale" value="nl">
                        <input type="hidden" name="redirect" value="{{ url()->full() }}">
                        <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded {{ app()->getLocale() === 'nl' ? 'bg-gray-50 font-medium' : '' }}">
                            🇳🇱 Nederlands
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Responsive Settings Options -->
        @auth
            @php
                $unreadMessageCount = $unreadMessageCount ?? Auth::user()->messageThreads()->where('unread_count_user', '>', 0)->count();
            @endphp
            <div class="pt-4 pb-1 border-t border-gray-200">
                <div class="px-4">
                    <div class="font-medium text-base text-gray-800">{{ Auth::user()->name }}</div>
                    <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
                </div>

                <div class="mt-3 space-y-1">
                    <x-responsive-nav-link :href="route('profile.edit')">
                        {{ __('auth.profile') }}
                    </x-responsive-nav-link>

                    @if($unreadMessageCount > 0)
                        <x-responsive-nav-link :href="route('profile.messages')">
                            <span class="flex items-center">
                                {{ __('messages.my_messages') }}
                                <span class="ml-2 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white bg-red-500 rounded-full">
                                    {{ $unreadMessageCount > 9 ? '9+' : $unreadMessageCount }}
                                </span>
                            </span>
                        </x-responsive-nav-link>
                    @endif

                    <!-- Authentication -->
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full ps-3 pe-4 py-3 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:text-gray-800 focus:bg-gray-50 focus:border-gray-300 transition duration-150 ease-in-out">
                            {{ __('auth.logout') }}
                        </button>
                    </form>
                </div>
            </div>
        @else
            <div class="pt-4 pb-1 border-t border-gray-200">
                <div class="mt-3 space-y-1">
                    <x-responsive-nav-link :href="route('login')">
                        Login
                    </x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('register')">
                        Register
                    </x-responsive-nav-link>
                </div>
            </div>
        @endauth
    </div>
</nav>
