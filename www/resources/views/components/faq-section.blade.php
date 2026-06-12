@php
$faqs = [
    [
        'question' => 'How secure is {{ config('app.name') }} for sensitive documents?',
        'answer' => '{{ config('app.name') }} uses bank-level AES-256 encryption for all file transfers and processing. Your documents are processed securely and automatically deleted from our servers after processing. We are SOC 2 Type II certified and GDPR compliant.',
    ],
    [
        'question' => 'What file formats can I convert PDFs to?',
        'answer' => '{{ config('app.name') }} supports conversion to Word (DOCX), Excel (XLSX), PowerPoint (PPTX), various image formats (PNG, JPG, TIFF), HTML, and plain text. We also support batch conversions for multiple files simultaneously.',
    ],
    [
        'question' => 'Is there a file size limit for uploads?',
        'answer' => 'Free accounts can process files up to 100MB. Pro accounts support files up to 1GB, and Enterprise accounts have no file size limits. We also support batch processing of multiple files.',
    ],
    [
        'question' => 'Can I automate PDF processing for my business?',
        'answer' => 'Yes! {{ config('app.name') }} offers powerful automation features including API access, webhook integrations, scheduled processing, and custom workflow builders. Enterprise customers get dedicated support for complex automation setups.',
    ],
    [
        'question' => 'How accurate is the OCR and text extraction?',
        'answer' => 'Our OCR technology achieves 99%+ accuracy on clear documents and supports over 100 languages. We use advanced AI models to preserve formatting, tables, and layout structure during text extraction.',
    ],
    [
        'question' => 'Do you offer team collaboration features?',
        'answer' => 'Pro and Enterprise plans include team workspaces, shared folders, user management, audit logs, and collaborative annotation tools. Administrators can set permissions and track document processing across the team.',
    ],
];
@endphp

<section class="py-20 content-section" style="background-color: #f8fafc;">
    <div class="container mx-auto px-4">
        <div class="text-center mb-16">
            <h2
                class="text-4xl md:text-5xl font-bold mb-6 text-balance text-gray-900"
                style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;"
            >
                Convert PDF FAQs
            </h2>
            <p
                class="text-xl text-gray-600 max-w-3xl mx-auto text-pretty"
                style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;"
            >
                Everything you need to know about {{ config('app.name') }}'s features, security, and pricing.
            </p>
        </div>

        <div class="max-w-4xl mx-auto">
            <div class="space-y-6" x-data="{ openItems: ['item-0', 'item-1'] }">
                @foreach($faqs as $index => $faq)
                <div class="bg-white rounded-2xl border-0 shadow-sm">
                    <button
                        @click="openItems.includes('item-{{ $index }}') ? openItems = openItems.filter(item => item !== 'item-{{ $index }}') : openItems.push('item-{{ $index }}')"
                        class="w-full px-6 py-6 text-left hover:no-underline focus:outline-none"
                        style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-lg font-semibold text-gray-900">{{ $faq['question'] }}</span>
                            <svg 
                                class="w-4 h-4 text-gray-500 transition-transform duration-200"
                                :class="{ 'rotate-180': openItems.includes('item-{{ $index }}') }"
                                fill="none" 
                                stroke="currentColor" 
                                viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </button>
                    <div 
                        x-show="openItems.includes('item-{{ $index }}')"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 transform -translate-y-2"
                        x-transition:enter-end="opacity-100 transform translate-y-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 transform translate-y-0"
                        x-transition:leave-end="opacity-0 transform -translate-y-2"
                        class="px-6 pb-6"
                    >
                        <p
                            class="text-[16px] text-gray-600 leading-relaxed"
                            style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;"
                        >
                            {{ $faq['answer'] }}
                        </p>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</section>






