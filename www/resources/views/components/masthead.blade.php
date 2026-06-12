<section
    class="masthead relative overflow-visible isolation-isolate"
    style="
        background: linear-gradient(135deg, #9FD6D2 0%, #53B3AE 100%);
        min-height: 700px;
    "
    x-data="{
        activeStep: 1,
        uploadedFiles: [],
        nextSteps: [{ action: '', isOpen: false }],
        workflowName: '',
        currentRotatorIndex: 1,
        rotatorItems: ['Merge Word', 'Merge PDF', 'Merge Presentation', 'Merge HTML', 'Merge Images'],
        isMenuOpen: false,
        isAppLauncherOpen: false,
        activeSubmenuItem: 'Merge PDF',
        selectedFileSource: 'My device',
        emailNotification: false,
        emailAddress: '',
        mockFiles: [
            { id: 1, name: 'Schermafbeelding 2025-09-09 om 14.33.55.png', size: '151.4 KB' },
            { id: 2, name: 'Schermafbeelding 2025-09-09 om 14.33.01.png', size: '152.8 KB' },
            { id: 3, name: 'Schermafbeelding 2025-09-09 om 13.31.40.png', size: '44.8 KB' },
            { id: 4, name: 'Document 2025-09-09 om 15.22.15.pdf', size: '2.1 MB' },
            { id: 5, name: 'Report 2025-09-09 om 16.45.30.pdf', size: '3.2 MB' },
            { id: 6, name: 'Invoice 2025-09-09 om 17.12.45.pdf', size: '1.8 MB' },
            { id: 7, name: 'Contract 2025-09-09 om 18.30.15.pdf', size: '2.5 MB' },
            { id: 8, name: 'Presentation 2025-09-09 om 19.15.20.pdf', size: '4.1 MB' }
        ],
        goToNext() {
            this.currentRotatorIndex = (this.currentRotatorIndex + 1) % this.rotatorItems.length;
        },
        goToPrevious() {
            this.currentRotatorIndex = (this.currentRotatorIndex - 1 + this.rotatorItems.length) % this.rotatorItems.length;
        },
        getVisibleItems() {
            const visible = [];
            for (let i = -1; i <= 2; i++) {
                const index = (this.currentRotatorIndex + i + this.rotatorItems.length) % this.rotatorItems.length;
                visible.push({
                    item: this.rotatorItems[index],
                    isActive: i === 0,
                });
            }
            return visible;
        },
        handleFileUpload() {
            console.log('handleFileUpload called');
            this.uploadedFiles = this.mockFiles;
            this.activeStep = 2;
            console.log('activeStep set to 2, uploadedFiles:', this.mockFiles);
        },
        removeFile(fileId) {
            this.uploadedFiles = this.uploadedFiles.filter((file) => file.id !== fileId);
        },
        addNextStep() {
            const newStep = {
                id: Date.now(),
                action: '',
            };
            this.nextSteps = [...this.nextSteps, newStep];
        },
        removeNextStep(stepId) {
            this.nextSteps = this.nextSteps.filter((step) => step.id !== stepId);
        },
        updateNextStep(stepId, action) {
            this.nextSteps = this.nextSteps.map((step) => (step.id === stepId ? { ...step, action } : step));
        },
        calculateBoxHeight() {
            const baseHeight = 600;
            const additionalHeight = this.nextSteps.length * 60;
            return Math.max(baseHeight, baseHeight + additionalHeight);
        }
    }"
>
    <!-- Header -->
    <header class="relative z-50" style="border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
        <div class="container mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <!-- Logo -->
                <div class="flex items-center">
                    <a href="/" aria-label="{{ config('app.name') }} homepage">
                        <div class="flex items-center">
                            <img
                                src="/favicon.svg"
                                alt="{{ config('app.name') }} Logo"
                                class="h-8 w-auto"
                            />
                        </div>
                    </a>
                </div>

                <!-- Desktop Navigation -->
                <nav class="hidden md:flex items-center" style="gap: 32px;">
                    <!-- Convert Dropdown -->
                    <div class="relative" x-data="{ open: false }">
                        <button 
                            @click="open = !open"
                            class="flex items-center text-white hover:text-white/80 transition-colors font-medium text-[15px]"
                        >
                            Convert
                            <svg class="ml-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        
                        <div 
                            x-show="open" 
                            @click.away="open = false"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="absolute top-full left-0 mt-2 w-[600px] p-8 bg-white rounded-2xl shadow-2xl border-0 z-50"
                            style="display: none;"
                        >
                            <div class="flex gap-8">
                                <div class="w-[200px]">
                                    <h3 class="font-bold text-lg mb-2 text-gray-900">Convert Files</h3>
                                    <p class="text-sm text-gray-600 mb-6">Transform your documents between different formats seamlessly</p>
                                    <div class="w-full h-[120px] bg-gradient-to-br from-blue-50 to-blue-100 rounded-2xl flex items-center justify-center shadow-lg">
                                        <div class="w-12 h-12 bg-blue-500 rounded-2xl flex items-center justify-center">
                                            <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="flex-1 grid grid-cols-2 gap-8">
                                    <div>
                                        <h4 class="font-semibold text-[14px] uppercase tracking-wide mb-4 text-gray-500">FROM PDF</h4>
                                        <ul class="space-y-3">
                                            <li><a href="#" class="text-[16px] text-gray-900 hover:text-primary transition-colors">PDF to Word</a></li>
                                            <li><a href="#" class="text-[16px] text-gray-900 hover:text-primary transition-colors">PDF to Excel</a></li>
                                            <li><a href="#" class="text-[16px] text-gray-900 hover:text-primary transition-colors">PDF to PowerPoint</a></li>
                                        </ul>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-[14px] uppercase tracking-wide mb-4 text-gray-500">TO PDF</h4>
                                        <ul class="space-y-3">
                                            <li><a href="#" class="text-[16px] text-gray-900 hover:text-primary transition-colors">Word to PDF</a></li>
                                            <li><a href="#" class="text-[16px] text-gray-900 hover:text-primary transition-colors">Excel to PDF</a></li>
                                            <li><a href="#" class="text-[16px] text-gray-900 hover:text-primary transition-colors">PowerPoint to PDF</a></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <a href="#" class="text-white hover:text-white/80 transition-colors font-medium text-[15px]">
                        Merge
                    </a>
                    <a href="#" class="text-white hover:text-white/80 transition-colors font-medium text-[15px]">
                        Compress
                    </a>
                    <a href="#" class="text-white hover:text-white/80 transition-colors font-medium text-[15px]">
                        Automate
                    </a>
                    <a href="#" class="text-white hover:text-white/80 transition-colors font-medium text-[15px]">
                        Extract
                    </a>

                    <!-- All Tools Dropdown -->
                    <div class="relative" x-data="{ open: false }">
                        <button 
                            @click="open = !open"
                            class="flex items-center text-white hover:text-white/80 transition-colors font-medium text-[15px]"
                        >
                            All 
                            <svg class="ml-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        
                        <div 
                            x-show="open" 
                            @click.away="open = false"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="absolute top-full left-1/2 transform -translate-x-1/2 mt-2 w-[920px] h-[380px] p-8 bg-white rounded-2xl shadow-2xl border-0 z-50"
                            style="display: none;"
                        >
                            <div class="flex gap-8 h-full">
                                <div class="w-[280px]">
                                    <h3 class="font-bold text-lg mb-2 text-gray-900">All PDF Tools</h3>
                                    <p class="text-sm text-gray-600 mb-6">Complete suite of PDF processing tools for every workflow need</p>
                                    <div class="w-full h-[180px] bg-gradient-to-br from-orange-400 to-blue-500 rounded-2xl flex items-center justify-center shadow-lg">
                                        <div class="w-16 h-16 bg-white rounded-2xl flex items-center justify-center">
                                            <svg class="w-8 h-8 text-gray-700" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/>
                                                <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke="currentColor" stroke-width="2" fill="none"/>
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex-1 grid grid-cols-3 gap-8">
                                    <div>
                                        <h4 class="font-semibold text-[14px] uppercase tracking-wide mb-4 text-gray-500">CONVERT</h4>
                                        <ul class="space-y-3">
                                            <li>
                                                <a href="#" class="flex items-center text-[16px] text-gray-900 hover:text-primary transition-colors">
                                                    <div class="w-4 h-4 bg-red-500 rounded mr-2"></div>
                                                    PDF to Word
                                                </a>
                                            </li>
                                            <li>
                                                <a href="#" class="flex items-center text-[16px] text-gray-900 hover:text-primary transition-colors">
                                                    <div class="w-4 h-4 bg-green-500 rounded mr-2"></div>
                                                    PDF to Excel
                                                </a>
                                            </li>
                                            <li>
                                                <a href="#" class="flex items-center text-[16px] text-gray-900 hover:text-primary transition-colors">
                                                    <div class="w-4 h-4 bg-orange-500 rounded mr-2"></div>
                                                    PDF to PowerPoint
                                                </a>
                                            </li>
                                            <li>
                                                <a href="#" class="flex items-center text-[16px] text-gray-900 hover:text-primary transition-colors">
                                                    <div class="w-4 h-4 bg-purple-500 rounded mr-2"></div>
                                                    PDF to Images
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-[14px] uppercase tracking-wide mb-4 text-gray-500">TOOLS</h4>
                                        <ul class="space-y-3">
                                            <li>
                                                <a href="#" class="flex items-center text-[16px] text-gray-900 hover:text-primary transition-colors">
                                                    <div class="w-4 h-4 border-2 border-blue-500 rounded mr-2"></div>
                                                    Merge PDFs
                                                </a>
                                            </li>
                                            <li>
                                                <a href="#" class="flex items-center text-[16px] text-gray-900 hover:text-primary transition-colors">
                                                    <div class="w-4 h-4 border-2 border-red-500 rounded mr-2"></div>
                                                    Split PDF
                                                </a>
                                            </li>
                                            <li>
                                                <a href="#" class="flex items-center text-[16px] text-gray-900 hover:text-primary transition-colors">
                                                    <div class="w-4 h-4 bg-purple-500 rounded mr-2"></div>
                                                    Compress PDF
                                                </a>
                                            </li>
                                            <li>
                                                <a href="#" class="flex items-center text-[16px] text-gray-900 hover:text-primary transition-colors">
                                                    <div class="w-4 h-4 bg-yellow-500 rounded-full mr-2"></div>
                                                    Rotate PDF
                                                </a>
                                            </li>
                                            <li>
                                                <a href="#" class="flex items-center text-[16px] text-gray-900 hover:text-primary transition-colors">
                                                    <div class="w-4 h-4 bg-blue-500 rounded mr-2"></div>
                                                    Blog & Resources
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-[14px] uppercase tracking-wide mb-4 text-gray-500">ADVANCED</h4>
                                        <ul class="space-y-3">
                                            <li>
                                                <a href="#" class="flex items-center text-[16px] text-gray-900 hover:text-primary transition-colors">
                                                    <div class="w-4 h-4 bg-purple-500 transform rotate-45 mr-2"></div>
                                                    Extract Images
                                                </a>
                                            </li>
                                            <li>
                                                <a href="#" class="flex items-center text-[16px] text-gray-900 hover:text-primary transition-colors">
                                                    <div class="w-4 h-4 border-2 border-blue-300 rounded mr-2"></div>
                                                    Extract Text
                                                </a>
                                            </li>
                                            <li>
                                                <a href="#" class="flex items-center text-[16px] text-gray-900 hover:text-primary transition-colors">
                                                    <div class="w-4 h-4 border-2 border-pink-500 rounded-full mr-2"></div>
                                                    Add Watermark
                                                </a>
                                            </li>
                                            <li>
                                                <a href="#" class="flex items-center text-[16px] text-gray-900 hover:text-primary transition-colors">
                                                    <div class="w-4 h-4 bg-gray-600 rounded mr-2"></div>
                                                    Protect PDF
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <a href="/blog" class="text-white hover:text-white/80 transition-colors font-medium text-[15px]">
                        Blog
                    </a>
                    <a href="/pricing" class="text-white hover:text-white/80 transition-colors font-medium text-[15px]">
                        Pricing
                    </a>
                </nav>

                <!-- Auth Buttons -->
                <div class="hidden md:flex items-center space-x-3">
                    @guest
                    <a href="/login?redirect={{ urlencode(url()->full()) }}" class="text-white hover:bg-white/10 hover:text-white px-4 py-2 rounded-md transition-colors">
                        Login
                    </a>
                    <a href="/register?redirect={{ urlencode(url()->full()) }}" class="bg-white text-[#1e40af] hover:bg-white/90 shadow-sm px-4 py-2 rounded-md transition-colors font-semibold">
                        Sign up
                    </a>
                    @endguest

                    @auth
                    <!-- Profile Dropdown -->
                    <div class="relative" x-data="{ open: false }">
                        <button 
                            @click="open = !open"
                            class="flex items-center justify-center w-10 h-10 rounded-full bg-white/20 hover:bg-white/30 transition-colors"
                        >
                            <div class="w-8 h-8 rounded-full bg-white flex items-center justify-center">
                                <svg class="w-5 h-5 text-[#1e40af]" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                                </svg>
                            </div>
                        </button>
                        
                        <div 
                            x-show="open" 
                            @click.away="open = false"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="absolute top-full right-0 mt-2 w-48 p-2 bg-white rounded-xl shadow-xl border-0 z-50"
                            style="display: none;"
                        >
                            <div class="space-y-1">
                                <a href="#" class="flex items-center space-x-3 px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                                    <svg class="w-4 h-4 text-[#1e40af]" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                                    </svg>
                                    <span>Profile</span>
                                </a>
                                <a href="#" class="flex items-center space-x-3 px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M19.14,12.94c0.04-0.3,0.06-0.61,0.06-0.94c0-0.32-0.02-0.64-0.07-0.94l2.03-1.58c0.18-0.14,0.23-0.41,0.12-0.61 l-1.92-3.32c-0.12-0.22-0.37-0.29-0.59-0.22l-2.39,0.96c-0.5-0.38-1.03-0.7-1.62-0.94L14.4,2.81c-0.04-0.24-0.24-0.41-0.48-0.41 h-3.84c-0.24,0-0.43,0.17-0.47,0.41L9.25,5.35C8.66,5.59,8.12,5.92,7.63,6.29L5.24,5.33c-0.22-0.08-0.47,0-0.59,0.22L2.74,8.87 C2.62,9.08,2.66,9.34,2.86,9.48l2.03,1.58C4.84,11.36,4.82,11.69,4.82,12s0.02,0.64,0.07,0.94l-2.03,1.58 c-0.18,0.14-0.23,0.41-0.12,0.61l1.92,3.32c0.12,0.22,0.37,0.29,0.59,0.22l2.39-0.96c0.5,0.38,1.03,0.7,1.62,0.94l0.36,2.54 c0.05,0.24,0.24,0.41,0.48,0.41h3.84c0.24,0,0.44-0.17,0.47-0.41l0.36-2.54c0.59-0.24,1.13-0.56,1.62-0.94l2.39,0.96 c0.22,0.08,0.47,0,0.59-0.22l1.92-3.32c0.12-0.22,0.07-0.47-0.12-0.61L19.14,12.94z M12,15.6c-1.98,0-3.6-1.62-3.6-3.6 s1.62-3.6,3.6-3.6s3.6,1.62,3.6,3.6S13.98,15.6,12,15.6z" />
                                    </svg>
                                    <span>Settings</span>
                                </a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="flex items-center space-x-3 px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-lg transition-colors w-full text-left">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z" />
                                        </svg>
                                        <span>Logout</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    @endauth
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <div class="container mx-auto px-4 py-8 relative z-10">
        <div class="text-center mb-8">
            <h1
                class="text-[48px] md:text-[56px] font-black text-white mb-4 text-balance leading-[0.9] tracking-tight"
                style="font-family: MarkPro, ui-sans-serif, system-ui, 'Segoe UI', Roboto, Helvetica, Arial;"
            >
                Merge PDF files
            </h1>

            <div class="flex items-center justify-center mb-6">
                <button @click="goToPrevious()" class="p-2 text-white/60 hover:text-white transition-colors">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </button>
                <div class="flex items-center space-x-8 mx-4">
                    <template x-for="(item, index) in getVisibleItems()" :key="`${item.item}-${index}`">
                        <button
                            @click="currentRotatorIndex = rotatorItems.indexOf(item.item)"
                            class="px-4 py-2 rounded-full transition-colors font-semibold text-sm"
                            :class="item.isActive ? 'bg-black/50 text-white' : 'text-white/70 hover:text-white hover:bg-white/20'"
                            style="font-family: MarkPro, ui-sans-serif, system-ui, 'Segoe UI', Roboto, Helvetica, Arial;"
                            x-text="item.item"
                        ></button>
                    </template>
                </div>
                <button @click="goToNext()" class="p-2 text-white/60 hover:text-white transition-colors">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>
            </div>
        </div>

        <div class="flex justify-center relative z-20 -mb-32">
            <!-- 3-Box Workflow Interface -->
            <div class="flex flex-col lg:flex-row gap-6 max-w-7xl w-full">
                <!-- Box 1: Upload -->
                <div class="relative transition-all cursor-pointer" :class="activeStep === 1 ? 'lg:flex-[2]' : 'lg:flex-1'">
                    <div class="absolute -top-3 left-4 z-10">
                        <div
                            class="bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-sm font-medium"
                            style="background-color: #FFF3E0; color: #E65100;"
                        >
                            15 credits available
                        </div>
                    </div>

                    <div
                        class="rounded-[28px] p-8 shadow-lg border transition-all cursor-pointer flex flex-col"
                        :class="activeStep === 1 ? 'bg-white border-black/5 text-gray-900' : 'bg-gray-100 border-gray-200 text-gray-500'"
                        :style="`height: ${calculateBoxHeight()}px`"
                        @click="activeStep = 1"
                    >
                        <div class="flex items-center mb-6">
                            <div
                                class="rounded-full w-8 h-8 flex items-center justify-center text-sm font-semibold mr-3"
                                :class="activeStep === 1 ? 'text-white' : 'bg-gray-300 text-gray-600'"
                                style="background-color: activeStep === 1 ? '#2A73E8' : undefined"
                            >
                                1
                            </div>
                            <h3 class="text-xl font-bold">Upload</h3>
                        </div>

                        <div class="flex-1 flex flex-col justify-center">
                            <template x-if="activeStep === 1">
                                <div class="space-y-6">
                                    <div class="border-2 border-dashed border-gray-300 rounded-xl p-8 hover:border-blue-400 transition-colors cursor-pointer bg-gray-50/30">
                                        <div class="text-center">
                                            <div class="w-12 h-12 mx-auto mb-4 opacity-60">
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    class="w-full h-full text-gray-400"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="2"
                                                >
                                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                                    <polyline points="7,10 12,5 17,10" />
                                                    <line x1="12" y1="5" x2="12" y2="15" />
                                                </svg>
                                            </div>
                                            <div class="flex items-center justify-center mb-4">
                                                <div
                                                    class="flex items-center rounded-lg overflow-hidden shadow-lg transition-colors"
                                                    style="background-color: #2A73E8"
                                                >
                                                    <button
                                                        class="text-white px-6 py-3 font-semibold flex items-center transition-colors hover:opacity-90"
                                                        @click.stop="handleFileUpload()"
                                                    >
                                                        <span class="mr-2">⊕</span>
                                                        Select files or drop it here
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="flex justify-center space-x-2 mt-4">
                                                <span
                                                    class="px-3 py-1 text-sm font-medium rounded-full"
                                                    style="background-color: #FFF3E0; color: #E65100;"
                                                >
                                                    PDF
                                                </span>
                                                <span
                                                    class="px-3 py-1 text-sm font-medium rounded-full"
                                                    style="background-color: #FFF3E0; color: #E65100;"
                                                >
                                                    ZIP
                                                </span>
                                            </div>
                                            <div class="text-sm text-gray-600 mt-2">(10 MB each)</div>
                                            
                                            <!-- Security & Compliance Icons -->
                                            <div class="mt-6 space-y-3">
                                                <div class="flex justify-center items-center space-x-6 text-sm text-gray-600">
                                                    <div class="flex items-center space-x-2">
                                                        <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 24 24">
                                                            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
                                                            <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" fill="none"/>
                                                        </svg>
                                                        <span>256-bit SSL</span>
                                                    </div>
                                                    <div class="flex items-center space-x-2">
                                                        <svg class="w-4 h-4 text-red-500" fill="currentColor" viewBox="0 0 24 24">
                                                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                                                            <path d="M8 12h8M12 8v8" stroke="currentColor" stroke-width="2"/>
                                                        </svg>
                                                        <span>No data sharing</span>
                                                    </div>
                                                    <div class="flex items-center space-x-2">
                                                        <svg class="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/>
                                                            <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke="currentColor" stroke-width="2" fill="none"/>
                                                        </svg>
                                                        <span>Auto-deleted</span>
                                                    </div>
                                                </div>
                                                <div class="flex justify-center">
                                                    <div class="flex items-center space-x-2 px-3 py-1 bg-gray-100 rounded-full text-sm text-gray-600">
                                                        <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 24 24">
                                                            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
                                                            <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" fill="none"/>
                                                        </svg>
                                                        <span>GDPR Compliant</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <template x-if="activeStep !== 1">
                                <div class="text-center">
                                    <div class="text-gray-400 mb-2">Ready to upload files</div>
                                    <div class="text-sm text-gray-400">Click to activate</div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Box 2: Specify -->
                <div
                    class="transition-all cursor-pointer flex flex-col rounded-[28px] p-8 shadow-lg border"
                    :class="activeStep === 2 ? 'lg:flex-[2] bg-white border-black/5 text-gray-900' : 'lg:flex-1 bg-gray-100 border-gray-200 text-gray-500'"
                    :style="`height: ${calculateBoxHeight()}px`"
                    @click="activeStep = 2"
                >
                    <div class="flex items-center mb-6">
                        <div
                            class="rounded-full w-8 h-8 flex items-center justify-center text-sm font-semibold mr-3"
                            :class="activeStep === 2 ? 'text-white' : 'bg-gray-300 text-gray-600'"
                            style="background-color: activeStep === 2 ? '#2A73E8' : undefined"
                        >
                            2
                        </div>
                        <h3 class="text-xl font-bold">Specify</h3>
                    </div>

                    <div class="flex-1 flex flex-col">
                        <template x-if="activeStep === 2 && uploadedFiles.length > 0">
                            <div class="space-y-4">
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-3" x-text="`Selected Files (${uploadedFiles.length})`"></h4>
                                    <div class="space-y-2 max-h-32 overflow-y-auto">
                                        <template x-for="file in uploadedFiles.slice(0, 3)" :key="file.id">
                                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border">
                                                <div class="flex items-center space-x-3">
                                                    <div class="w-8 h-8 bg-blue-100 rounded flex items-center justify-center">
                                                        <svg class="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                                            <path
                                                                fill-rule="evenodd"
                                                                d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z"
                                                                clip-rule="evenodd"
                                                            />
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <div class="text-sm font-medium text-gray-900 truncate max-w-48" x-text="file.name"></div>
                                                        <div class="text-xs text-gray-500" x-text="file.size"></div>
                                                    </div>
                                                </div>
                                                <button
                                                    @click="removeFile(file.id)"
                                                    class="text-red-500 hover:text-red-700 transition-colors"
                                                >
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </template>
                                        <template x-if="uploadedFiles.length > 3">
                                            <button class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                                Show more (<span x-text="uploadedFiles.length - 3"></span> files)
                                            </button>
                                        </template>
                                    </div>
                                </div>

                                <div class="space-y-4 pt-4 border-t border-gray-200">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Page Size</label>
                                            <select class="w-full p-2 border border-gray-300 rounded-md text-sm">
                                                <option>A4</option>
                                                <option>Letter</option>
                                                <option>Legal</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Orientation</label>
                                            <select class="w-full p-2 border border-gray-300 rounded-md text-sm">
                                                <option>Portrait</option>
                                                <option>Landscape</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="pt-4 border-t border-gray-200">
                                    <button
                                        class="w-full text-white px-6 py-3 rounded-lg font-semibold transition-colors hover:opacity-90"
                                        style="background-color: #2A73E8"
                                        @click.stop="activeStep = 3"
                                    >
                                        Convert
                                    </button>
                                </div>
                            </div>
                        </template>

                        <template x-if="!(activeStep === 2 && uploadedFiles.length > 0)">
                            <div class="flex-1 flex flex-col justify-center text-center">
                                <div class="text-gray-400 mb-2">Configure settings</div>
                                <div class="text-sm text-gray-400">Upload files first</div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Box 3: Convert -->
                <div class="relative transition-all cursor-pointer" :class="activeStep === 3 ? 'lg:flex-[2]' : 'lg:flex-1'">
                    <div class="absolute -top-3 -right-3 bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-sm font-medium border border-orange-200">
                        12 credits (-3)
                    </div>

                    <div
                        class="rounded-[28px] p-8 shadow-lg border transition-all cursor-pointer flex flex-col"
                        :class="activeStep === 3 ? 'bg-white border-black/5 text-gray-900' : 'bg-gray-100 border-gray-200 text-gray-500'"
                        :style="`height: ${calculateBoxHeight()}px`"
                        @click="activeStep = 3"
                    >
                        <div class="flex items-center mb-6">
                            <div
                                class="rounded-full w-8 h-8 flex items-center justify-center text-sm font-semibold mr-3"
                                :class="activeStep === 3 ? 'text-white' : 'bg-gray-300 text-gray-600'"
                                style="background-color: activeStep === 3 ? '#2A73E8' : undefined"
                            >
                                3
                            </div>
                            <h3 class="text-xl font-bold">Convert</h3>
                        </div>

                        <div class="flex-1 flex flex-col">
                            <template x-if="activeStep === 3">
                                <div class="space-y-6">
                                    <!-- Download Section -->
                                    <div class="text-center">
                                        <button
                                            class="text-white px-8 py-4 rounded-lg font-semibold text-lg transition-colors hover:opacity-90 mb-2"
                                            style="background-color: #4CAF50"
                                        >
                                            Download PDF
                                        </button>
                                        <div class="text-sm text-gray-600">100kb</div>
                                    </div>

                                    <!-- Options -->
                                    <div class="flex justify-center space-x-4 pt-4 border-t border-gray-200">
                                        <button class="flex items-center space-x-2 px-4 py-2 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md transition-colors">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                <path
                                                    fill-rule="evenodd"
                                                    d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z"
                                                    clip-rule="evenodd"
                                                />
                                            </svg>
                                            <span>Delete now</span>
                                        </button>
                                        <button class="flex items-center space-x-2 px-4 py-2 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-md transition-colors">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M15 8a3 3 0 10-2.977-2.63l-4.94 2.47a3 3 0 100 4.319l4.94 2.47a3 3 0 10.895-1.789l-4.94-2.47a3.027 3.027 0 000-.74l4.94-2.47C13.456 7.68 14.19 8 15 8z" />
                                            </svg>
                                            <span>Share</span>
                                        </button>
                                    </div>
                                </div>
                            </template>

                            <template x-if="activeStep !== 3">
                                <div class="flex-1 flex flex-col justify-center text-center">
                                    <div class="text-gray-400 mb-2">Process & download</div>
                                    <div class="text-sm text-gray-400">Complete previous steps</div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>




