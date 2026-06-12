@extends('layouts.homepage-standalone')

@section('title', 'Professional PDF Tools')

@section('content')
<main class="min-h-screen">
    <!-- Header -->
    <header class="gradient-header relative z-50" style="border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
        <div style="background: linear-gradient(135deg, #9FD6D2 0%, #53B3AE 100%);">
            <div class="container mx-auto px-4 py-4">
                <div class="flex items-center justify-between">
                    <!-- Logo -->
                    <div class="flex items-center">
                        <img src="https://via.placeholder.com/120x48/53b3ae/ffffff?text=App+Logo" alt="App Logo"
                            class="h-12 w-auto opacity-90 hover:opacity-100 transition-opacity" />
                    </div>

                    <!-- Desktop Navigation -->
                    <nav class="hidden md:flex items-center space-x-8">
                        <a href="#"
                            class="text-white hover:text-white/80 transition-colors font-medium text-sm">Convert</a>
                        <a href="#"
                            class="text-white hover:text-white/80 transition-colors font-medium text-sm">Merge</a>
                        <a href="#"
                            class="text-white hover:text-white/80 transition-colors font-medium text-sm">Compress</a>
                        <a href="#"
                            class="text-white hover:text-white/80 transition-colors font-medium text-sm">Automate</a>
                        <a href="#"
                            class="text-white hover:text-white/80 transition-colors font-medium text-sm">Extract</a>
                        <button
                            class="flex items-center text-white hover:text-white/80 transition-colors font-medium text-sm">
                            All
                            <svg class="ml-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                    </nav>

                    <!-- Auth Buttons -->
                    <div class="hidden md:flex items-center space-x-3">
                        <button
                            class="text-white hover:bg-white/10 hover:text-white px-4 py-2 rounded-md transition-colors">
                            Login
                        </button>
                        <button
                            class="bg-white text-[#53b3ae] hover:bg-white/90 px-4 py-2 rounded-md font-medium shadow-sm transition-colors">
                            Sign up
                        </button>
                        <button class="text-white hover:bg-white/10 hover:text-white p-2 rounded-md transition-colors">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z">
                                </path>
                            </svg>
                        </button>
                    </div>

                    <!-- Mobile Menu Button -->
                    <button class="md:hidden text-white">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="gradient-hero relative overflow-visible">
        <div class="absolute inset-0" style="background: linear-gradient(135deg, #9FD6D2 0%, #53B3AE 100%);"></div>

        <!-- Curved Bottom (Buikje naar beneden) -->
        <svg class="absolute bottom-[-95px] left-0 w-full h-[120px]" viewBox="0 0 100 120" preserveAspectRatio="none">
            <path d="M-10,10 C55,120 50,120 100,0 L100,0 L0,20 Z" fill="#53B3AE" />
        </svg>

        <div class="container mx-auto px-4 py-20 relative z-10">
            <div class="text-center mb-12">
                <h1 class="text-6xl md:text-7xl font-black text-white mb-6 leading-[0.9] tracking-tight font-display">
                    Streamline document workflows with our PDF & eSign solutions
                </h1>
                <p class="text-xl md:text-2xl text-white/90 mb-8 max-w-[800px] mx-auto font-medium leading-relaxed">
                    Optimized for business and organisations. Transform, merge, compress, and automate your document
                    processes with enterprise-grade security.
                </p>
            </div>
        </div>
    </section>

    <!-- Conversions Section -->
    <section class="py-16 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900 mb-4 font-display">Our popular conversions</h2>
                <p class="text-lg text-gray-600 max-w-3xl mx-auto">
                    Convert between PDF, Word, Excel, PowerPoint, and text formats. Our converter preserves formatting
                    and embedded content.
                </p>
            </div>

            <div class="grid md:grid-cols-3 gap-6">
                <div class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow border">
                    <div class="flex items-center mb-4">
                        <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center mr-4">
                            <span class="text-lg font-bold text-red-600">PDF</span>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">PDF Converter</h3>
                            <p class="text-sm text-gray-600">Convert PDF to Word, Excel, PowerPoint formats</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow border">
                    <div class="flex items-center mb-4">
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mr-4">
                            <span class="text-lg font-bold text-blue-600">DOC</span>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Word Converter</h3>
                            <p class="text-sm text-gray-600">Transform Word documents to PDF and other formats</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow border">
                    <div class="flex items-center mb-4">
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center mr-4">
                            <span class="text-lg font-bold text-green-600">XLS</span>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Excel Converter</h3>
                            <p class="text-sm text-gray-600">Convert Excel spreadsheets while preserving formulas</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow border">
                    <div class="flex items-center mb-4">
                        <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center mr-4">
                            <span class="text-lg font-bold text-orange-600">PPT</span>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">PowerPoint Converter</h3>
                            <p class="text-sm text-gray-600">Convert presentations to PDF and image formats</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow border">
                    <div class="flex items-center mb-4">
                        <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center mr-4">
                            <span class="text-lg font-bold text-purple-600">TXT</span>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Text Converter</h3>
                            <p class="text-sm text-gray-600">Extract and convert text from various document types</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow border">
                    <div class="flex items-center mb-4">
                        <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center mr-4">
                            <span class="text-lg font-bold text-yellow-600">IMG</span>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Image Converter</h3>
                            <p class="text-sm text-gray-600">Convert documents to JPG, PNG, and other image formats</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="py-20">
        <div class="container mx-auto px-4">
            <div class="text-center mb-16">
                <h2 class="text-4xl md:text-5xl font-bold mb-6 text-gray-900 font-display">
                    Powerful solutions for every workflow
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto">
                    From email archiving to automated batch processing, our comprehensive suite handles all your
                    document management needs.
                </p>
            </div>

            <div class="space-y-24">
                <!-- Feature 1 -->
                <div class="flex flex-col lg:flex-row items-center gap-12">
                    <div class="flex-1">
                        <img src="https://via.placeholder.com/600x320/e5e7eb/6b7280?text=Email+Archive+Dashboard"
                            alt="Email Archive Dashboard" class="w-full h-80 object-cover rounded-2xl shadow-lg" />
                    </div>
                    <div class="flex-1 space-y-6">
                        <div class="w-16 h-16 bg-blue-100 rounded-2xl flex items-center justify-center">
                            <svg class="h-8 w-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                                </path>
                            </svg>
                        </div>
                        <h3 class="text-3xl font-bold text-gray-900 font-display">Email Archive solutions</h3>
                        <p class="text-lg text-gray-600 leading-relaxed">
                            Comprehensive email archiving and management solutions that help organizations maintain
                            compliance, reduce storage costs, and improve email accessibility.
                        </p>
                    </div>
                </div>

                <!-- Feature 2 -->
                <div class="flex flex-col lg:flex-row-reverse items-center gap-12">
                    <div class="flex-1">
                        <img src="https://via.placeholder.com/600x320/e5e7eb/6b7280?text=Workflow+Automation"
                            alt="Workflow Automation" class="w-full h-80 object-cover rounded-2xl shadow-lg" />
                    </div>
                    <div class="flex-1 space-y-6">
                        <div class="w-16 h-16 bg-green-100 rounded-2xl flex items-center justify-center">
                            <svg class="h-8 w-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z">
                                </path>
                            </svg>
                        </div>
                        <h3 class="text-3xl font-bold text-gray-900 font-display">Workflow and batch processing</h3>
                        <p class="text-lg text-gray-600 leading-relaxed">
                            Automate your document workflows with powerful batch processing capabilities. Set up custom
                            rules, triggers, and integrations to handle thousands of documents automatically.
                        </p>
                    </div>
                </div>

                <!-- Feature 3 -->
                <div class="flex flex-col lg:flex-row items-center gap-12">
                    <div class="flex-1">
                        <img src="https://via.placeholder.com/600x320/e5e7eb/6b7280?text=Business+Dashboard"
                            alt="Business Dashboard" class="w-full h-80 object-cover rounded-2xl shadow-lg" />
                    </div>
                    <div class="flex-1 space-y-6">
                        <div class="w-16 h-16 bg-purple-100 rounded-2xl flex items-center justify-center">
                            <svg class="h-8 w-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                                </path>
                            </svg>
                        </div>
                        <h3 class="text-3xl font-bold text-gray-900 font-display">Optimized for business and
                            organisations</h3>
                        <p class="text-lg text-gray-600 leading-relaxed">
                            Enterprise-grade solutions designed specifically for business needs. Advanced user
                            management, team collaboration tools, compliance features, and dedicated support.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Reviews Section -->
    <section class="py-20 bg-gray-50">
        <div class="container mx-auto px-4">
            <div class="text-center mb-16">
                <h2 class="text-4xl md:text-5xl font-bold mb-6 text-gray-900 font-display">
                    Users and business love to recommend us
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto mb-12">
                    Trusted by leading organizations worldwide for their document processing needs.
                </p>
            </div>

            <!-- Company Logos -->
            <div class="mb-16">
                <p class="text-center text-gray-500 mb-8 text-sm uppercase tracking-wider font-medium">
                    Trusted by industry leaders
                </p>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-8 items-center justify-items-center">
                    <div class="grayscale hover:grayscale-0 transition-all duration-300 opacity-60 hover:opacity-100">
                        <img src="https://via.placeholder.com/120x48/6b7280/ffffff?text=Microsoft" alt="Microsoft logo"
                            class="h-12 w-auto object-contain" />
                    </div>
                    <div class="grayscale hover:grayscale-0 transition-all duration-300 opacity-60 hover:opacity-100">
                        <img src="https://via.placeholder.com/120x48/6b7280/ffffff?text=Google" alt="Google logo"
                            class="h-12 w-auto object-contain" />
                    </div>
                    <div class="grayscale hover:grayscale-0 transition-all duration-300 opacity-60 hover:opacity-100">
                        <img src="https://via.placeholder.com/120x48/6b7280/ffffff?text=Amazon" alt="Amazon logo"
                            class="h-12 w-auto object-contain" />
                    </div>
                    <div class="grayscale hover:grayscale-0 transition-all duration-300 opacity-60 hover:opacity-100">
                        <img src="https://via.placeholder.com/120x48/6b7280/ffffff?text=Apple" alt="Apple logo"
                            class="h-12 w-auto object-contain" />
                    </div>
                    <div class="grayscale hover:grayscale-0 transition-all duration-300 opacity-60 hover:opacity-100">
                        <img src="https://via.placeholder.com/120x48/6b7280/ffffff?text=IBM" alt="IBM logo"
                            class="h-12 w-auto object-contain" />
                    </div>
                    <div class="grayscale hover:grayscale-0 transition-all duration-300 opacity-60 hover:opacity-100">
                        <img src="https://via.placeholder.com/120x48/6b7280/ffffff?text=Oracle" alt="Oracle logo"
                            class="h-12 w-auto object-contain" />
                    </div>
                </div>
            </div>

            <!-- Testimonials -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="bg-white rounded-2xl p-8 shadow-lg hover:shadow-xl transition-shadow">
                    <div class="flex items-center mb-4">
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                    </div>
                    <blockquote class="text-gray-700 mb-6 text-lg leading-relaxed">
                        "{{ config('app.name') }} has transformed our document workflows. The automation features save us hours every
                        day."
                    </blockquote>
                    <div class="border-t pt-4">
                        <div class="font-semibold text-gray-900 font-display">Sarah Johnson</div>
                        <div class="text-gray-600 text-sm">Operations Manager at TechCorp Inc.</div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-8 shadow-lg hover:shadow-xl transition-shadow">
                    <div class="flex items-center mb-4">
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                    </div>
                    <blockquote class="text-gray-700 mb-6 text-lg leading-relaxed">
                        "The email archiving solution is exactly what we needed for compliance. Highly recommended!"
                    </blockquote>
                    <div class="border-t pt-4">
                        <div class="font-semibold text-gray-900 font-display">Michael Chen</div>
                        <div class="text-gray-600 text-sm">IT Director at Global Solutions Ltd.</div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-8 shadow-lg hover:shadow-xl transition-shadow">
                    <div class="flex items-center mb-4">
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                        <svg class="h-5 w-5 text-yellow-400 fill-current" viewBox="0 0 24 24">
                            <path
                                d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                        </svg>
                    </div>
                    <blockquote class="text-gray-700 mb-6 text-lg leading-relaxed">
                        "Batch processing capabilities are outstanding. We process thousands of documents seamlessly."
                    </blockquote>
                    <div class="border-t pt-4">
                        <div class="font-semibold text-gray-900 font-display">Emily Rodriguez</div>
                        <div class="text-gray-600 text-sm">Document Manager at Enterprise Systems</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="py-20 bg-gray-50">
        <div class="container mx-auto px-4">
            <div class="text-center mb-16">
                <h2 class="text-4xl md:text-5xl font-bold mb-6 text-gray-900 font-display">
                    Convert PDF FAQs
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto">
                    Everything you need to know about {{ config('app.name') }}'s features, security, and pricing.
                </p>
            </div>

            <div class="max-w-4xl mx-auto space-y-6">
                <div class="bg-white rounded-2xl border-0 shadow-sm">
                    <details class="group">
                        <summary class="px-6 py-6 cursor-pointer list-none">
                            <div class="flex items-center justify-between">
                                <span class="text-lg font-semibold text-gray-900">How secure is {{ config('app.name') }} for sensitive
                                    documents?</span>
                                <svg class="w-5 h-5 text-gray-500 group-open:rotate-180 transition-transform"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        </summary>
                        <div class="px-6 pb-6">
                            <p class="text-gray-600 leading-relaxed">
                                {{ config('app.name') }} uses bank-level AES-256 encryption for all file transfers and processing. Your
                                documents are processed securely and automatically deleted from our servers after
                                processing. We are SOC 2 Type II certified and GDPR compliant.
                            </p>
                        </div>
                    </details>
                </div>

                <div class="bg-white rounded-2xl border-0 shadow-sm">
                    <details class="group">
                        <summary class="px-6 py-6 cursor-pointer list-none">
                            <div class="flex items-center justify-between">
                                <span class="text-lg font-semibold text-gray-900">What file formats can I convert PDFs
                                    to?</span>
                                <svg class="w-5 h-5 text-gray-500 group-open:rotate-180 transition-transform"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        </summary>
                        <div class="px-6 pb-6">
                            <p class="text-gray-600 leading-relaxed">
                                {{ config('app.name') }} supports conversion to Word (DOCX), Excel (XLSX), PowerPoint (PPTX), various
                                image formats (PNG, JPG, TIFF), HTML, and plain text. We also support batch conversions
                                for multiple files simultaneously.
                            </p>
                        </div>
                    </details>
                </div>

                <div class="bg-white rounded-2xl border-0 shadow-sm">
                    <details class="group">
                        <summary class="px-6 py-6 cursor-pointer list-none">
                            <div class="flex items-center justify-between">
                                <span class="text-lg font-semibold text-gray-900">Is there a file size limit for
                                    uploads?</span>
                                <svg class="w-5 h-5 text-gray-500 group-open:rotate-180 transition-transform"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        </summary>
                        <div class="px-6 pb-6">
                            <p class="text-gray-600 leading-relaxed">
                                Free accounts can process files up to 100MB. Pro accounts support files up to 1GB, and
                                Enterprise accounts have no file size limits. We also support batch processing of
                                multiple files.
                            </p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-gradient-to-br from-gray-900 via-gray-800 to-slate-900 text-white">
        <div class="border-b border-gray-700/30">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 text-center">
                <h2 class="text-3xl md:text-4xl font-bold mb-6 font-display">
                    Ready to Streamline Your<br />PDF Workflows?
                </h2>
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <button
                        class="bg-orange-500 hover:bg-orange-600 text-white px-8 py-3 rounded-lg font-semibold transition-colors">
                        Get Started for Free
                    </button>
                    <button
                        class="border-2 border-blue-500 hover:border-blue-400 text-blue-500 hover:text-blue-400 px-8 py-3 rounded-lg font-semibold transition-colors">
                        Contact Sales
                    </button>
                </div>
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-8">
                <div class="lg:col-span-1">
                    <div class="flex items-center space-x-2 mb-6">
                        <div class="w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center">
                            <span class="text-xl font-bold text-white">P</span>
                        </div>
                        <span class="font-bold text-xl text-white">{{ config('app.name') }}</span>
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center space-x-2 text-sm bg-gray-700/50 rounded-lg px-3 py-2 w-fit">
                            <div class="w-6 h-6 bg-gray-600 rounded-full flex items-center justify-center">
                                <span class="text-xs font-bold text-white">✓</span>
                            </div>
                            <span class="text-gray-300 font-medium">GDPR Compliant</span>
                        </div>
                        <div class="flex items-center space-x-2 text-sm bg-gray-700/50 rounded-lg px-3 py-2 w-fit">
                            <div class="w-6 h-6 bg-gray-600 rounded-full flex items-center justify-center">
                                <span class="text-xs font-bold text-white">ISO</span>
                            </div>
                            <span class="text-gray-300 font-medium">ISO 27001</span>
                        </div>
                        <div class="flex items-center space-x-2 text-sm bg-gray-700/50 rounded-lg px-3 py-2 w-fit">
                            <div class="w-6 h-6 bg-gray-600 rounded-full flex items-center justify-center">
                                <span class="text-xs font-bold text-white">SSL</span>
                            </div>
                            <span class="text-gray-300 font-medium">256-bit SSL</span>
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="font-semibold text-white mb-4 text-sm uppercase tracking-wider">Platform</h3>
                    <ul class="space-y-3">
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Convert PDF</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Merge PDF</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Compress PDF</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">API Tools</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Pricing</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="font-semibold text-white mb-4 text-sm uppercase tracking-wider">Resources</h3>
                    <ul class="space-y-3">
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Blog</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Documentation</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Partners</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Affiliates</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Media Kit</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="font-semibold text-white mb-4 text-sm uppercase tracking-wider">Help</h3>
                    <ul class="space-y-3">
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Support</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Help Desk</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Live Chat</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Status</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="font-semibold text-white mb-4 text-sm uppercase tracking-wider">Legal</h3>
                    <ul class="space-y-3">
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Security and
                                Compliance</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Server Locations</a>
                        </li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Terms of Service</a>
                        </li>
                        <li><a href="#" class="text-gray-300 hover:text-white transition-colors">Privacy Policy</a></li>
                    </ul>
                </div>
            </div>

            <div class="border-t border-gray-700/30 mt-12 pt-8">
                <div class="flex flex-col md:flex-row justify-between items-center">
                    <p class="text-gray-300 text-sm mb-4 md:mb-0">© 2011 - 2024 {{ config('app.name') }}</p>
                    <div class="flex items-center space-x-4">
                        <a href="#" class="text-gray-300 hover:text-white transition-colors text-sm">LinkedIn</a>
                        <a href="#" class="text-gray-300 hover:text-white transition-colors text-sm">GitHub</a>
                        <a href="#" class="text-gray-300 hover:text-white transition-colors text-sm">Twitter</a>
                    </div>
                </div>
            </div>
        </div>
    </footer>
</main>
@endsection