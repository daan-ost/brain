<!-- Upload Module -->
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
                    {{ __('upload.credits_available', ['count' => 15], app()->getLocale()) }}
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
                    <h3 class="text-xl font-bold">{{ __('upload.step_title', [], app()->getLocale()) }}</h3>
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
                                                {{ __('upload.select_files', [], app()->getLocale()) }}
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
                                    <div class="text-sm text-gray-600 mt-2">{{ __('upload.file_size_limit', [], app()->getLocale()) }}</div>

                                    <!-- Security & Compliance Icons -->
                                    <div class="mt-6 space-y-3">
                                        <div class="flex justify-center items-center space-x-6 text-sm text-gray-600">
                                            <div class="flex items-center space-x-2">
                                                <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
                                                    <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" fill="none"/>
                                                </svg>
                                                <span>{{ __('upload.ssl_encryption', [], app()->getLocale()) }}</span>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <svg class="w-4 h-4 text-red-500" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                                                    <path d="M8 12h8M12 8v8" stroke="currentColor" stroke-width="2"/>
                                                </svg>
                                                <span>{{ __('upload.no_data_sharing', [], app()->getLocale()) }}</span>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <svg class="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/>
                                                    <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke="currentColor" stroke-width="2" fill="none"/>
                                                </svg>
                                                <span>{{ __('upload.auto_deleted', [], app()->getLocale()) }}</span>
                                            </div>
                                        </div>
                                        <div class="flex justify-center">
                                            <div class="flex items-center space-x-2 px-3 py-1 bg-gray-100 rounded-full text-sm text-gray-600">
                                                <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
                                                    <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" fill="none"/>
                                                </svg>
                                                <span>{{ __('upload.gdpr_compliant', [], app()->getLocale()) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <template x-if="activeStep !== 1">
                        <div class="text-center">
                            <div class="text-gray-400 mb-2">{{ __('upload.ready_to_upload', [], app()->getLocale()) }}</div>
                            <div class="text-sm text-gray-400">{{ __('upload.click_to_activate', [], app()->getLocale()) }}</div>
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
                <h3 class="text-xl font-bold">{{ __('upload.specify_title', [], app()->getLocale()) }}</h3>
            </div>

            <div class="flex-1 flex flex-col">
                <template x-if="activeStep === 2 && uploadedFiles.length > 0">
                    <div class="space-y-4">
                        <div>
                            <h4 class="font-semibold text-gray-900 mb-3" x-text="`{{ __('upload.selected_files', [], app()->getLocale()) }} (${uploadedFiles.length})`"></h4>
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
                                        {{ __('upload.show_more', [], app()->getLocale()) }} (<span x-text="uploadedFiles.length - 3"></span> {{ __('upload.files', [], app()->getLocale()) }})
                                    </button>
                                </template>
                            </div>
                        </div>

                        <div class="space-y-4 pt-4 border-t border-gray-200">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('upload.page_size', [], app()->getLocale()) }}</label>
                                    <select class="w-full p-2 border border-gray-300 rounded-md text-sm">
                                        <option>A4</option>
                                        <option>Letter</option>
                                        <option>Legal</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('upload.orientation', [], app()->getLocale()) }}</label>
                                    <select class="w-full p-2 border border-gray-300 rounded-md text-sm">
                                        <option>{{ __('upload.portrait', [], app()->getLocale()) }}</option>
                                        <option>{{ __('upload.landscape', [], app()->getLocale()) }}</option>
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
                                {{ __('upload.convert_button', [], app()->getLocale()) }}
                            </button>
                        </div>
                    </div>
                </template>

                <template x-if="!(activeStep === 2 && uploadedFiles.length > 0)">
                    <div class="flex-1 flex flex-col justify-center text-center">
                        <div class="text-gray-400 mb-2">{{ __('upload.configure_settings', [], app()->getLocale()) }}</div>
                        <div class="text-sm text-gray-400">{{ __('upload.upload_files_first', [], app()->getLocale()) }}</div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Box 3: Convert -->
        <div class="relative transition-all cursor-pointer" :class="activeStep === 3 ? 'lg:flex-[2]' : 'lg:flex-1'">
            <div class="absolute -top-3 -right-3 bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-sm font-medium border border-orange-200">
                {{ __('upload.credits_after_conversion', ['remaining' => 12, 'used' => 3], app()->getLocale()) }}
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
                    <h3 class="text-xl font-bold">{{ __('upload.convert_title', [], app()->getLocale()) }}</h3>
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
                                    {{ __('upload.download_pdf', [], app()->getLocale()) }}
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
                                    <span>{{ __('upload.delete_now', [], app()->getLocale()) }}</span>
                                </button>
                                <button class="flex items-center space-x-2 px-4 py-2 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-md transition-colors">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M15 8a3 3 0 10-2.977-2.63l-4.94 2.47a3 3 0 100 4.319l4.94 2.47a3 3 0 10.895-1.789l-4.94-2.47a3.027 3.027 0 000-.74l4.94-2.47C13.456 7.68 14.19 8 15 8z" />
                                    </svg>
                                    <span>{{ __('upload.share', [], app()->getLocale()) }}</span>
                                </button>
                            </div>
                        </div>
                    </template>

                    <template x-if="activeStep !== 3">
                        <div class="flex-1 flex flex-col justify-center text-center">
                            <div class="text-gray-400 mb-2">{{ __('upload.process_download', [], app()->getLocale()) }}</div>
                            <div class="text-sm text-gray-400">{{ __('upload.complete_previous_steps', [], app()->getLocale()) }}</div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>