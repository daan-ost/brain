<section class="gradient-hero relative overflow-visible">
    <div
        class="absolute inset-0"
        style="background: linear-gradient(90deg, #2AA7A0 0%, #6FC7C2 50%, #A9E1DE 100%);"
    ></div>

    <svg class="absolute bottom-[-1px] left-0 w-full h-[120px]" viewBox="0 0 1440 120" preserveAspectRatio="none">
        <path d="M0,40 C300,120 1140,-20 1440,60 L1440,120 L0,120 Z" fill="url(#heroGrad)" />
        <defs>
            <linearGradient id="heroGrad" x1="0" x2="1">
                <stop offset="0%" stop-color="#2AA7A0" />
                <stop offset="50%" stop-color="#6FC7C2" />
                <stop offset="100%" stop-color="#A9E1DE" />
            </linearGradient>
        </defs>
    </svg>

    <div class="container mx-auto px-4 py-20 relative z-10">
        <div class="text-center mb-12">
            <h1
                class="text-[56px] md:text-[64px] font-black text-white mb-6 text-balance leading-[0.95] tracking-tight"
                style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;"
            >
                Merge PDF files
            </h1>
            <p class="text-2xl md:text-3xl text-white/80 mb-8 max-w-[700px] mx-auto text-pretty font-medium leading-relaxed">
                Upload more PDF files or a ZIP
            </p>
        </div>

        <div class="flex justify-center relative z-20 -mb-16">
            <div
                class="bg-white p-8 text-gray-900 rounded-sm"
                style="
                    width: 940px;
                    height: 450px;
                    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.1);
                    border: 1px solid rgba(255, 255, 255, 0.2);
                "
            >
                <div class="text-center h-full flex flex-col justify-between">
                    <div class="flex flex-col items-center">
                        <div class="w-16 h-16 bg-primary/10 rounded-2xl flex items-center justify-center mx-auto mb-4">
                            <svg class="h-8 w-8 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-semibold mb-4 text-gray-900 font-sans">Drop your PDF here</h3>
                        <p class="text-gray-600 mb-6 font-sans">Or click to browse and select your files</p>
                        <div class="border-2 border-dashed border-gray-300 rounded-xl p-8 mb-6 hover:border-primary/50 transition-colors cursor-pointer bg-gray-50/50">
                            <svg class="h-8 w-8 text-gray-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <p class="text-gray-600 font-sans text-sm">Drag & drop your PDF files here</p>
                        </div>
                    </div>
                    <button class="w-full bg-gray-800 text-white px-6 py-3 rounded-lg font-semibold transition-colors hover:opacity-90 flex items-center justify-center">
                        <svg class="mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        Start Processing
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>






