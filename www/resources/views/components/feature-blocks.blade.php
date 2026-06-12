@php
$originalFeatures = [
[
'title' => 'Smart Conversion',
'description' => 'Convert PDFs to Word, Excel, PowerPoint, and images with AI-powered accuracy that preserves formatting
and layout.',
'image' => '/pdf-conversion-interface-with-multiple-file-format.jpg',
'layout' => 'default',
],
[
'title' => 'Intelligent Compression',
'description' => 'Reduce file sizes by up to 90% while maintaining visual quality using advanced compression
algorithms.',
'image' => '/pdf-compression-interface-showing-file-size-reduct.jpg',
'layout' => 'default',
],
[
'title' => 'Workflow Automation',
'description' => 'Set up automated PDF processing pipelines with custom rules, triggers, and integrations for your
business.',
'image' => '/automation-workflow-interface-with-pdf-processing-.jpg',
'layout' => 'default',
],
[
'title' => 'Data Extraction',
'description' => 'Extract text, images, tables, and forms from PDFs with OCR technology and structured data output.',
'image' => '/pdf-data-extraction-interface-showing-text-and-tab.jpg',
'layout' => 'default',
],
[
'title' => 'Enterprise Security',
'description' => 'Bank-level encryption, compliance certifications, and secure processing ensure your documents stay
protected.',
'image' => '/security-dashboard-showing-encryption-and-complian.jpg',
'layout' => 'default',
],
];

$diverseFeatures = [
[
'title' => 'Smart Conversion',
'description' => 'Convert PDFs to Word, Excel, PowerPoint, and images with AI-powered accuracy that preserves formatting
and layout.',
'image' => '/pdf-conversion-interface-with-multiple-file-format.jpg',
'layout' => 'card',
],
[
'title' => 'Intelligent Compression',
'description' => 'Reduce file sizes by up to 90% while maintaining visual quality using advanced compression
algorithms.',
'image' => '/pdf-compression-interface-showing-file-size-reduct.jpg',
'layout' => 'split',
],
[
'title' => 'Workflow Automation',
'description' => 'Set up automated PDF processing pipelines with custom rules, triggers, and integrations for your
business.',
'image' => '/automation-workflow-interface-with-pdf-processing-.jpg',
'layout' => 'highlight',
],
[
'title' => 'Data Extraction',
'description' => 'Extract text, images, tables, and forms from PDFs with OCR technology and structured data output.',
'image' => '/pdf-data-extraction-interface-showing-text-and-tab.jpg',
'layout' => 'minimal',
],
];

$advancedMergingFeature = [
'title' => 'Advanced Merging',
'description' => 'Combine multiple PDFs with custom page ordering, bookmarks, and metadata preservation for professional
results.',
'image' => '/pdf-merge-interface-showing-multiple-documents-bei.jpg',
];

$canvaStyleSection = [
'title' => 'Perfect for Everyone',
'subtitle' => 'Choose the right plan for your PDF processing needs',
'plans' => [
[
'name' => 'Free Starter',
'price' => '€0',
'period' => '/month',
'description' => 'Convert PDFs to Word, Excel, PowerPoint with basic AI-powered accuracy for personal use.',
'features' => ['5 conversions/month', 'Basic format support', 'Standard quality'],
'buttonText' => 'Start Free',
'buttonStyle' => 'secondary',
'popular' => false,
],
[
'name' => 'Smart Pro',
'price' => '€29',
'period' => '/month',
'description' => 'Advanced AI conversion with premium formatting preservation and layout accuracy.',
'features' => ['Unlimited conversions', 'All formats', 'Premium quality', 'Priority support'],
'buttonText' => 'Try Smart Pro',
'buttonStyle' => 'primary',
'popular' => true,
],
[
'name' => 'Team Convert',
'price' => '€99',
'period' => '/month',
'description' => 'Team collaboration with shared conversion credits and advanced workflow features.',
'features' => ['Team dashboard', 'Bulk processing', 'API access', 'Admin controls'],
'buttonText' => 'Start Team Trial',
'buttonStyle' => 'primary',
'popular' => true,
],
[
'name' => 'Enterprise',
'price' => 'Custom',
'period' => '',
'description' => 'Custom AI models with enterprise security and dedicated conversion infrastructure.',
'features' => ['Custom AI training', 'Enterprise security', 'Dedicated support', 'SLA guarantee'],
'buttonText' => 'Contact Sales',
'buttonStyle' => 'primary',
'popular' => true,
],
],
];
@endphp

<section class="py-40 content-section">
    <div class="container mx-auto px-4">
        <div class="text-center mb-16">
            <h2 class="text-4xl md:text-5xl font-bold mb-6 text-balance text-gray-900"
                style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                Everything you need for PDF workflows
            </h2>
            <p class="text-xl text-gray-600 max-w-3xl mx-auto text-pretty"
                style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                From simple conversions to complex automation, {{ config('app.name') }} provides professional-grade tools for every PDF
                task.
            </p>
        </div>

        <div class="space-y-20 mb-32">
            @foreach($originalFeatures as $index => $feature)
            @php $isImageLeft = $index % 2 === 0; @endphp
            <div class="flex flex-col lg:flex-row items-center gap-12 {{ !$isImageLeft ? 'lg:flex-row-reverse' : '' }}">
                <div class="flex-1">
                    <img src="{{ $feature['image'] ?? '/placeholder.svg' }}" alt="{{ $feature['title'] }}"
                        class="w-full h-80 object-cover rounded-2xl shadow-lg" />
                </div>
                <div class="flex-1 space-y-6">
                    <h3 class="text-3xl font-bold text-black"
                        style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        {{ $feature['title'] }}
                    </h3>
                    <p class="text-lg text-gray-600 leading-relaxed"
                        style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        {{ $feature['description'] }}
                    </p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

<!-- Full-width diverse features section -->
@foreach($diverseFeatures as $index => $feature)
@php $isImageLeft = $index % 2 === 0; @endphp

@if($feature['layout'] === 'card')
<!-- Full-width blue section -->
<section class="bg-gradient-to-br from-blue-50 to-indigo-100 py-20">
    <div class="container mx-auto px-4">
        <div class="flex flex-col lg:flex-row items-center gap-12">
            <div class="flex-1">
                <img src="{{ $feature['image'] ?? '/placeholder.svg' }}" alt="{{ $feature['title'] }}"
                    class="w-full h-80 object-cover rounded-2xl shadow-xl" />
            </div>
            <div class="flex-1 space-y-6">
                <h3 class="text-3xl font-bold text-blue-900"
                    style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                    {{ $feature['title'] }}
                </h3>
                <p class="text-lg text-blue-700 leading-relaxed"
                    style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                    {{ $feature['description'] }}
                </p>
            </div>
        </div>
    </div>
</section>
@elseif($feature['layout'] === 'split')
<!-- Full-width green section -->
<section class="bg-gradient-to-r from-emerald-500 to-teal-600 py-20">
    <div class="container mx-auto px-4">
        <div class="flex flex-col lg:flex-row">
            <div class="flex-1 p-12 text-white bg-emerald-600">
                <h3 class="text-3xl font-bold mb-6"
                    style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #ffffff;">
                    {{ $feature['title'] }}
                </h3>
                <p class="text-lg leading-relaxed"
                    style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #f0fdf4;">
                    {{ $feature['description'] }}
                </p>
            </div>
            <div class="flex-1">
                <img src="{{ $feature['image'] ?? '/placeholder.svg' }}" alt="{{ $feature['title'] }}"
                    class="w-full h-full object-cover" />
            </div>
        </div>
    </div>
</section>
@elseif($feature['layout'] === 'highlight')
<!-- Why choose us section -->
<section class="py-20 bg-gradient-to-br from-gray-50 to-gray-100">
    <div class="container mx-auto px-4">
        <div class="text-center mb-16">
            <h2 class="text-4xl md:text-5xl font-bold mb-6 text-balance text-gray-900"
                style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                Why choose us to convert your file
            </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <!-- Secured information and files -->
            <div class="bg-white rounded-2xl p-8 shadow-lg hover:shadow-xl transition-shadow">
                <div class="mb-6">
                    <div class="w-16 h-16 bg-blue-100 rounded-xl flex items-center justify-center mb-4">
                        <svg class="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" />
                            <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" fill="none" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-900"
                        style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        Secured information and files
                    </h3>
                    <p class="text-gray-600 leading-relaxed"
                        style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        All the client's data on the website or save is encrypted. We offer 256-bit SSL Encryption that
                        decrypts and encrypt clients' information with its modern algorithms and methodologies.
                    </p>
                </div>
            </div>

            <!-- Timely file removal -->
            <div class="bg-white rounded-2xl p-8 shadow-lg hover:shadow-xl transition-shadow">
                <div class="mb-6">
                    <div class="w-16 h-16 bg-blue-100 rounded-xl flex items-center justify-center mb-4">
                        <svg class="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                            <path
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-900"
                        style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        Timely file removal
                    </h3>
                    <p class="text-gray-600 leading-relaxed"
                        style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        The website server does not hold and saves the user's file permanently. The user's security is
                        always taken into account, and the file you convert on the software is automatically Deleted
                        from the website server to provide security and safety to its customers.
                    </p>
                </div>
            </div>

            <!-- Quick and easy -->
            <div class="bg-white rounded-2xl p-8 shadow-lg hover:shadow-xl transition-shadow">
                <div class="mb-6">
                    <div class="w-16 h-16 bg-blue-100 rounded-xl flex items-center justify-center mb-4">
                        <svg class="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-900"
                        style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        Quick and easy
                    </h3>
                    <p class="text-gray-600 leading-relaxed"
                        style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        The PDF to Excel conversion takes a lot of complications and is a necessary data entry
                        procedure. But here, you can get a quick process to convert because of its fantastic tools and
                        features.
                    </p>
                </div>
            </div>

            <!-- Format retaining at its best -->
            <div class="bg-white rounded-2xl p-8 shadow-lg hover:shadow-xl transition-shadow">
                <div class="mb-6">
                    <div class="w-16 h-16 bg-blue-100 rounded-xl flex items-center justify-center mb-4">
                        <svg class="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                            <path
                                d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1m4 0h1m-6 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-900"
                        style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        Format retaining at its best
                    </h3>
                    <p class="text-gray-600 leading-relaxed"
                        style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        The Excel format is the most mandatory element to be concerned about because it contains several
                        sheets, tables, rows, and cells to represent the file. Here there are tools and features to
                        align the data to an Excel format and generate the perfect output.
                    </p>
                </div>
            </div>

            <!-- Quality scanning -->
            <div class="bg-white rounded-2xl p-8 shadow-lg hover:shadow-xl transition-shadow">
                <div class="mb-6">
                    <div class="w-16 h-16 bg-blue-100 rounded-xl flex items-center justify-center mb-4">
                        <svg class="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-900"
                        style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        Quality scanning
                    </h3>
                    <p class="text-gray-600 leading-relaxed"
                        style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        The powerful OCR integrated into software lets you extract the words from different paper
                        documents in high readable quality. All the characters and symbols are recognized with 100%
                        accuracy with the help of powerful OCR Integrated.
                    </p>
                </div>
            </div>

            <!-- Offline PDF to Excel -->
            <div class="bg-white rounded-2xl p-8 shadow-lg hover:shadow-xl transition-shadow">
                <div class="mb-6">
                    <div class="w-16 h-16 bg-blue-100 rounded-xl flex items-center justify-center mb-4">
                        <svg class="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                            <path
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-900"
                        style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        Offline PDF to Excel
                    </h3>
                    <p class="text-gray-600 leading-relaxed"
                        style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        The Desktop version of PDF Agile doesn't need any internet connection. It helps its clients to
                        convert the files offline, and it lets the users convert and edit anywhere remotely.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
@endif
@endforeach

<!-- Continue with rest of component -->
<section class="py-40 content-section">
    <div class="container mx-auto px-4">
        <div class="w-full bg-gradient-to-br from-gray-50 to-gray-100 rounded-3xl p-16 mb-32">
            <div class="text-center mb-16">
                <h2 class="text-4xl md:text-5xl font-bold mb-6 text-balance text-gray-900"
                    style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                    {{ $canvaStyleSection['title'] }}
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto text-pretty"
                    style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                    {{ $canvaStyleSection['subtitle'] }}
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                @foreach($canvaStyleSection['plans'] as $index => $plan)
                <div
                    class="relative rounded-2xl p-8 {{ $index === 0 ? 'bg-white border-2 border-gray-200' : 'bg-gradient-to-br from-purple-100 to-purple-200 border-2 border-purple-300' }}">
                    @if($plan['popular'] && $index > 0)
                    <div class="absolute -top-3 left-1/2 transform -translate-x-1/2">
                        <div
                            class="bg-orange-500 text-white px-4 py-1 rounded-full text-sm font-semibold flex items-center gap-1">
                            <span>👑</span> Popular
                        </div>
                    </div>
                    @endif

                    <div class="mb-6">
                        <h3 class="text-2xl font-bold mb-2"
                            style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                            {{ $plan['name'] }}
                        </h3>
                        <div class="flex items-baseline mb-4">
                            <span class="text-4xl font-bold"
                                style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                                {{ $plan['price'] }}
                            </span>
                            <span class="text-gray-600 ml-1">{{ $plan['period'] }}</span>
                        </div>
                        <p class="text-gray-600 mb-6"
                            style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                            {{ $plan['description'] }}
                        </p>
                    </div>

                    <ul class="space-y-3 mb-8">
                        @foreach($plan['features'] as $feature)
                        <li class="flex items-center text-sm"
                            style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                            <span class="text-green-500">✓</span>
                            {{ $feature }}
                        </li>
                        @endforeach
                    </ul>

                    <button
                        class="w-full py-3 px-6 rounded-xl font-semibold transition-all duration-200 {{ $plan['buttonStyle'] === 'primary' ? 'bg-purple-600 text-white hover:bg-purple-700 shadow-lg hover:shadow-xl' : 'bg-gray-200 text-gray-800 hover:bg-gray-300' }}"
                        style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        {{ $plan['buttonText'] }}
                    </button>
                </div>
                @endforeach
            </div>

            <div class="text-center mt-12">
                <p class="text-sm text-gray-500"
                    style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                    All plans include
                    <a href="#" class="text-purple-600 hover:underline">enterprise security</a>,
                    <a href="#" class="text-purple-600 hover:underline">GDPR compliance</a>, and
                    <a href="#" class="text-purple-600 hover:underline">24/7 support</a>.
                </p>
            </div>
        </div>

        <div class="bg-gradient-to-r from-slate-900 via-purple-900 to-slate-900 rounded-3xl overflow-hidden">
            <div class="container mx-auto">
                <div class="flex flex-col lg:flex-row items-center">
                    <div class="flex-1 p-16 text-white">
                        <div class="w-20 h-20 bg-white/10 rounded-3xl flex items-center justify-center mb-8">
                            <svg class="h-10 w-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 13H13V19H11V13H5V11H11V5H13V11H19V13Z"></path>
                            </svg>
                        </div>
                        <h3 class="text-5xl font-bold mb-8 text-white"
                            style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                            {{ $advancedMergingFeature['title'] }}
                        </h3>
                        <p class="text-xl text-gray-300 leading-relaxed mb-8"
                            style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                            {{ $advancedMergingFeature['description'] }}
                        </p>
                        <button
                            class="bg-white text-purple-900 px-8 py-4 rounded-xl font-semibold hover:bg-gray-100 transition-colors">
                            Try Advanced Merging
                        </button>
                    </div>
                    <div class="flex-1">
                        <img src="{{ $advancedMergingFeature['image'] ?? '/placeholder.svg' }}"
                            alt="{{ $advancedMergingFeature['title'] }}"
                            class="w-full h-96 lg:h-[500px] object-cover" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Full-width gray section -->
<section class="py-20 bg-gray-100">
    <div class="container mx-auto px-4">
        <div class="text-center mb-16">
            <h2 class="text-4xl md:text-5xl font-bold mb-6 text-balance text-gray-900"
                style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                Your Ultimate PDF Solution
            </h2>
            <p class="text-xl text-gray-600 max-w-3xl mx-auto text-pretty"
                style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                Experience the power of professional PDF processing with our comprehensive suite of tools designed for
                modern workflows.
            </p>
        </div>

        <div class="max-w-6xl mx-auto">
            <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                <div class="p-8">
                    <div class="text-center mb-8">
                        <h3 class="text-2xl font-bold text-gray-900 mb-4">SPACECRAFT DESIGN</h3>
                        <div class="flex justify-center items-center space-x-8">
                            <div class="text-center">
                                <div class="w-32 h-24 bg-gray-200 rounded-lg mb-2"></div>
                                <p class="text-sm text-gray-600">Space Shuttle</p>
                            </div>
                            <div class="text-center">
                                <div class="w-32 h-24 bg-gray-200 rounded-lg mb-2"></div>
                                <p class="text-sm text-gray-600">Astronaut</p>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="space-y-4">
                            <h4 class="font-semibold text-gray-900">Edit Content</h4>
                            <div class="space-y-2">
                                <button
                                    class="w-full text-left px-3 py-2 bg-gray-50 rounded-lg hover:bg-gray-100">Insert
                                    Text</button>
                                <button
                                    class="w-full text-left px-3 py-2 bg-gray-50 rounded-lg hover:bg-gray-100">Insert
                                    Image</button>
                                <button
                                    class="w-full text-left px-3 py-2 bg-gray-50 rounded-lg hover:bg-gray-100">Erase</button>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <h4 class="font-semibold text-gray-900">Text Properties</h4>
                            <div class="space-y-2">
                                <select class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    <option>Font Family</option>
                                </select>
                                <select class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    <option>Size</option>
                                </select>
                                <select class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    <option>Color</option>
                                </select>
                                <select class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    <option>Alignment</option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <h4 class="font-semibold text-gray-900">Tools</h4>
                            <div class="text-center">
                                <button
                                    class="px-6 py-3 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700">
                                    Highlight
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>