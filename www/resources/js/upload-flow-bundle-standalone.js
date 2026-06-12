/**
 * Upload Flow Bundle - Standalone Version
 * Combined modules without ES6 import/export syntax
 */

(function(window) {
    'use strict';

    /**
     * Upload Flow Utilities
     * Shared helper functions for file handling and formatting
     */
    const UploadUtils = {
        /**
         * Format bytes to human readable size
         */
        formatFileSize(bytes) {
            const units = ['B', 'KB', 'MB', 'GB'];
            const factor = Math.floor(Math.log(bytes) / Math.log(1024));
            return (bytes / Math.pow(1024, factor)).toFixed(1) + ' ' + units[factor];
        },

        /**
         * Get file extension from filename
         */
        getFileExtension(filename) {
            return filename.split('.').pop().toLowerCase();
        },

        /**
         * Validate file against allowed types and size limits
         */
        validateFile(file, allowedTypes, maxSize = 50 * 1024 * 1024) {
            const ext = this.getFileExtension(file.name);
            return allowedTypes.includes(ext) && file.size <= maxSize;
        },

        /**
         * Map mime group names to file extensions
         */
        getMimeGroupExtensions(groups) {
            const typeMap = {
                'office': ["docx", "doc", "xlsx", "xls", "pptx", "ppt"],
                'office_word': ["docx", "doc"],
                'office_excel': ["xlsx", "xls"],
                'office_powerpoint': ["pptx", "ppt"],
                'powerpoint': ["pptx", "ppt"],
                'excel': ["xls", "xlsx"],
                'text': ["txt", "log", "csv"],
                'images': ["jpg", "jpeg", "png", "webp", "tiff", "heic"],
                'html': ["html", "htm"],
                'pdf': ["pdf"],
                'pdf_operations': ["pdf"],
                'pdf_reverse': ["pdf"],
                'autocad': ["dwf", "dwfx", "dwg", "dxf"],
                'postscript': ["eps", "ps", "prn"],
                'csv_excel': ["csv", "xls", "xlsb", "xltx"],
                'publisher': ["pub"],
                'rtf_word': ["rtf", "dot", "dotx", "wpd", "log"],
                'ebook': ["epub", "mobi", "djvu"],
                'visio': ["vsd", "vsdx"],
                'markdown': ["md"],
                'opendocument': ["odg"],
                'zips': ["zip"],
            };

            let extensions = [];
            groups.forEach(group => {
                if (typeMap[group]) {
                    extensions = extensions.concat(typeMap[group]);
                }
            });

            return [...new Set(extensions)];
        },

        /**
         * Check if file can be previewed
         */
        canPreviewFile(file) {
            // Check if file from ZIP has preview content
            if (file.isFromZip && file.previewContent) {
                return true;
            }

            // Can't preview other files from ZIP without content
            if (file.isFromZip) return false;

            const ext = this.getFileExtension(file.name);
            return ['pdf', 'jpg', 'jpeg', 'png', 'webp'].includes(ext);
        },

        /**
         * Create file object for internal tracking
         */
        createFileObject(file, isFromZip = false, parentZip = null) {
            return {
                id: Date.now() + Math.random(),
                name: file.name,
                size: file.size,
                file: file,
                isFromZip: isFromZip,
                parentZip: parentZip
            };
        },

        /**
         * Format step display names
         */
        getStepDisplayName(stepType) {
            const names = {
                'image_to_pdf': 'Convert Images to PDF',
                'excel_to_pdf': 'Convert Excel to PDF',
                'word_to_pdf': 'Convert Word to PDF',
                'powerpoint_to_pdf': 'Convert PowerPoint to PDF',
                'merge_pdfs': 'Merge PDF Files',
                'compress_pdf': 'Compress PDF',
                'zip_output': 'Package as ZIP',
                'generic_convert_to_pdf': 'Convert to PDF'
            };
            return names[stepType] || stepType.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        },

        /**
         * Format option keys for display
         */
        formatOptionKey(key) {
            // Check if we have a translation for this key
            if (window.i18n && window.i18n.options && window.i18n.options[key]) {
                return window.i18n.options[key].name;
            }
            // Fallback to formatting the key itself
            return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        },

        /**
         * Get option description
         */
        getOptionDescription(key) {
            // Check if we have a translation for this key
            if (window.i18n && window.i18n.options && window.i18n.options[key]) {
                return window.i18n.options[key].description;
            }
            return '';
        }
    };

    /**
     * Upload Flow API Service
     * Handles all API communication for the upload flow
     */
    const UploadAPI = {
        /**
         * Get CSRF token from meta tag
         */
        getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        },

        /**
         * Load user credits
         */
        async loadUserCredits() {
            try {
                const response = await fetch('/api/user/credits');
                if (response.ok) {
                    return await response.json();
                }
                console.warn('Credits API failed with status:', response.status);
                return null;
            } catch (error) {
                console.warn('Credits API error:', error);
                return null;
            }
        },

        /**
         * Load user workflows for a specific page
         */
        async loadUserWorkflows(pageSlug) {
            try {
                const response = await fetch(`/api/user/workflows?page=${pageSlug}`);
                if (response.ok) {
                    return await response.json();
                }
                console.warn('Workflows API failed with status:', response.status);
                return null;
            } catch (error) {
                console.warn('Workflows API error:', error);
                return null;
            }
        },

        /**
         * Validate credits for conversion
         */
        async validateCredits(pageSlug, fileCount, workflowId = null) {
            try {
                const response = await fetch('/api/validate-credits', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.getCsrfToken()
                    },
                    body: JSON.stringify({
                        page_slug: pageSlug,
                        file_count: fileCount,
                        workflow_id: workflowId
                    })
                });

                if (response.ok) {
                    return await response.json();
                }
                console.warn('Credits validation failed with status:', response.status);
                return null;
            } catch (error) {
                console.error('Credits validation failed:', error);
                return null;
            }
        },

        /**
         * Preview ZIP file contents
         */
        async previewZipFiles(files, pageSlug) {
            const zipFiles = Array.from(files).filter(f => f.name.toLowerCase().endsWith('.zip'));
            if (zipFiles.length === 0) {
                return { success: true, extracted_files: [] };
            }

            try {
                const formData = new FormData();
                zipFiles.forEach(file => formData.append('files[]', file));
                formData.append('page_slug', pageSlug);

                const response = await fetch('/api/preview-zip', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                if (response.ok) {
                    return await response.json();
                }
                throw new Error('Failed to preview ZIP files');
            } catch (error) {
                console.error('ZIP preview error:', error);
                throw error;
            }
        },

        /**
         * Upload and convert files
         */
        async uploadAndConvert(pageSlug, files, workflowId = null, conversionOptions = {}) {
            const formData = new FormData();
            formData.append('page_slug', pageSlug);

            if (workflowId) {
                formData.append('workflow_id', workflowId);
            }

            // Only send real files (not virtual files from ZIP)
            files.forEach((fileObj, index) => {
                formData.append(`files[${index}]`, fileObj.file);
            });

            if (Object.keys(conversionOptions).length > 0) {
                formData.append('conversion_options', JSON.stringify(conversionOptions));
            }

            const response = await fetch('/api/upload-and-convert', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': this.getCsrfToken()
                },
                body: formData
            });

            if (response.ok) {
                return await response.json();
            }

            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.error || 'Upload failed');
        },

        /**
         * Check workflow execution status
         */
        async checkExecutionStatus(executionId) {
            const response = await fetch(`/api/workflow-execution/${executionId}/status`);
            if (response.ok) {
                return await response.json();
            }
            throw new Error('Failed to check status');
        },

        /**
         * Poll conversion status with callback
         */
        pollExecutionStatus(executionId, onUpdate, onComplete, onError) {
            const interval = setInterval(async () => {
                try {
                    const data = await this.checkExecutionStatus(executionId);

                    if (data.status === 'done') {
                        clearInterval(interval);
                        onComplete(data);
                    } else if (data.status === 'error') {
                        clearInterval(interval);
                        onError(data.error_message || 'Conversion failed');
                    } else {
                        onUpdate(data);
                    }
                } catch (error) {
                    console.error('Polling error:', error);
                    // Don't stop polling on temporary errors
                }
            }, 2000);

            // Stop polling after 5 minutes
            setTimeout(() => {
                clearInterval(interval);
                onError('Conversion timeout - please try again');
            }, 300000);

            return interval;
        }
    };

    /**
     * Preview Handler Module
     * Handles file preview functionality including PDFs and images
     */
    const PreviewHandler = {
        /**
         * Initialize PDF preview for a file
         */
        async loadPdfPreview(url) {
            // Lazy load PDF.js if not already loaded
            if (!window.pdfjsLib) {
                if (window.loadPdfJs) {
                    console.log('Loading PDF.js library...');
                    try {
                        await window.loadPdfJs();
                    } catch (error) {
                        console.error('Failed to load PDF.js:', error);
                        return null;
                    }
                } else {
                    console.warn('PDF.js loader not available');
                    return null;
                }
            }

            try {
                const loadingTask = window.pdfjsLib.getDocument(url);
                const doc = await loadingTask.promise;

                return {
                    doc: doc,
                    totalPages: doc.numPages,
                    currentPage: 1
                };
            } catch (error) {
                console.error('PDF preview failed:', error);
                return null;
            }
        },

        /**
         * Load preview from base64 content (for ZIP extracted files)
         */
        async loadBase64PdfPreview(base64Content) {
            try {
                // Convert base64 to blob
                const base64Response = await fetch(`data:application/pdf;base64,${base64Content}`);
                const blob = await base64Response.blob();

                // Create object URL from blob
                const fileUrl = URL.createObjectURL(blob);

                // loadPdfPreview will handle PDF.js loading
                return await this.loadPdfPreview(fileUrl);
            } catch (error) {
                console.error('Failed to load preview from ZIP:', error);
                return null;
            }
        },

        /**
         * Render a PDF page to canvas
         */
        async renderPdfPage(pdfDoc, pageNum, canvas) {
            if (!pdfDoc || !canvas) return;

            try {
                // Unwrap from Alpine reactivity if needed
                const rawDoc = Alpine?.raw ? Alpine.raw(pdfDoc) : pdfDoc;
                const page = await rawDoc.getPage(pageNum);
                const rawPage = Alpine?.raw ? Alpine.raw(page) : page;

                const ctx = canvas.getContext('2d');
                const viewport = rawPage.getViewport({ scale: 1.5 });
                canvas.width = viewport.width;
                canvas.height = viewport.height;

                await rawPage.render({
                    canvasContext: ctx,
                    viewport
                }).promise;

                return true;
            } catch (error) {
                console.error('PDF render failed:', error);
                return false;
            }
        },

        /**
         * Create image preview URL
         */
        createImagePreview(file) {
            try {
                return URL.createObjectURL(file);
            } catch (error) {
                console.error('Failed to create image preview:', error);
                return null;
            }
        },

        /**
         * Get MIME type from file extension
         */
        getMimeTypeFromExtension(extension) {
            const mimeTypes = {
                'jpg': 'image/jpeg',
                'jpeg': 'image/jpeg',
                'png': 'image/png',
                'webp': 'image/webp',
                'gif': 'image/gif',
                'tiff': 'image/tiff',
                'tif': 'image/tiff'
            };
            return mimeTypes[extension.toLowerCase()] || 'image/jpeg';
        },

        /**
         * Load appropriate preview based on file type
         */
        async loadPreview(file) {
            const result = {
                type: null,
                url: null,
                pdfData: null,
                error: null
            };

            // Handle files from ZIP with preview content
            if (file.isFromZip && file.previewContent) {
                if (file.extension === 'pdf') {
                    const pdfData = await this.loadBase64PdfPreview(file.previewContent);
                    if (pdfData) {
                        result.type = 'pdf';
                        result.pdfData = pdfData;
                    } else {
                        result.error = 'Failed to load PDF preview';
                    }
                } else if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(file.extension)) {
                    // Handle image preview from base64 content
                    try {
                        const mimeType = this.getMimeTypeFromExtension(file.extension);
                        result.type = 'image';
                        result.url = `data:${mimeType};base64,${file.previewContent}`;
                    } catch (error) {
                        console.error('Failed to create image preview from ZIP:', error);
                        result.error = 'Failed to load image preview';
                    }
                } else {
                    // Can't preview other file types from ZIP
                    result.error = 'Preview not available for this file type from ZIP';
                }
                return result;
            }

            // Can't preview files from ZIP without content
            if (file.isFromZip) {
                result.error = 'Preview not available - file too large or unsupported';
                return result;
            }

            // Handle regular files
            const ext = file.name.split('.').pop().toLowerCase();

            // Check if file.file exists
            if (!file.file) {
                console.error('File object is missing for:', file.name);
                result.error = 'File data not available';
                return result;
            }

            if (ext === 'pdf') {
                try {
                    const fileUrl = URL.createObjectURL(file.file);
                    const pdfData = await this.loadPdfPreview(fileUrl);

                    if (pdfData) {
                        result.type = 'pdf';
                        result.pdfData = pdfData;
                    } else {
                        result.error = 'Failed to load PDF preview';
                    }
                } catch (error) {
                    console.error('Error creating PDF preview:', error);
                    result.error = 'Failed to create PDF preview';
                }
            } else if (['jpg', 'jpeg', 'png', 'webp'].includes(ext)) {
                result.type = 'image';
                result.url = this.createImagePreview(file.file);
            } else {
                result.error = 'Preview not supported for this file type';
            }

            return result;
        },

        /**
         * Clean up preview resources
         */
        cleanup(previewData) {
            if (previewData?.url) {
                URL.revokeObjectURL(previewData.url);
            }
            // PDF.js cleanup happens automatically when document is garbage collected
        }
    };

    /**
     * Error Handler Module
     * Centralized error handling with user-friendly messages
     */
    const ErrorHandler = {
        /**
         * Error type mappings for user-friendly messages
         */
        errorTypes: {
            FILE_TOO_LARGE: 'File exceeds maximum size limit',
            INVALID_FILE_TYPE: 'File type not supported',
            NETWORK_ERROR: 'Network connection error',
            AUTHENTICATION_ERROR: 'Authentication required',
            INSUFFICIENT_CREDITS: 'Insufficient credits',
            CONVERSION_FAILED: 'Conversion failed',
            ZIP_EXTRACTION_FAILED: 'ZIP extraction failed',
            QUOTA_EXCEEDED: 'Upload quota exceeded',
            SERVER_ERROR: 'Server error occurred',
            TIMEOUT: 'Operation timed out'
        },

        /**
         * Parse error and return user-friendly message
         */
        parseError(error) {
            // Handle different error types
            if (error instanceof Error) {
                return this.parseErrorMessage(error.message);
            }

            if (typeof error === 'string') {
                return this.parseErrorMessage(error);
            }

            if (error?.response?.data?.message) {
                return this.parseErrorMessage(error.response.data.message);
            }

            if (error?.response?.data?.error) {
                return this.parseErrorMessage(error.response.data.error);
            }

            return 'An unexpected error occurred. Please try again.';
        },

        /**
         * Parse error message and return appropriate user message
         */
        parseErrorMessage(message) {
            const lowerMessage = message.toLowerCase();

            // Check for specific error patterns
            if (lowerMessage.includes('file') && lowerMessage.includes('large')) {
                return this.errorTypes.FILE_TOO_LARGE;
            }

            if (lowerMessage.includes('invalid') && lowerMessage.includes('type')) {
                return this.errorTypes.INVALID_FILE_TYPE;
            }

            if (lowerMessage.includes('network') || lowerMessage.includes('connection')) {
                return this.errorTypes.NETWORK_ERROR;
            }

            if (lowerMessage.includes('auth') || lowerMessage.includes('login')) {
                return this.errorTypes.AUTHENTICATION_ERROR;
            }

            if (lowerMessage.includes('credit')) {
                return this.errorTypes.INSUFFICIENT_CREDITS;
            }

            if (lowerMessage.includes('conversion') && lowerMessage.includes('fail')) {
                return this.errorTypes.CONVERSION_FAILED;
            }

            if (lowerMessage.includes('zip') && lowerMessage.includes('extract')) {
                return this.errorTypes.ZIP_EXTRACTION_FAILED;
            }

            if (lowerMessage.includes('quota') || lowerMessage.includes('limit')) {
                return this.errorTypes.QUOTA_EXCEEDED;
            }

            if (lowerMessage.includes('timeout')) {
                return this.errorTypes.TIMEOUT;
            }

            if (lowerMessage.includes('server') || lowerMessage.includes('500')) {
                return this.errorTypes.SERVER_ERROR;
            }

            // Return original message if no pattern matches
            return message;
        },

        /**
         * Get recovery suggestions based on error type
         */
        getRecoverySuggestions(error) {
            const message = this.parseError(error);
            const suggestions = [];

            if (message === this.errorTypes.FILE_TOO_LARGE) {
                suggestions.push('Try compressing your file before uploading');
                suggestions.push('Split large files into smaller parts');
            }

            if (message === this.errorTypes.INVALID_FILE_TYPE) {
                suggestions.push('Check the list of supported file types');
                suggestions.push('Convert your file to a supported format');
            }

            if (message === this.errorTypes.NETWORK_ERROR) {
                suggestions.push('Check your internet connection');
                suggestions.push('Try again in a few moments');
            }

            if (message === this.errorTypes.AUTHENTICATION_ERROR) {
                suggestions.push('Please log in to continue');
                suggestions.push('Create an account if you don\'t have one');
            }

            if (message === this.errorTypes.INSUFFICIENT_CREDITS) {
                suggestions.push('Purchase more credits');
                suggestions.push('Use a simpler workflow');
            }

            if (message === this.errorTypes.CONVERSION_FAILED) {
                suggestions.push('Check if your file is corrupted');
                suggestions.push('Try a different file format');
                suggestions.push('Contact support if the problem persists');
            }

            if (message === this.errorTypes.ZIP_EXTRACTION_FAILED) {
                suggestions.push('Ensure the ZIP file is not corrupted');
                suggestions.push('Try extracting files locally first');
            }

            if (message === this.errorTypes.TIMEOUT) {
                suggestions.push('Try with smaller files');
                suggestions.push('Check your internet connection speed');
            }

            return suggestions;
        },

        /**
         * Create error notification object
         */
        createNotification(error, type = 'error') {
            const message = this.parseError(error);
            const suggestions = this.getRecoverySuggestions(error);

            return {
                id: Date.now(),
                type: type,
                message: message,
                suggestions: suggestions,
                timestamp: new Date(),
                dismissed: false
            };
        },

        /**
         * Log error for debugging (only in development)
         */
        logError(error, context = {}) {
            // Could send to error tracking service in production
            console.error('Error:', error);
            console.log('Context:', context);
        },

        /**
         * Handle API response errors
         */
        handleApiError(response) {
            if (!response.ok) {
                switch (response.status) {
                    case 400:
                        throw new Error('Invalid request. Please check your input.');
                    case 401:
                        throw new Error(this.errorTypes.AUTHENTICATION_ERROR);
                    case 403:
                        throw new Error('Permission denied. You don\'t have access to this resource.');
                    case 404:
                        throw new Error('Resource not found.');
                    case 413:
                        throw new Error(this.errorTypes.FILE_TOO_LARGE);
                    case 422:
                        throw new Error('Validation failed. Please check your input.');
                    case 429:
                        throw new Error('Too many requests. Please wait before trying again.');
                    case 500:
                        throw new Error(this.errorTypes.SERVER_ERROR);
                    case 502:
                    case 503:
                    case 504:
                        throw new Error('Service temporarily unavailable. Please try again later.');
                    default:
                        throw new Error(`Request failed with status ${response.status}`);
                }
            }
        }
    };

    /**
     * Upload Flow V2 Alpine Component
     * Main component for handling file upload and conversion flow
     */
    function uploadFlowV2(pageSlug, pageConfig) {
        return {
            // Props
            pageSlug: pageSlug,
            pageConfig: pageConfig,

            // State
            currentStep: 1,
            uploadedFiles: [],
            selectedFileIndex: 0,
            isDragging: false,
            showExtensions: false,
            errorMessage: null,
            errorSuggestions: [],

            // User & Credits
            isGuestUser: true,
            userCredits: null,
            creditsUsed: 0,
            creditsInfo: null,
            creditsError: null,
            guestEmail: '',
            guestEmailCaptured: false,
            guestEmailError: null,

            // ZIP preview
            zipPreviews: [],
            zipPreviewLoading: false,
            zipPreviewError: null,

            // Workflow
            activeWorkflow: null,
            conversionOptions: {},

            // Preview
            previewUrl: null,
            previewLoading: false,
            currentPage: 1,
            totalPages: 1,
            pdfDoc: null,

            // Conversion
            isProcessing: false,
            conversionStatus: 'ready',
            workflowExecutionId: null,
            downloadUrl: null,
            resultFileSize: null,
            pollingInterval: null,

            // Keep track of original ZIP files for upload
            originalZipFiles: [],

            // Timeouts for throttling
            creditValidationTimeout: null,
            dropTimeout: null,

            // Computed properties
            get selectedFile() {
                return this.uploadedFiles[this.selectedFileIndex] || null;
            },

            get allowedFileTypes() {
                const groups = this.pageConfig.allowed_mime_groups || [];
                return UploadUtils.getMimeGroupExtensions(groups);
            },

            get acceptedFileExtensions() {
                return this.allowedFileTypes.map(type => '.' + type).join(',');
            },

            get convertButtonText() {
                if (this.pageConfig.merge_mode === true) {
                    return 'Merge to PDF';
                }
                return 'Convert to PDF';
            },

            get downloadButtonText() {
                const actionType = this.pageConfig.action_type || 'convert';
                return (actionType === 'merge' || this.uploadedFiles.length === 1) ? 'Download PDF' : 'Download ZIP';
            },

            get canConvert() {
                return this.uploadedFiles.length > 0 &&
                       (!this.isGuestUser || this.guestEmailCaptured) &&
                       !this.creditsError;
            },

            // Lifecycle
            async init() {
                await this.loadUserCredits();
                await this.loadUserWorkflows();
            },

            // User & Credits Methods
            async loadUserCredits() {
                const data = await UploadAPI.loadUserCredits();
                if (data) {
                    this.userCredits = data.credits;
                    this.isGuestUser = false;
                } else {
                    this.isGuestUser = true;
                }
            },

            async loadUserWorkflows() {
                const data = await UploadAPI.loadUserWorkflows(this.pageSlug);
                if (data?.workflows?.length > 0) {
                    this.activeWorkflow = data.workflows[0];
                }
            },

            async validateCredits() {
                if (this.isGuestUser || this.userCredits === null) {
                    this.creditsError = null;
                    this.creditsInfo = null;
                    return;
                }

                // Throttle credit validation to avoid rapid API calls
                if (this.creditValidationTimeout) {
                    clearTimeout(this.creditValidationTimeout);
                }

                this.creditValidationTimeout = setTimeout(async () => {
                    const fileCount = this.uploadedFiles.length; // All files count, no ZIP containers anymore
                    const data = await UploadAPI.validateCredits(
                        this.pageSlug,
                        fileCount,
                        this.activeWorkflow?.id
                    );

                    if (data) {
                        if (data.valid) {
                            this.creditsInfo = data.message;
                            this.creditsUsed = data.credits_needed || 0;
                            this.creditsError = null;
                        } else {
                            this.creditsError = data.message;
                            this.creditsInfo = null;
                        }
                    }
                }, 300); // 300ms delay to throttle requests
            },

            // File Handling Methods
            handleDrop(event) {
                this.isDragging = false;
                const files = Array.from(event.dataTransfer.files);

                // Debounce multiple rapid drops
                if (this.dropTimeout) {
                    clearTimeout(this.dropTimeout);
                }
                this.dropTimeout = setTimeout(() => {
                    this.handleFiles(files);
                }, 100);
            },

            handleFileSelect(event) {
                const files = Array.from(event.target.files);
                this.handleFiles(files);
            },

            async handleFiles(files) {
                await this.previewZipFiles(files);
                await this.processFiles(files);
            },

            async previewZipFiles(files) {
                const zipFiles = Array.from(files).filter(f => f.name.toLowerCase().endsWith('.zip'));
                if (zipFiles.length === 0) {
                    this.zipPreviews = [];
                    return;
                }

                this.zipPreviewLoading = true;
                this.zipPreviewError = null;

                try {
                    const data = await UploadAPI.previewZipFiles(files, this.pageSlug);
                    if (data.success) {
                        this.zipPreviews = data.extracted_files || [];
                        if (data.errors?.length > 0) {
                            this.zipPreviewError = data.errors.join(', ');
                        }
                    } else {
                        this.zipPreviewError = data.errors ? data.errors.join(', ') : 'Failed to preview ZIP';
                        this.zipPreviews = [];
                    }
                } catch (error) {
                    this.zipPreviewError = 'Failed to preview ZIP files. Please try again.';
                    this.zipPreviews = [];
                } finally {
                    this.zipPreviewLoading = false;
                }
            },

            async processFiles(files) {
                this.errorMessage = null;
                this.errorSuggestions = [];
                const validFiles = [];
                const invalidFiles = [];

                // Early validation: Check total size BEFORE processing
                const maxTotalSize = this.pageConfig.limits?.max_total_size || (50 * 1024 * 1024);
                let totalSize = 0;
                for (const file of files) {
                    totalSize += file.size;
                }

                if (totalSize > maxTotalSize) {
                    const errorMsg = `Total file size (${UploadUtils.formatFileSize(totalSize)}) exceeds maximum (${UploadUtils.formatFileSize(maxTotalSize)})`;
                    const notification = ErrorHandler.createNotification(errorMsg);
                    this.errorMessage = notification.message;
                    this.errorSuggestions = notification.suggestions;
                    return;
                }

                // Validate and separate files
                for (const file of files) {
                    const maxFileSize = this.pageConfig.limits?.max_file_size || (50 * 1024 * 1024);

                    // Client-side file size validation
                    if (file.size > maxFileSize) {
                        invalidFiles.push({
                            name: file.name,
                            reason: `File size (${UploadUtils.formatFileSize(file.size)}) exceeds limit (${UploadUtils.formatFileSize(maxFileSize)})`
                        });
                        continue;
                    }

                    // Client-side file type validation
                    const ext = UploadUtils.getFileExtension(file.name);
                    if (!this.allowedFileTypes.includes(ext)) {
                        invalidFiles.push({
                            name: file.name,
                            reason: `File type .${ext} is not supported`
                        });
                        continue;
                    }

                    if (ext === 'zip') {
                        this.processZipFile(file, validFiles);
                    } else {
                        validFiles.push(UploadUtils.createFileObject(file));
                    }
                }

                // Display validation errors if any
                if (invalidFiles.length > 0) {
                    const errorDetails = invalidFiles.map(f => `${f.name}: ${f.reason}`).join('\n');
                    const notification = ErrorHandler.createNotification(errorDetails);
                    this.errorMessage = notification.message;
                    this.errorSuggestions = notification.suggestions;
                }

                if (validFiles.length === 0) {
                    if (!this.errorMessage) {
                        this.errorMessage = 'No valid files selected. Please check the file requirements.';
                    }
                    return;
                }

                // Check max files limit
                const maxFiles = this.pageConfig.limits?.max_files || 20;
                if (validFiles.length > maxFiles) {
                    this.errorMessage = `Too many files. Maximum ${maxFiles} files allowed.`;
                    this.errorSuggestions = ['Select fewer files', 'Process files in batches'];
                    return;
                }

                this.uploadedFiles = validFiles;
                this.currentStep = 2;
                this.selectedFileIndex = 0;
                await this.loadPreview(this.selectedFile);
                await this.validateCredits();
            },

            processZipFile(zipFile, validFiles) {
                const zipPreview = this.zipPreviews.find(zp => zp.zip_name === zipFile.name);

                if (zipPreview) {
                    // Store the original ZIP file for later upload
                    this.originalZipFiles = this.originalZipFiles || [];
                    this.originalZipFiles.push(zipFile);

                    // Add each extracted file as a virtual entry
                    for (const extractedFile of zipPreview.files) {
                        validFiles.push({
                            id: Date.now() + Math.random() + Math.random(),
                            name: extractedFile.name,
                            size: extractedFile.size,
                            file: null, // Virtual file - actual ZIP will be uploaded
                            isFromZip: true,
                            parentZip: zipFile.name,
                            parentZipFile: zipFile,
                            previewContent: extractedFile.preview_content || null,
                            extension: extractedFile.extension
                        });
                    }
                } else {
                    // If no preview available (shouldn't happen), just add the ZIP as-is
                    validFiles.push(UploadUtils.createFileObject(zipFile));
                }
            },

            moveFileUp(index) {
                if (index > 0 && this.uploadedFiles.length > 1) {
                    const temp = this.uploadedFiles[index];
                    this.uploadedFiles[index] = this.uploadedFiles[index - 1];
                    this.uploadedFiles[index - 1] = temp;
                }
            },

            moveFileDown(index) {
                if (index < this.uploadedFiles.length - 1) {
                    const temp = this.uploadedFiles[index];
                    this.uploadedFiles[index] = this.uploadedFiles[index + 1];
                    this.uploadedFiles[index + 1] = temp;
                }
            },

            // Preview Methods
            canPreview(file) {
                return UploadUtils.canPreviewFile(file);
            },

            async loadPreview(file) {
                if (!file || !this.canPreview(file)) return;

                this.previewLoading = true;
                this.previewUrl = null;
                this.pdfDoc = null;

                const preview = await PreviewHandler.loadPreview(file);

                if (preview.type === 'pdf' && preview.pdfData) {
                    this.pdfDoc = window.Alpine?.raw ? Alpine.raw(preview.pdfData.doc) : preview.pdfData.doc;
                    this.totalPages = preview.pdfData.totalPages;
                    this.currentPage = preview.pdfData.currentPage;

                    // Wait for DOM update then render
                    await new Promise(resolve => setTimeout(resolve, 0));
                    requestAnimationFrame(() => {
                        this.renderPage(1);
                    });
                } else if (preview.type === 'image') {
                    this.previewUrl = preview.url;
                } else if (preview.error) {
                    console.warn('Preview error:', preview.error);
                }

                this.previewLoading = false;
            },

            async renderPage(pageNum) {
                if (!this.pdfDoc || !this.$refs.previewCanvas) return;
                await PreviewHandler.renderPdfPage(this.pdfDoc, pageNum, this.$refs.previewCanvas);
            },

            async prevPage() {
                if (this.currentPage > 1) {
                    this.currentPage--;
                    await this.renderPage(this.currentPage);
                }
            },

            async nextPage() {
                if (this.currentPage < this.totalPages) {
                    this.currentPage++;
                    await this.renderPage(this.currentPage);
                }
            },

            // File Management Methods
            selectFile(index) {
                this.selectedFileIndex = index;
                this.loadPreview(this.selectedFile);
            },

            async addMoreFiles(event) {
                const newFiles = Array.from(event.target.files);

                // Reset the state for all files
                this.originalZipFiles = [];
                this.zipPreviews = [];

                // Get all current non-virtual files
                const existingFiles = this.uploadedFiles
                    .filter(f => !f.isFromZip && f.file !== null)
                    .map(f => f.file);

                // Combine with new files
                const allFiles = [...existingFiles, ...newFiles];

                // Re-process all files together
                await this.handleFiles(allFiles);
            },

            removeFile(index) {
                const fileToRemove = this.uploadedFiles[index];

                if (fileToRemove.isFromZip) {
                    // Remove all files from the same ZIP
                    const parentZip = fileToRemove.parentZip;
                    this.uploadedFiles = this.uploadedFiles.filter(f => f.parentZip !== parentZip);

                    // Remove the ZIP from original files list
                    if (this.originalZipFiles) {
                        this.originalZipFiles = this.originalZipFiles.filter(f => f.name !== parentZip);
                    }

                    // Remove the ZIP preview
                    this.zipPreviews = this.zipPreviews.filter(zp => zp.zip_name !== parentZip);
                } else {
                    // Remove regular file
                    this.uploadedFiles.splice(index, 1);
                }

                // Adjust selection if needed
                if (this.uploadedFiles.length === 0) {
                    this.currentStep = 1;
                    this.errorMessage = null;
                } else if (this.selectedFileIndex >= this.uploadedFiles.length) {
                    this.selectedFileIndex = this.uploadedFiles.length - 1;
                }

                this.validateCredits();
            },

            // Conversion Methods
            async startConversion() {
                // Always move to Step 3 (for guests, show email capture form)
                this.currentStep = 3;
                this.errorMessage = null;

                // For guest users, wait for email capture before starting actual conversion
                if (this.isGuestUser && !this.guestEmailCaptured) {
                    this.conversionStatus = 'ready';
                    return;
                }

                // For authenticated users or guests with captured email, proceed with conversion
                this.isProcessing = true;
                this.conversionStatus = 'processing';

                try {
                    // Collect files to upload: original ZIPs or regular files
                    const filesToUpload = [];

                    // If we have original ZIP files, upload those
                    if (this.originalZipFiles && this.originalZipFiles.length > 0) {
                        for (const zipFile of this.originalZipFiles) {
                            filesToUpload.push({
                                file: zipFile,
                                name: zipFile.name,
                                size: zipFile.size
                            });
                        }
                    }

                    // Add any regular files (not from ZIP)
                    for (const fileObj of this.uploadedFiles) {
                        if (!fileObj.isFromZip && fileObj.file) {
                            filesToUpload.push(fileObj);
                        }
                    }

                    const data = await UploadAPI.uploadAndConvert(
                        this.pageSlug,
                        filesToUpload,
                        this.activeWorkflow?.id,
                        this.conversionOptions
                    );

                    this.workflowExecutionId = data.execution_id;
                    this.pollConversionStatus();
                } catch (error) {
                    this.conversionStatus = 'error';
                    const notification = ErrorHandler.createNotification(error.message || error);
                    this.errorMessage = notification.message;
                    this.errorSuggestions = notification.suggestions;
                    this.isProcessing = false;
                }
            },

            async handleGuestRegistration() {
                // Validate email
                if (!this.guestEmail || !this.guestEmail.includes('@')) {
                    this.guestEmailError = 'Please enter a valid email address';
                    return;
                }

                this.guestEmailError = null;
                this.guestEmailCaptured = true;

                // Now start the actual conversion
                await this.startConversion();
            },

            pollConversionStatus() {
                this.pollingInterval = UploadAPI.pollExecutionStatus(
                    this.workflowExecutionId,
                    (data) => {
                        // Update callback - could show progress here
                        console.log('Conversion progress:', data);
                    },
                    (data) => {
                        // Complete callback
                        this.conversionStatus = 'done';
                        this.downloadUrl = data.download_url;
                        this.resultFileSize = data.file_size;
                        this.isProcessing = false;

                        // Update credits if logged in
                        if (this.userCredits !== null && this.creditsUsed > 0) {
                            this.userCredits -= this.creditsUsed;
                        }
                    },
                    (error) => {
                        // Error callback
                        this.conversionStatus = 'error';
                        const notification = ErrorHandler.createNotification(error);
                        this.errorMessage = notification.message;
                        this.errorSuggestions = notification.suggestions;
                        this.isProcessing = false;
                    }
                );
            },

            retryConversion() {
                this.conversionStatus = 'processing';
                this.errorMessage = null;
                this.errorSuggestions = [];
                this.startConversion();
            },

            // Navigation Methods
            goBackToUpload() {
                this.resetFileInput();
                this.uploadedFiles = [];
                this.originalZipFiles = [];
                this.currentStep = 1;
                this.errorMessage = null;
                this.errorSuggestions = [];
                this.cleanupPreview();
            },

            resetUpload() {
                this.resetFileInput();
                this.uploadedFiles = [];
                this.originalZipFiles = [];
                this.zipPreviews = [];
                this.currentStep = 1;
                this.conversionStatus = 'ready';
                this.workflowExecutionId = null;
                this.downloadUrl = null;
                this.resultFileSize = null;
                this.creditsUsed = 0;
                this.creditsError = null;
                this.creditsInfo = null;
                this.errorMessage = null;
                this.errorSuggestions = [];
                this.cleanupPreview();

                if (this.pollingInterval) {
                    clearInterval(this.pollingInterval);
                    this.pollingInterval = null;
                }
            },

            // Workflow Methods
            removeWorkflow() {
                this.activeWorkflow = null;
                this.validateCredits();
            },

            editWorkflow() {
                if (this.activeWorkflow && !this.activeWorkflow.is_default) {
                    window.location.href = `/profile/workflows?edit=${this.activeWorkflow.id}`;
                }
            },

            // Helper Methods
            getStepDisplayName(stepType) {
                return UploadUtils.getStepDisplayName(stepType);
            },

            formatOptionKey(key) {
                return UploadUtils.formatOptionKey(key);
            },

            getOptionDescription(key) {
                return UploadUtils.getOptionDescription(key);
            },

            formatFileSize(bytes) {
                return UploadUtils.formatFileSize(bytes);
            },

            getOutputDescription() {
                if (!this.activeWorkflow) return '';

                const outputType = this.activeWorkflow.output_type || 'single';
                const outputs = {
                    'single': 'Single PDF file',
                    'zip': 'ZIP archive',
                    'individual': 'Individual files'
                };

                return `${outputs[outputType]} via download`;
            },

            resetFileInput() {
                if (this.$refs.fileInput) {
                    this.$refs.fileInput.value = '';
                }
                if (this.$refs.addMoreInput) {
                    this.$refs.addMoreInput.value = '';
                }
            },

            cleanupPreview() {
                if (this.previewUrl) {
                    URL.revokeObjectURL(this.previewUrl);
                    this.previewUrl = null;
                }
                this.pdfDoc = null;
                this.currentPage = 1;
                this.totalPages = 1;
            }
        };
    }

    // Export to window
    window.uploadFlowV2 = uploadFlowV2;

})(window);