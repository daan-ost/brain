/**
 * Upload Flow Utilities
 * Shared helper functions for file handling and formatting
 */

window.UploadUtils = {
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
            'email': ["eml", "msg"],
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
        return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }
};/**
 * Upload Flow API Service
 * Handles all API communication for the upload flow
 */

window.UploadAPI = {
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
    },

    /**
     * Analyze email files for attachment information and credit estimation
     */
    async analyzeEmails(files) {
        const formData = new FormData();

        // Only send email files
        files.forEach((fileObj, index) => {
            const ext = UploadUtils.getFileExtension(fileObj.name);
            if (ext === 'eml' || ext === 'msg') {
                formData.append(`files[${index}]`, fileObj.file);
            }
        });

        try {
            const response = await fetch('/api/analyze-emails', {
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
            throw new Error(errorData.error || 'Email analysis failed');
        } catch (error) {
            console.error('Email analysis error:', error);
            throw error;
        }
    }
};/**
 * Preview Handler Module
 * Handles file preview functionality including PDFs and images
 */

window.PreviewHandler = {
    /**
     * Initialize PDF preview for a file
     */
    async loadPdfPreview(url) {
        // Load PDF.js if not already loaded
        if (!window.pdfjsLib) {
            try {
                await window.loadPdfJs();
            } catch (error) {
                console.error('Failed to load PDF.js:', error);
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

        if (ext === 'pdf') {
            const fileUrl = URL.createObjectURL(file.file);
            const pdfData = await this.loadPdfPreview(fileUrl);

            if (pdfData) {
                result.type = 'pdf';
                result.pdfData = pdfData;
            } else {
                result.error = 'Failed to load PDF preview';
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
};/**
 * Error Handler Module
 * Centralized error handling with user-friendly messages
 */

window.ErrorHandler = {
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
        if (process.env.NODE_ENV === 'development') {
            console.group('🔴 Error Details');
            console.error('Error:', error);
            console.log('Context:', context);
            console.log('Stack:', error?.stack);
            console.groupEnd();
        }

        // Could send to error tracking service in production
        // e.g., Sentry, LogRocket, etc.
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
};/**
 * Upload Flow V2 Alpine Component
 * Main component for handling file upload and conversion flow
 * Uses global window.UploadUtils, window.UploadAPI, window.PreviewHandler
 */

window.uploadFlowV2 = function(pageSlug, pageConfig) {
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
        batchId: null,
        downloadUrl: null,
        resultFileSize: null,
        pollingInterval: null,

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
            // Skip validation for guest users
            if (this.isGuestUser || this.userCredits === null) {
                this.creditsError = null;
                this.creditsInfo = null;
                return;
            }

            // Special handling for email-to-pdf: use email analyzer
            if (this.pageSlug === 'email-to-pdf') {
                await this.validateEmailCredits();
                return;
            }

            // Default validation for other page types
            const fileCount = this.uploadedFiles.filter(f => !f.isZipContainer).length;
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
        },

        /**
         * Validate credits specifically for email-to-pdf conversions
         * Uses EmailAnalyzerService to calculate accurate credit costs
         */
        async validateEmailCredits() {
            try {
                // Filter out email files (excluding ZIP containers)
                const emailFiles = this.uploadedFiles.filter(f =>
                    !f.isZipContainer &&
                    (f.name.toLowerCase().endsWith('.eml') || f.name.toLowerCase().endsWith('.msg'))
                );

                if (emailFiles.length === 0) {
                    this.creditsInfo = 'No email files to analyze';
                    this.creditsUsed = 0;
                    this.creditsError = null;
                    return;
                }

                // Call email analyzer
                const analysis = await UploadAPI.analyzeEmails(emailFiles);

                // Get mail_part setting (default to 'L' - Both)
                const mailPart = this.conversionOptions.mail_part || 'L';

                // Calculate credits based on mail_part selection
                let totalCredits = 0;
                let breakdown = [];

                if (mailPart === 'L') {
                    // Both: email bodies + convertible attachments + merge
                    totalCredits =
                        analysis.credit_breakdown.email_bodies +
                        analysis.credit_breakdown.office_attachments +
                        analysis.credit_breakdown.merge_operation;

                    breakdown.push(`${analysis.credit_breakdown.email_bodies} email${analysis.credit_breakdown.email_bodies !== 1 ? 's' : ''}`);
                    if (analysis.credit_breakdown.office_attachments > 0) {
                        breakdown.push(`${analysis.credit_breakdown.office_attachments} attachment${analysis.credit_breakdown.office_attachments !== 1 ? 's' : ''}`);
                    }
                    if (analysis.credit_breakdown.merge_operation > 0) {
                        breakdown.push(`${analysis.credit_breakdown.merge_operation} merge`);
                    }

                } else if (mailPart === 'B') {
                    // Body only: just email bodies
                    totalCredits = analysis.credit_breakdown.email_bodies;
                    breakdown.push(`${analysis.credit_breakdown.email_bodies} email${analysis.credit_breakdown.email_bodies !== 1 ? 's' : ''}`);

                } else if (mailPart === 'A') {
                    // Attachments only: convertible attachments + merge
                    totalCredits =
                        analysis.credit_breakdown.office_attachments +
                        analysis.credit_breakdown.merge_operation;

                    if (analysis.credit_breakdown.office_attachments > 0) {
                        breakdown.push(`${analysis.credit_breakdown.office_attachments} attachment${analysis.credit_breakdown.office_attachments !== 1 ? 's' : ''}`);
                    }
                    if (analysis.credit_breakdown.merge_operation > 0) {
                        breakdown.push(`${analysis.credit_breakdown.merge_operation} merge`);
                    }

                    if (totalCredits === 0) {
                        breakdown = ['no convertible attachments found'];
                    }
                }

                // Set credits info
                this.creditsUsed = totalCredits;

                if (totalCredits === 0) {
                    this.creditsInfo = 'This workflow is free to run.';
                } else {
                    const breakdownText = breakdown.length > 0 ? ` (${breakdown.join(' + ')})` : '';
                    this.creditsInfo = `This will consume ${totalCredits} credit${totalCredits !== 1 ? 's' : ''}${breakdownText}`;
                }

                // Add warnings for unsupported/oversized attachments
                if (analysis.unsupported_attachments?.length > 0) {
                    console.warn(`${analysis.unsupported_attachments.length} unsupported attachments will be excluded`);
                }
                if (analysis.oversized_attachments?.length > 0) {
                    console.warn(`${analysis.oversized_attachments.length} oversized attachments will be excluded`);
                }

                this.creditsError = null;

            } catch (error) {
                console.error('Email credit validation failed:', error);

                // Fallback to simple file count if analysis fails
                const fileCount = this.uploadedFiles.filter(f => !f.isZipContainer).length;
                this.creditsInfo = `Estimated ${fileCount} credit${fileCount !== 1 ? 's' : ''} (unable to analyze emails)`;
                this.creditsUsed = fileCount;
                this.creditsError = null;
            }
        },

        // File Handling Methods
        handleDrop(event) {
            this.isDragging = false;
            const files = Array.from(event.dataTransfer.files);
            this.handleFiles(files);
        },

        handleFileSelect(event) {
            const files = Array.from(event.target.files);
            this.handleFiles(files);
        },

        async handleFiles(files) {
            // First preview ZIP files if any
            await this.previewZipFiles(files);
            // Then process all files (including ZIP contents)
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
            const validFiles = [];

            // Separate and validate files
            for (const file of files) {
                if (this.validateFile(file)) {
                    if (file.name.toLowerCase().endsWith('.zip')) {
                        await this.processZipFile(file, validFiles);
                    } else {
                        validFiles.push(UploadUtils.createFileObject(file));
                    }
                }
            }

            if (validFiles.length === 0) {
                this.errorMessage = 'No valid files selected';
                return;
            }

            this.uploadedFiles = validFiles;
            this.currentStep = 2;
            await this.loadPreview(this.selectedFile);
            await this.validateCredits();
        },

        processZipFile(zipFile, validFiles) {
            // Find the corresponding ZIP preview
            const zipPreview = this.zipPreviews.find(zp => zp.zip_name === zipFile.name);

            if (zipPreview) {
                // Add the ZIP container
                const zipEntry = {
                    id: Date.now() + Math.random(),
                    name: zipFile.name,
                    size: zipFile.size || zipPreview.zip_size,
                    file: zipFile,
                    isZipContainer: true,
                    isFromZip: false,
                    extractedCount: zipPreview.total_files
                };
                validFiles.push(zipEntry);

                // Add extracted files as virtual entries
                for (const extractedFile of zipPreview.files) {
                    validFiles.push({
                        id: Date.now() + Math.random() + Math.random(),
                        name: extractedFile.name,
                        size: extractedFile.size,
                        file: null, // Virtual file
                        isFromZip: true,
                        parentZip: zipFile.name,
                        parentZipId: zipEntry.id,
                        previewContent: extractedFile.preview_content || null,
                        extension: extractedFile.extension
                    });
                }
            } else {
                // No preview available, add as regular file
                validFiles.push(UploadUtils.createFileObject(zipFile));
            }
        },

        validateFile(file) {
            return UploadUtils.validateFile(file, this.allowedFileTypes);
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
                this.pdfDoc = Alpine.raw(preview.pdfData.doc);
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
            const existingFiles = this.uploadedFiles.filter(f => f.file !== null).map(f => f.file);
            const allFiles = [...existingFiles, ...newFiles];

            await this.handleFiles(allFiles);
        },

        removeFile(index) {
            const fileToRemove = this.uploadedFiles[index];

            if (fileToRemove.isZipContainer) {
                // Remove ZIP and all its extracted files
                this.uploadedFiles = this.uploadedFiles.filter(f =>
                    f.id !== fileToRemove.id && f.parentZipId !== fileToRemove.id
                );
                this.zipPreviews = this.zipPreviews.filter(zp => zp.zip_name !== fileToRemove.name);
            } else if (!fileToRemove.isFromZip) {
                // Remove regular file
                this.uploadedFiles.splice(index, 1);
            }
            // Can't remove individual files from ZIP

            if (this.uploadedFiles.filter(f => !f.isZipContainer).length === 0) {
                this.currentStep = 1;
            } else if (this.selectedFileIndex >= this.uploadedFiles.length) {
                this.selectedFileIndex = this.uploadedFiles.length - 1;
            }

            this.validateCredits();
        },

        // Conversion Methods
        async startConversion() {
            if (!this.canConvert) return;

            this.currentStep = 3;
            this.isProcessing = true;
            this.conversionStatus = 'processing';

            try {
                // Get only real files (not virtual files from ZIP)
                const realFiles = this.uploadedFiles.filter(f => f.file !== null && !f.isFromZip);

                // Add coverpage settings to conversion options
                const optionsWithCoverpage = {
                    ...this.conversionOptions,
                    coverPageEnabled: this.coverPageEnabled || false,
                    coverTemplateId: this.coverTemplateId || null
                };

                const data = await UploadAPI.uploadAndConvert(
                    this.pageSlug,
                    realFiles,
                    this.activeWorkflow?.id,
                    optionsWithCoverpage
                );

                this.workflowExecutionId = data.execution_id;
                this.pollConversionStatus();
            } catch (error) {
                this.conversionStatus = 'error';
                this.errorMessage = error.message;
            } finally {
                this.isProcessing = false;
            }
        },

        pollConversionStatus() {
            this.pollingInterval = UploadAPI.pollExecutionStatus(
                this.workflowExecutionId,
                (data) => {
                    // Update callback - could show progress here
                },
                (data) => {
                    // Complete callback
                    this.conversionStatus = 'done';
                    this.downloadUrl = data.download_url;
                    this.resultFileSize = data.file_size;

                    if (this.userCredits !== null) {
                        this.userCredits -= this.creditsUsed;
                    }
                },
                (error) => {
                    // Error callback
                    this.conversionStatus = 'error';
                    this.errorMessage = error;
                }
            );
        },

        retryConversion() {
            this.conversionStatus = 'processing';
            this.startConversion();
        },

        // Navigation Methods
        goBackToUpload() {
            this.resetFileInput();
            this.uploadedFiles = [];
            this.currentStep = 1;
            this.errorMessage = null;
            this.cleanupPreview();
        },

        resetUpload() {
            this.resetFileInput();
            this.uploadedFiles = [];
            this.currentStep = 1;
            this.conversionStatus = 'ready';
            this.workflowExecutionId = null;
            this.downloadUrl = null;
            this.resultFileSize = null;
            this.creditsUsed = 0;
            this.errorMessage = null;
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
        }
    };
}