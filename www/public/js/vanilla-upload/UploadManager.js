/**
 * UploadManager - Vanilla JavaScript Upload Component (Full Feature Parity)
 *
 * @version 1.1.1 - 2025-11-20 - Optimized for options column (20% / 25% / 55%)
 *
 * API-First Design - Matches Alpine.js component exactly
 *
 * Features:
 * - Step 1: Upload (drag & drop + click)
 * - Step 2: Configure (3-column: Files | Preview | Configuration)
 * - Step 3: Processing/Download (guest email, processing, success, error)
 * - File preview (images + PDFs with PDF.js)
 * - Add more files
 * - Remove files
 * - File selection for preview
 * - Conversion options
 * - Cover page integration
 *
 * API Endpoints:
 * - GET  /api/user/limits?page={slug}
 * - POST /api/validate-credits
 * - POST /api/upload-and-convert
 */
export class UploadManager {
    constructor(config) {
        this.config = {
            pageSlug: config.pageSlug || 'images-to-pdf',
            container: config.container,
            allowedExtensions: config.allowedExtensions || [],
            maxFiles: config.maxFiles || 20,
            maxTotalSize: config.maxTotalSize || 200 * 1024 * 1024,
            maxPages: config.maxPages || 5000,
            outputFormat: config.outputFormat || 'pdf',
            actionType: config.actionType || 'convert',
            locale: config.locale || 'en',
            translations: config.translations || {},
            onStateChange: config.onStateChange || (() => {}),
            onError: config.onError || (() => {}),
            onSuccess: config.onSuccess || (() => {}),
            // Feedback config
            feedbackEnabled: config.feedbackEnabled || false,
            feedbackUrl: config.feedbackUrl || '/feedback',
            csrfToken: config.csrfToken || '',
        };

        this.state = {
            currentStep: 1, // 1=upload, 2=configure, 3=processing
            uploadedFiles: [], // All files (with metadata)
            selectedFileIndex: 0, // Currently selected file for preview
            showExtensions: false,

            // File Limits
            fileLimit: {
                current: 0,
                limit: config.maxFiles || 50,
                valid: true,
                excess: 0
            },
            // Size Limits
            sizeLimit: {
                current: 0,
                limit: config.maxTotalSize || (100 * 1024 * 1024), // Default 100MB
                valid: true,
                excess: 0
            },
            // Credits
            creditsLimit: {
                available: null, // Will be fetched from API
                needed: 0,
                valid: true,
                deficit: 0
            },
            // Per-file Size Limit
            fileSizeLimit: {
                limit: config.maxFileSize || (100 * 1024 * 1024), // Default 100MB per file
                valid: true,
                oversizedFiles: [] // Array of {name, size, limit} for files that are too large
            },
            // Merge Minimum Files Limit (for merge action types)
            mergeMinimumFilesLimit: {
                minimum: 2,
                current: 0,
                valid: true,
                actionType: config.actionType || 'convert'
            },
            // Minimum Files Limit (must have at least 1 file)
            minimumFilesLimit: {
                minimum: 1,
                current: 0,
                valid: true
            },
            isDragging: false,
            isProcessing: false,
            errorMessage: null,
            errorSuggestions: [],

            // Preview state
            previewLoading: false,
            previewUrl: null,
            pdfDoc: null,
            currentPage: 1,
            totalPages: 0,

            // Conversion state
            conversionOptions: {},
            conversionOptionsConfig: null, // Loaded from API
            coverPageEnabled: false,
            coverTemplateId: null,
            coverTemplateName: '',
            activeWorkflow: null, // Workflow object if user has one

            // Processing state
            conversionStatus: 'ready', // ready, processing, done, error, awaiting_confirmation
            executionId: null,
            downloadUrl: null,
            batchId: null,
            preUploadedBatchId: null, // For pre-upload persistence
            creditsUsed: 0,
            creditsInfo: null,
            creditsError: null,

            // Guest state
            isGuestUser: !window.auth?.check,
            guestEmail: '',
            guestEmailError: '',
            guestEmailCaptured: false,

            // Email Status Check (for returning guests)
            emailStatusCheck: {
                checking: false,
                savedEmail: null,
                status: null, // 'not_found', 'pending', 'verified'
                message: null,
                canResend: false,
                lastSentAt: null,
                requiresLogin: false, // true if verified but user must log in
            },

            // Results
            resultFileSize: null,
            downloadButtonText: 'Download PDF',

            // Feedback widget state
            feedbackThumb: null, // 'up' or 'down'
            feedbackContent: '',
            feedbackSubmitting: false,
            feedbackSubmitted: false,
            feedbackError: null,

            // ZIP extraction
            zipPreviews: [], // Array of {zip_name, total_files, files: [...]}

            // Email analysis (for email-to-pdf)
            emailAnalysis: null, // Analysis result from /api/analyze-emails
            emailAttachmentsCount: 0, // Total convertible attachments count
        };

        this.init();
    }

    /**
     * Initialize component
     */
    async init() {
        // Initialize credits from window.auth if available
        if (window.auth?.check && window.auth?.user?.credits !== undefined) {
            this.state.creditsLimit.available = window.auth.user.credits;
        }

        // Check for ?batch= URL parameter (Next Step functionality - highest priority)
        const urlParams = new URLSearchParams(window.location.search);
        const urlBatchId = urlParams.get('batch');
        const isNextStep = urlParams.get('next_step') === 'true';

        // If loading from batch URL parameter
        if (urlBatchId) {
            console.log('[UploadManager] Found batch parameter:', urlBatchId, 'next_step:', isNextStep);

            // Fetch all required data first
            await Promise.all([
                this.fetchLimits(),
                this.fetchWorkflows(),
                this.fetchConversionOptions()
            ]);

            // Load the batch files - use result file for next_step, input files otherwise
            const loaded = isNextStep
                ? await this.loadBatchResultFile(urlBatchId)
                : await this.loadBatchFiles(urlBatchId);

            if (loaded) {
                // Clean URL after loading
                window.history.replaceState({}, '', window.location.pathname);
                // Save as pending upload for cross-tab/navigation scenarios
                this.savePendingUpload();
            }
            // If batch was deleted/expired, just continue to step 1 (no error shown)

            this.render();
            this.setupLivewireListeners();
            this.loadCoverTemplateFromSession();
            return;
        }

        // Check for pending upload in localStorage (cross-tab/navigation scenario)
        const pendingUpload = this.checkPendingUpload();
        if (pendingUpload && pendingUpload.batch_id) {
            console.log('[UploadManager] Found pending upload:', pendingUpload);

            // Fetch all required data first
            await Promise.all([
                this.fetchLimits(),
                this.fetchWorkflows(),
                this.fetchConversionOptions()
            ]);

            // Try to load the batch files
            const loaded = await this.loadBatchFiles(pendingUpload.batch_id);
            if (loaded) {
                console.log('[UploadManager] Resumed pending upload from localStorage');
            } else {
                // Batch was deleted by cronjob or user - clear localStorage and go to step 1
                console.log('[UploadManager] Pending batch no longer exists, clearing');
                this.clearPendingUpload();
            }

            this.render();
            this.setupLivewireListeners();
            this.loadCoverTemplateFromSession();
            return;
        }

        // Normal flow: render first, then fetch data
        this.render();
        await this.fetchLimits();
        await this.fetchWorkflows();
        await this.fetchConversionOptions();
        this.setupLivewireListeners();
        this.loadCoverTemplateFromSession();
    }

    /**
     * Load files from an existing batch (for Next Step functionality)
     * @param {string} batchId - The batch ID to load files from
     * @returns {Promise<boolean>} - Whether files were successfully loaded
     */
    async loadBatchFiles(batchId) {
        try {
            console.log('[UploadManager] Loading files from batch:', batchId);

            // Fetch file list from API
            const response = await fetch(`/api/batch/${batchId}/files`, {
                credentials: 'same-origin'
            });

            if (!response.ok) {
                console.error('[UploadManager] Failed to fetch batch files:', response.status);
                return false;
            }

            const data = await response.json();

            if (!data.files || data.files.length === 0) {
                console.warn('[UploadManager] No files found in batch');
                return false;
            }

            console.log('[UploadManager] Found', data.files.length, 'files in batch');

            // Download each file and convert to File object
            const uploadedFiles = [];
            for (const fileInfo of data.files) {
                try {
                    // Fetch the actual file content
                    const fileUrl = `/api/batch/${batchId}/file/${encodeURIComponent(fileInfo.filename)}`;
                    const fileResponse = await fetch(fileUrl, {
                        credentials: 'same-origin'
                    });

                    if (!fileResponse.ok) {
                        console.warn('[UploadManager] Failed to fetch file:', fileInfo.name);
                        continue;
                    }

                    // Convert to Blob and then to File
                    const blob = await fileResponse.blob();
                    const file = new File([blob], fileInfo.name, {
                        type: fileInfo.mime_type || blob.type
                    });

                    // Create file entry matching the expected format
                    uploadedFiles.push({
                        id: fileInfo.id || crypto.randomUUID(),
                        name: fileInfo.name,
                        size: fileInfo.size || file.size,
                        type: fileInfo.mime_type || file.type,
                        file: file, // Real File object for upload
                        url: URL.createObjectURL(blob), // For preview
                        previewUrl: URL.createObjectURL(blob),
                        isZipContainer: false,
                        isServerFile: true, // Mark as loaded from server
                        sourceBatchId: batchId
                    });

                    console.log('[UploadManager] Loaded file:', fileInfo.name);
                } catch (fileError) {
                    console.error('[UploadManager] Error loading file:', fileInfo.name, fileError);
                }
            }

            if (uploadedFiles.length === 0) {
                console.warn('[UploadManager] No files could be loaded from batch');
                return false;
            }

            // Update state with loaded files
            this.state.uploadedFiles = uploadedFiles;
            this.state.batchId = batchId;
            this.state.preUploadedBatchId = batchId; // Use this batch for conversion
            this.state.currentStep = 2;
            this.state.selectedFileIndex = 0;

            // Update limits
            this.updateFileLimit();
            this.updateSizeLimit();
            this.updateMergeMinimumFilesLimit();
            this.updateMinimumFilesLimit();

            // Load preview for first file
            if (uploadedFiles.length > 0) {
                await this.loadPreview(0);
            }

            console.log('[UploadManager] Successfully loaded', uploadedFiles.length, 'files from batch');
            return true;

        } catch (error) {
            console.error('[UploadManager] Error loading batch files:', error);
            return false;
        }
    }

    /**
     * Load result file from a completed batch (for Next Step functionality)
     * Loads the converted output file (e.g., PDF) as input for the next conversion
     * @param {string} batchId - The batch ID to load result from
     * @returns {Promise<boolean>} - Whether file was successfully loaded
     */
    async loadBatchResultFile(batchId) {
        try {
            console.log('[UploadManager] Loading result file from batch:', batchId);

            // Fetch result file info from API
            const response = await fetch(`/api/batch/${batchId}/result`, {
                credentials: 'same-origin'
            });

            if (!response.ok) {
                console.error('[UploadManager] Failed to fetch batch result:', response.status);
                return false;
            }

            const data = await response.json();

            if (!data.file) {
                console.warn('[UploadManager] No result file found in batch');
                return false;
            }

            console.log('[UploadManager] Found result file:', data.file.name);

            // Download the actual file content
            const fileUrl = `/api/batch/${batchId}/result/download`;
            const fileResponse = await fetch(fileUrl, {
                credentials: 'same-origin'
            });

            if (!fileResponse.ok) {
                console.warn('[UploadManager] Failed to download result file');
                return false;
            }

            // Log response headers for debugging
            const contentLength = fileResponse.headers.get('Content-Length');
            const contentType = fileResponse.headers.get('Content-Type');
            console.log('[UploadManager] Result file headers - Content-Length:', contentLength, 'Content-Type:', contentType);

            // Convert to Blob and then to File
            const blob = await fileResponse.blob();
            console.log('[UploadManager] Blob size:', blob.size, 'Expected:', data.file.size, 'Blob type:', blob.type);

            // Check if we got HTML instead of PDF (error page)
            const firstBytes = await blob.slice(0, 100).text();
            console.log('[UploadManager] First 100 bytes of file:', firstBytes.substring(0, 100));

            // Valid PDF should start with %PDF-
            if (!firstBytes.startsWith('%PDF-')) {
                console.error('[UploadManager] ERROR: File does not start with %PDF- header! Got:', firstBytes.substring(0, 20));
                console.error('[UploadManager] This might be an HTML error page or corrupted file');
            }

            // Verify blob size matches expected
            if (contentLength && blob.size !== parseInt(contentLength)) {
                console.warn('[UploadManager] Blob size mismatch! Got:', blob.size, 'Expected:', contentLength);
            }

            const file = new File([blob], data.file.name, {
                type: data.file.mime_type || blob.type
            });
            console.log('[UploadManager] File created, size:', file.size, 'type:', file.type);

            // Create file entry matching the expected format
            const uploadedFile = {
                id: data.file.id || crypto.randomUUID(),
                name: data.file.name,
                size: data.file.size || file.size,
                type: data.file.mime_type || file.type,
                file: file, // Real File object for upload
                url: URL.createObjectURL(blob), // For preview
                previewUrl: URL.createObjectURL(blob),
                isZipContainer: false,
                isServerFile: true, // Mark as loaded from server
                sourceBatchId: batchId,
                isNextStepFile: true // Mark as coming from next step
            };

            console.log('[UploadManager] Loaded result file:', data.file.name);

            // Update state with loaded file
            // Note: Don't set preUploadedBatchId - this is a new conversion
            this.state.uploadedFiles = [uploadedFile];
            this.state.currentStep = 2;
            this.state.selectedFileIndex = 0;

            // Update limits
            this.updateFileLimit();
            this.updateSizeLimit();
            this.updateMergeMinimumFilesLimit();
            this.updateMinimumFilesLimit();

            // Load preview for the file
            await this.loadPreview(0);

            console.log('[UploadManager] Successfully loaded result file from batch');
            return true;

        } catch (error) {
            console.error('[UploadManager] Error loading batch result file:', error);
            return false;
        }
    }

    /**
     * Save pending upload to localStorage
     * Called after successful upload or guest email submission
     */
    savePendingUpload() {
        if (!this.state.batchId || this.state.uploadedFiles.length === 0) {
            return;
        }

        const data = {
            batch_id: this.state.batchId,
            page_slug: this.config.pageSlug,
            files_count: this.state.uploadedFiles.filter(f => !f.isZipContainer).length,
            created_at: Date.now(),
            files: this.state.uploadedFiles
                .filter(f => !f.isZipContainer)
                .slice(0, 5) // Only store first 5 for display
                .map(f => ({
                    name: f.name,
                    size: f.size
                }))
        };

        localStorage.setItem('app_pending_upload', JSON.stringify(data));
        console.log('[UploadManager] Saved pending upload:', data);
    }

    /**
     * Check for pending upload in localStorage
     * @returns {Object|null} Pending upload data or null
     */
    checkPendingUpload() {
        const stored = localStorage.getItem('app_pending_upload');
        if (!stored) return null;

        try {
            const pending = JSON.parse(stored);

            // Check expiry (24 hours)
            const maxAge = 24 * 60 * 60 * 1000;
            if (Date.now() - pending.created_at > maxAge) {
                console.log('[UploadManager] Pending upload expired, clearing');
                this.clearPendingUpload();
                return null;
            }

            // Check if page_slug matches current page
            if (pending.page_slug && pending.page_slug !== this.config.pageSlug) {
                console.log('[UploadManager] Pending upload is for different page:', pending.page_slug, '!= current:', this.config.pageSlug);
                return null; // Don't clear - it's for another page
            }

            return pending;
        } catch (e) {
            console.error('[UploadManager] Error parsing pending upload:', e);
            this.clearPendingUpload();
            return null;
        }
    }

    /**
     * Clear pending upload from localStorage
     */
    clearPendingUpload() {
        localStorage.removeItem('app_pending_upload');
        console.log('[UploadManager] Cleared pending upload');
    }

    /**
     * Set up Livewire event listeners for cover page modal
     */
    setupLivewireListeners() {
        // Wait for Livewire to be available
        const setupListeners = () => {
            if (typeof Livewire === 'undefined') {
                console.log('[UploadManager] Livewire not available, cover page modal will not function');
                return;
            }

            // Helper to extract event data from Livewire 3 events
            // Livewire 3 can pass data in different formats:
            // - Array: [{templateId: value, templateName: value}]
            // - Object with detail: event.detail.templateId
            // - Direct object: event.templateId
            const extractEventData = (event) => {
                // If it's an array, get first element
                if (Array.isArray(event) && event.length > 0) {
                    return event[0];
                }
                // If it has a detail property (browser event style)
                if (event && event.detail && typeof event.detail === 'object') {
                    return event.detail;
                }
                // Otherwise return as-is (direct object)
                return event || {};
            };

            // Listen for template-loaded event (existing template loaded on page load)
            Livewire.on('template-loaded', (event) => {
                console.log('[UploadManager] Cover template loaded (raw):', event);
                const data = extractEventData(event);
                console.log('[UploadManager] Cover template loaded (extracted):', data);

                // Update window.coverTemplateData for consistency
                if (window.coverTemplateData) {
                    window.coverTemplateData.templateId = data.templateId;
                    window.coverTemplateData.templateName = data.templateName;
                }

                // Update internal state
                this.state.coverTemplateId = data.templateId;
                this.state.coverTemplateName = data.templateName;
                this.state.coverPageEnabled = false; // Don't auto-enable
                this.render();
            });

            // Listen for template-saved event (template saved in modal)
            Livewire.on('template-saved', (event) => {
                console.log('[UploadManager] Cover template saved (raw):', event);
                const data = extractEventData(event);
                console.log('[UploadManager] Cover template saved (extracted):', data);

                // BUGFIX: Preserve currentStep to prevent regression to step 1
                // When user creates a new template while in step 2, we must stay in step 2
                const currentStep = this.state.currentStep;
                const hasUploadedFiles = this.state.uploadedFiles.length > 0;

                this.state.coverTemplateId = data.templateId;
                this.state.coverTemplateName = data.templateName;
                this.state.coverPageEnabled = true; // Auto-enable after save

                // If user is in step 2 with files, preserve the step after render
                if (currentStep === 2 && hasUploadedFiles) {
                    console.log('[UploadManager] Template saved while in step 2 - preserving step');
                    this.state.currentStep = 2; // Force stay in step 2
                }

                this.render();
            });
        };

        // Try immediately if Livewire is already loaded
        if (typeof Livewire !== 'undefined') {
            setupListeners();
            // Re-check template data after listeners are set up
            this.recheckTemplateData();
        } else {
            // Otherwise wait for livewire:init event
            document.addEventListener('livewire:init', () => {
                setupListeners();
                // Re-check template data after listeners are set up
                this.recheckTemplateData();
            });
        }
    }

    /**
     * Recheck template data after Livewire is initialized
     * (handles race condition where event fired before listeners attached)
     */
    recheckTemplateData() {
        // Give Livewire more time to fully initialize and dispatch its mount events
        setTimeout(() => {
            if (window.coverTemplateData && window.coverTemplateData.templateId) {
                const dataChanged =
                    this.state.coverTemplateId !== window.coverTemplateData.templateId ||
                    this.state.coverTemplateName !== (window.coverTemplateData.templateName || '');

                if (dataChanged) {
                    console.log('[UploadManager] Rechecking template data after Livewire init - data changed, updating:', window.coverTemplateData);
                    this.state.coverTemplateId = window.coverTemplateData.templateId;
                    this.state.coverTemplateName = window.coverTemplateData.templateName || '';
                    this.state.coverPageEnabled = window.coverTemplateData.enabled || false;
                    this.render();
                } else {
                    console.log('[UploadManager] Rechecking template data after Livewire init - no changes detected');
                }
            }
        }, 1500); // Increased from 500ms to 1500ms for more reliable initialization
    }

    /**
     * Load cover template from session (for page refresh persistence)
     */
    loadCoverTemplateFromSession() {
        // Check if window.coverTemplateData exists (set by Blade template)
        if (window.coverTemplateData) {
            this.state.coverTemplateId = window.coverTemplateData.templateId || null;
            this.state.coverTemplateName = window.coverTemplateData.templateName || '';
            this.state.coverPageEnabled = window.coverTemplateData.enabled || false;
        }
    }

    /**
     * Fetch upload limits from API
     */
    async fetchLimits() {
        try {
            const response = await fetch(`/api/user/limits?page=${this.config.pageSlug}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) throw new Error('Failed to fetch limits');

            const data = await response.json();
            this.config.maxFiles = data.max_files;
            this.config.maxTotalSize = data.max_total_size;
            this.config.maxPages = data.max_pages;
            this.config.maxFileSize = data.max_file_size;

            // Update limits with the fetched values
            this.state.fileLimit.limit = data.max_files;
            this.state.sizeLimit.limit = data.max_total_size;
            this.state.fileSizeLimit.limit = data.max_file_size;

            this.updateState({ limits: data });
        } catch (error) {
            console.error('Failed to fetch limits:', error);
        }
    }

    /**
     * Fetch workflows for current page
     */
    async fetchWorkflows() {
        try {
            const response = await fetch(`/api/user/workflows?page=${this.config.pageSlug}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) throw new Error('Failed to fetch workflows');

            const data = await response.json();

            // If we have workflows, set the first one as active
            if (data.workflows && data.workflows.length > 0) {
                this.updateState({
                    activeWorkflow: data.workflows[0]
                });
                console.log('[UploadManager] Workflow loaded:', data.workflows[0]);
            } else {
                console.log('[UploadManager] No workflows available for this page');
            }
        } catch (error) {
            console.error('Failed to fetch workflows:', error);
        }
    }

    /**
     * Fetch conversion options configuration from API
     */
    async fetchConversionOptions() {
        try {
            const response = await fetch(`/api/conversion-options?page=${this.config.pageSlug}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) throw new Error('Failed to fetch conversion options');

            const data = await response.json();

            // Store configuration in state
            this.state.conversionOptionsConfig = data;

            // Initialize default values for basic options
            if (data.has_options && data.basic_options) {
                const defaultOptions = {};
                Object.entries(data.basic_options).forEach(([key, option]) => {
                    let value = option.default_value;
                    // Convert string booleans to actual booleans
                    if (option.data_type === 'boolean' && typeof value === 'string') {
                        value = value === 'true' || value === '1';
                    }
                    defaultOptions[key] = value;
                });

                // Also include advanced options defaults
                if (data.advanced_options) {
                    Object.entries(data.advanced_options).forEach(([key, option]) => {
                        let value = option.default_value;
                        // Convert string booleans to actual booleans
                        if (option.data_type === 'boolean' && typeof value === 'string') {
                            value = value === 'true' || value === '1';
                        }
                        defaultOptions[key] = value;
                    });
                }

                this.state.conversionOptions = defaultOptions;
            }

            // For email-to-pdf, set default mail_part option
            if (data.is_email_conversion) {
                this.state.conversionOptions.mail_part = 'L'; // Default: Both (Letter content + attachments)
            }

            console.log('[UploadManager] Conversion options loaded:', data);
        } catch (error) {
            console.error('Failed to fetch conversion options:', error);
        }
    }

    /**
     * Log file action (add/remove) to analytics
     */
    logFileAction(action, fileCount, extensions, totalSizeKb = 0) {
        // Fire and forget - don't block UI
        fetch('/api/log-file-action', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                action: action,
                file_count: fileCount,
                extensions: extensions,
                total_size_kb: totalSizeKb,
                page_slug: this.config.pageSlug,
            }),
        }).catch(error => {
            console.warn('Failed to log file action:', error);
        });
    }

    /**
     * Log guest email page view to analytics
     */
    logGuestEmailPageView() {
        const { emailStatusCheck } = this.state;
        const savedEmail = emailStatusCheck.savedEmail || localStorage.getItem('guest_email');
        const emailStatus = emailStatusCheck.status || 'unknown';

        // Fire and forget - don't block UI
        fetch('/api/log-guest-email-page', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                page_slug: this.config.pageSlug,
                file_count: this.state.uploadedFiles.length,
                email_status: emailStatus,
                saved_email: savedEmail ? 'present' : null, // Don't send actual email for privacy
            }),
        }).catch(error => {
            console.warn('Failed to log guest email page view:', error);
        });
    }

    /**
     * Log guest email button click to analytics
     */
    logGuestEmailButtonClick(buttonAction) {
        const { emailStatusCheck } = this.state;
        const emailStatus = emailStatusCheck.status || 'unknown';
        const savedEmail = emailStatusCheck.savedEmail || localStorage.getItem('guest_email');

        // Fire and forget - don't block UI
        fetch('/api/analytics/log', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                event: 'guest_email_button_click',
                meta: {
                    button_action: buttonAction,
                    email_status: emailStatus,
                    has_saved_email: savedEmail ? true : false,
                    page_slug: this.config.pageSlug,
                    file_count: this.state.uploadedFiles.length,
                }
            }),
        }).catch(error => {
            console.warn('Failed to log guest email button click:', error);
        });
    }

    /**
     * Update state and trigger callbacks
     */
    updateState(updates) {
        Object.assign(this.state, updates);
        this.config.onStateChange(this.state);
        this.render();
    }

    /**
     * Go to specific step
     */
    goToStep(step) {
        this.updateState({ currentStep: step });
    }

    /**
     * Main render method
     */
    render() {
        // Prevent infinite render loops
        if (this._isRendering) {
            return;
        }

        this._isRendering = true;
        try {
            const { currentStep } = this.state;

            if (currentStep === 1) {
                this.renderStep1();
            } else if (currentStep === 2) {
                this.renderStep2();
            } else if (currentStep === 3) {
                this.renderStep3();
            }
        } finally {
            this._isRendering = false;
        }
    }

    /**
     * Render Step 1: Upload
     */
    renderStep1() {
        const debugMode = localStorage.getItem('vanilla_upload_debug') === 'true';

        this.config.container.innerHTML = `
            <div class="flex gap-6">
                ${debugMode ? this.renderDebugPanel() : ''}
                <div class="flex-1 relative">
                    <div class="bg-white rounded-[28px] shadow-xl border border-gray-100 p-12">
                        <div class="upload-dropzone border-2 border-dashed border-gray-300 rounded-xl p-16 text-center transition-all relative"
                             data-dropzone>
                    <!-- Upload Icon -->
                    <svg class="w-20 h-20 mx-auto text-gray-300 mb-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>

                    <!-- Primary Action -->
                    <button type="button" class="bg-blue-600 text-white px-8 py-3 rounded-lg text-lg font-semibold hover:bg-blue-700 transition mb-3" data-select-files>
                        ${this.config.translations.select_files || 'Select Files'}
                    </button>

                    <!-- Hint -->
                    <p class="text-gray-400 text-sm">${this.config.translations.or_drop_files_here || 'or drop files here'}</p>

                    <!-- Extensions Badges (Hidden by default, shown on hover) -->
                    <div class="mt-4 min-h-[40px] flex items-center justify-center">
                        <div class="extension-badges flex flex-wrap items-center justify-center gap-2 transition-opacity duration-200" style="opacity: 0;">
                            ${this.config.allowedExtensions.slice(0, 8).map(ext =>
                                `<span class="inline-block px-2 py-1 bg-white text-gray-700 text-xs font-medium rounded border border-gray-300">${ext.toUpperCase()}</span>`
                            ).join('')}
                            ${this.config.allowedExtensions.length > 8 ?
                                `<span class="inline-block px-2 py-1 bg-white text-gray-400 text-xs font-medium rounded border border-gray-300">+${this.config.allowedExtensions.length - 8} more</span>`
                                : ''}
                        </div>
                    </div>

                    <!-- Error Display -->
                    ${this.state.errorMessage ? `
                        <div class="mt-6 bg-red-50 border border-red-200 rounded-lg p-4 max-w-md mx-auto">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-red-800">${this.state.errorMessage}</p>
                                    ${this.state.errorSuggestions.length > 0 ? `
                                        <ul class="mt-2 space-y-1">
                                            ${this.state.errorSuggestions.map(s => `
                                                <li class="text-xs text-red-700 flex items-start gap-1">
                                                    <span class="text-red-400 mt-0.5">•</span>
                                                    <span>${s}</span>
                                                </li>
                                            `).join('')}
                                        </ul>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    ` : ''}
                    </div>

                    <input type="file" multiple style="display: none;" data-file-input
                           accept="${this.config.allowedExtensions.map(e => '.' + e).join(',')}">
                    </div>
                </div>
            </div>
        `;

        this.attachStep1Events();
    }

    /**
     * Attach events for Step 1
     */
    attachStep1Events() {
        const dropzone = this.config.container.querySelector('[data-dropzone]');
        const fileInput = this.config.container.querySelector('[data-file-input]');
        const selectButton = this.config.container.querySelector('[data-select-files]');
        const badges = this.config.container.querySelector('.extension-badges');

        console.log('[UploadManager] attachStep1Events - badges element:', badges);

        // Show/hide extension badges
        if (badges) {
            dropzone.addEventListener('mouseenter', () => {
                console.log('[UploadManager] Mouse enter - showing badges');
                badges.style.opacity = '1';
            });
        } else {
            console.warn('[UploadManager] Extension badges element not found!');
        }

        if (badges) {
            dropzone.addEventListener('mouseleave', () => {
                if (!this.state.isDragging) {
                    console.log('[UploadManager] Mouse leave - hiding badges');
                    badges.style.opacity = '0';
                }
            });
        }

        // Drag & Drop
        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            this.state.isDragging = true;
            dropzone.classList.add('border-blue-400', 'bg-blue-50');
            if (badges) {
                badges.style.opacity = '1';
            }
        });

        dropzone.addEventListener('dragleave', () => {
            this.state.isDragging = false;
            dropzone.classList.remove('border-blue-400', 'bg-blue-50');
            if (badges) {
                badges.style.opacity = '0';
            }
        });

        dropzone.addEventListener('drop', async (e) => {
            e.preventDefault();
            this.state.isDragging = false;
            dropzone.classList.remove('border-blue-400', 'bg-blue-50');
            if (badges) {
                badges.style.opacity = '0';
            }

            const files = Array.from(e.dataTransfer.files);
            await this.processFiles(files);
        });

        // Click to select
        selectButton.addEventListener('click', () => {
            fileInput.click();
        });

        fileInput.addEventListener('change', async (e) => {
            const files = Array.from(e.target.files);
            await this.processFiles(files);
        });
    }

    /**
     * Process selected files
     */
    async processFiles(files) {
        this.clearError();

        // Client-side validation
        const validation = this.validateFiles(files);
        if (!validation.valid) {
            this.showError(validation.error, validation.suggestions);
            return;
        }

        // Extract ZIP files first
        await this.extractZipFiles(files);

        // Analyze email files for attachment count (email-to-pdf only)
        await this.analyzeEmailFiles(files);

        // Convert to uploadedFiles format with metadata
        const validFiles = [];

        for (const [index, file] of files.entries()) {
            const extension = file.name.split('.').pop().toLowerCase();

            // Check if this is a ZIP file
            if (extension === 'zip') {
                await this.processZipFile(file, validFiles);
            } else {
                // Regular file
                validFiles.push({
                    id: Date.now() + index + Math.random(),
                    name: file.name,
                    size: file.size,
                    file: file,
                    isFromZip: false,
                    isZipContainer: false,
                });
            }
        }

        this.state.uploadedFiles = validFiles;

        // Log initial file upload
        const nonZipFiles = validFiles.filter(f => !f.isZipContainer);
        const extensions = Array.from(new Set(nonZipFiles.map(f => f.name.split('.').pop().toLowerCase())));
        const totalSizeKb = nonZipFiles.reduce((sum, f) => sum + (f.size / 1024), 0);
        this.logFileAction('upload', nonZipFiles.length, extensions, Math.round(totalSizeKb * 100) / 100);

        // Update limits
        this.updateFileLimit();
        this.updateSizeLimit();
        this.updateFileSizeLimit();
        this.updateMergeMinimumFilesLimit();
        this.updateMinimumFilesLimit();

        // Select first visible (non-ZIP container) file
        const firstVisibleIndex = validFiles.findIndex(f => !f.isZipContainer);
        this.state.selectedFileIndex = firstVisibleIndex !== -1 ? firstVisibleIndex : 0;

        this.goToStep(2);

        // Pre-upload files to server for localStorage persistence
        // This creates a batch that can be resumed if user navigates away
        await this.preUploadFiles();

        // Load preview for selected file
        if (firstVisibleIndex !== -1) {
            await this.loadPreview(firstVisibleIndex);
        }
    }

    /**
     * Pre-upload files to server for localStorage persistence
     * Creates a pending batch that can be resumed later
     */
    async preUploadFiles() {
        const filesToUpload = this.state.uploadedFiles.filter(f => f.file && !f.isZipContainer);

        if (filesToUpload.length === 0) {
            console.log('[UploadManager] No files to pre-upload');
            return;
        }

        console.log('[UploadManager] Pre-uploading', filesToUpload.length, 'files');

        try {
            const formData = new FormData();
            formData.append('page_slug', this.config.pageSlug);

            filesToUpload.forEach(file => {
                formData.append('files[]', file.file);
            });

            // Add conversion options if any
            if (Object.keys(this.state.conversionOptions).length > 0) {
                formData.append('conversion_options', JSON.stringify(this.state.conversionOptions));
            }

            const response = await fetch('/api/pre-upload', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    'Accept': 'application/json',
                },
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (response.ok && data.batch_id) {
                console.log('[UploadManager] Pre-upload successful, batch_id:', data.batch_id);
                this.state.batchId = data.batch_id;
                this.state.preUploadedBatchId = data.batch_id;

                // Save to localStorage for persistence
                this.savePendingUpload();
            } else {
                console.warn('[UploadManager] Pre-upload failed:', data.error || 'Unknown error');
                // Non-critical failure - conversion will still work with direct upload
            }
        } catch (error) {
            console.error('[UploadManager] Pre-upload error:', error);
            // Non-critical failure - conversion will still work with direct upload
        }
    }

    /**
     * Extract ZIP files via API
     */
    async extractZipFiles(files) {
        const zipFiles = files.filter(f => f.name.toLowerCase().endsWith('.zip'));

        console.log('[ZIP] Starting ZIP extraction', {
            zipCount: zipFiles.length,
            zipNames: zipFiles.map(f => f.name),
            pageSlug: this.config.pageSlug
        });

        if (zipFiles.length === 0) {
            this.state.zipPreviews = [];
            return;
        }

        try {
            const formData = new FormData();
            formData.append('page_slug', this.config.pageSlug);
            zipFiles.forEach(zipFile => {
                formData.append('files[]', zipFile);
                console.log('[ZIP] Adding to FormData:', {
                    name: zipFile.name,
                    size: zipFile.size,
                    type: zipFile.type
                });
            });

            console.log('[ZIP] Sending request to /api/preview-zip...');
            const response = await fetch('/api/preview-zip', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: formData,
            });

            console.log('[ZIP] Response received:', {
                status: response.status,
                statusText: response.statusText,
                ok: response.ok
            });

            if (!response.ok) {
                console.error('[ZIP] Preview failed:', response.statusText);
                this.state.zipPreviews = [];
                return;
            }

            const data = await response.json();
            console.log('[ZIP] Preview data received:', {
                success: data.success,
                extracted_files: data.extracted_files?.length || 0,
                total_files: data.summary?.total_files || 0,
                errors: data.errors || [],
                full_data: data
            });

            this.state.zipPreviews = data.extracted_files || [];
            console.log('[ZIP] ZIP previews stored:', this.state.zipPreviews.length);
        } catch (error) {
            console.error('[ZIP] Extraction error:', error);
            this.state.zipPreviews = [];
        }
    }

    /**
     * Analyze email files to count attachments (for email-to-pdf)
     */
    async analyzeEmailFiles(files) {
        // Only for email-to-pdf and merge-email-to-pdf pages (both English and Dutch slugs)
        const isEmailPage = this.config.pageSlug === 'email-to-pdf' ||
                           this.config.pageSlug === 'converteer-email-naar-pdf' ||
                           this.config.pageSlug === 'merge-email-to-pdf' ||
                           this.config.pageSlug === 'email-samenvoegen-naar-pdf';

        if (!isEmailPage) {
            return;
        }

        const emailFiles = files.filter(f => {
            const ext = f.name.split('.').pop().toLowerCase();
            return ext === 'eml' || ext === 'msg';
        });

        if (emailFiles.length === 0) {
            this.state.emailAnalysis = null;
            this.state.emailAttachmentsCount = 0;
            return;
        }

        console.log('[Email] Analyzing email files...', {
            count: emailFiles.length,
            names: emailFiles.map(f => f.name)
        });

        try {
            const formData = new FormData();
            emailFiles.forEach(emailFile => {
                formData.append('files[]', emailFile);
            });

            // Detect workflow type based on page slug
            const workflowType = (this.config.pageSlug.includes('merge-email') ||
                                 this.config.pageSlug.includes('email-samenvoegen'))
                                 ? 'merge' : 'convert';

            formData.append('workflow_type', workflowType);

            console.log('[Email] Detected workflow type:', workflowType);

            const response = await fetch('/api/analyze-emails', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: formData,
            });

            if (!response.ok) {
                console.error('[Email] Analysis failed:', response.statusText);
                this.state.emailAnalysis = null;
                this.state.emailAttachmentsCount = 0;
                return;
            }

            const data = await response.json();
            console.log('[Email] Analysis completed:', {
                total_emails: data.total_emails,
                convertible_attachments: data.convertible_attachments?.length || 0,
                estimated_credits: data.credit_breakdown?.total || 0
            });

            this.state.emailAnalysis = data;
            this.state.emailAttachmentsCount = data.convertible_attachments?.length || 0;

        } catch (error) {
            console.error('[Email] Analysis error:', error);
            this.state.emailAnalysis = null;
            this.state.emailAttachmentsCount = 0;
        }
    }

    /**
     * Calculate credits needed based on mail_part selection
     *
     * @param {string} mailPart - 'L' (both), 'B' (body only), 'A' (attachments only)
     * @returns {number} Credits needed
     */
    calculateCreditsForMailPart(mailPart = 'L') {
        // Only for email-to-pdf with analysis data
        if (!this.state.emailAnalysis?.credit_breakdown) {
            return 0;
        }

        const breakdown = this.state.emailAnalysis.credit_breakdown;

        switch (mailPart) {
            case 'L': // Both (Letter content + attachments)
                return breakdown.total || 0;

            case 'B': // Body only
                return breakdown.email_bodies || 0;

            case 'A': // Attachments only
                // Attachments = office_attachments + pdf_image_attachments
                const attachments = (breakdown.office_attachments || 0) +
                                   (breakdown.pdf_image_attachments || 0);

                // If multiple attachments need to be merged, add merge cost
                // Note: merge_operation is already calculated in the breakdown
                // But we need to recalculate for attachments-only scenario
                const needsMerge = attachments > 1 ? 1 : 0;

                return attachments + needsMerge;

            default:
                return breakdown.total || 0;
        }
    }

    /**
     * Process a single ZIP file
     */
    async processZipFile(zipFile, validFiles) {
        // Find the corresponding ZIP preview
        const zipPreview = this.state.zipPreviews.find(zp => zp.zip_name === zipFile.name);

        console.log('[ZIP] Processing ZIP file:', {
            zipName: zipFile.name,
            hasPreview: !!zipPreview,
            previewFiles: zipPreview?.files?.length || 0,
            totalPreviews: this.state.zipPreviews.length
        });

        if (zipPreview) {
            console.log('[ZIP] ZIP preview found, extracting files...');
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
                console.log('[ZIP] Adding extracted file:', extractedFile.name);
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
            console.log('[ZIP] ZIP processed successfully, added', zipPreview.files.length, 'files');
        } else {
            console.warn('[ZIP] No preview available for ZIP, adding as regular file');
            // No preview available, add as regular file
            validFiles.push({
                id: Date.now() + Math.random(),
                name: zipFile.name,
                size: zipFile.size,
                file: zipFile,
                isFromZip: false,
                isZipContainer: false,
            });
        }
    }

    /**
     * Validate files
     */
    validateFiles(files) {
        console.log('[UploadManager] Validating files:', files.length);
        console.log('[UploadManager] Allowed extensions:', this.config.allowedExtensions);
        console.log('[UploadManager] Page slug:', this.config.pageSlug);
        console.log('[UploadManager] Action type:', this.config.actionType);

        // File limit validation is now handled via UI (badge + warning banner)
        // Don't block here - let user proceed to step 2 where they can see and fix the issue
        // if (files.length > this.config.maxFiles) {
        //     return {
        //         valid: false,
        //         error: `Maximum ${this.config.maxFiles} files allowed. You selected ${files.length}.`,
        //         suggestions: ['Remove some files and try again'],
        //     };
        // }

        const totalSize = files.reduce((sum, file) => sum + file.size, 0);
        if (totalSize > this.config.maxTotalSize) {
            const maxSizeMB = Math.round(this.config.maxTotalSize / 1024 / 1024);
            const actualSizeMB = (totalSize / 1024 / 1024).toFixed(2);
            return {
                valid: false,
                error: `Total file size exceeds ${maxSizeMB}MB. Your files are ${actualSizeMB}MB.`,
                suggestions: ['Compress your files', 'Remove some files'],
            };
        }

        for (const file of files) {
            const extension = file.name.split('.').pop().toLowerCase();
            console.log(`[UploadManager] Checking file: ${file.name}, extension: ${extension}`);

            if (this.config.allowedExtensions.length > 0 &&
                !this.config.allowedExtensions.includes(extension)) {
                console.log(`[UploadManager] ✗ File rejected: ${file.name} (extension: ${extension})`);
                return {
                    valid: false,
                    error: `File type .${extension} is not allowed.`,
                    suggestions: [
                        `Allowed types: ${this.config.allowedExtensions.join(', ')}`,
                        `File: ${file.name}`,
                    ],
                };
            }

            console.log(`[UploadManager] ✓ File accepted: ${file.name}`);
        }

        console.log('[UploadManager] ✓ All files validated successfully');
        return { valid: true };
    }

    /**
     * Update file limit state based on current uploaded files
     */
    updateFileLimit() {
        // Count actual files (excluding ZIP containers)
        const totalFiles = this.state.uploadedFiles.filter(f => !f.isZipContainer).length;

        this.state.fileLimit.current = totalFiles;
        this.state.fileLimit.valid = totalFiles <= this.state.fileLimit.limit;
        this.state.fileLimit.excess = Math.max(0, totalFiles - this.state.fileLimit.limit);

        console.log('[UploadManager] File limit updated:', this.state.fileLimit);
    }

    /**
     * Update size limit state based on current uploaded files
     */
    updateSizeLimit() {
        // Calculate total size (excluding ZIP containers)
        const totalSize = this.state.uploadedFiles
            .filter(f => !f.isZipContainer)
            .reduce((sum, f) => sum + f.size, 0);

        this.state.sizeLimit.current = totalSize;
        this.state.sizeLimit.valid = totalSize <= this.state.sizeLimit.limit;
        this.state.sizeLimit.excess = Math.max(0, totalSize - this.state.sizeLimit.limit);

        console.log('[UploadManager] Size limit updated:', this.state.sizeLimit);
    }

    /**
     * Update per-file size limit state based on current uploaded files
     */
    updateFileSizeLimit() {
        const oversizedFiles = [];

        this.state.uploadedFiles
            .filter(f => !f.isZipContainer)
            .forEach(f => {
                if (f.size > this.state.fileSizeLimit.limit) {
                    oversizedFiles.push({
                        name: f.name,
                        size: f.size,
                        limit: this.state.fileSizeLimit.limit
                    });
                }
            });

        this.state.fileSizeLimit.oversizedFiles = oversizedFiles;
        this.state.fileSizeLimit.valid = oversizedFiles.length === 0;

        console.log('[UploadManager] File size limit updated:', this.state.fileSizeLimit);
    }

    /**
     * Update merge minimum files limit state (for merge action types only)
     */
    updateMergeMinimumFilesLimit() {
        // Only validate for merge action types
        if (this.config.actionType !== 'merge') {
            this.state.mergeMinimumFilesLimit.valid = true;
            return;
        }

        // Count actual files (excluding ZIP containers)
        const totalFiles = this.state.uploadedFiles.filter(f => !f.isZipContainer).length;

        this.state.mergeMinimumFilesLimit.current = totalFiles;
        this.state.mergeMinimumFilesLimit.valid = totalFiles >= this.state.mergeMinimumFilesLimit.minimum;

        console.log('[UploadManager] Merge minimum files limit updated:', this.state.mergeMinimumFilesLimit);
    }

    /**
     * Update minimum files limit state (must have at least 1 file)
     * Prevents conversion with 0 files
     */
    updateMinimumFilesLimit() {
        // Count visible files (excluding ZIP containers)
        const visibleFileCount = this.state.uploadedFiles.filter(f => !f.isZipContainer).length;

        this.state.minimumFilesLimit = {
            current: visibleFileCount,
            minimum: 1,
            valid: visibleFileCount >= 1
        };

        console.log('[UploadManager] Minimum files limit updated:', this.state.minimumFilesLimit);
    }

    /**
     * Validate credits for conversion
     */
    async validateCredits() {
        // Skip for guest users (handled differently)
        if (this.state.isGuestUser) {
            this.state.creditsLimit.valid = true;
            return;
        }

        // Count files that need credits (excluding ZIP containers)
        const fileCount = this.state.uploadedFiles.filter(f => !f.isZipContainer).length;

        // Skip if we already validated for this file count (prevents infinite loops)
        if (this._lastValidatedFileCount === fileCount) {
            console.log('[UploadManager] Credits already validated for', fileCount, 'files - skipping');
            return;
        }

        console.log('[UploadManager] validateCredits - Total files:', this.state.uploadedFiles.length);
        console.log('[UploadManager] validateCredits - Files for credits (non-ZIP):', fileCount);

        if (fileCount === 0) {
            this.state.creditsLimit.valid = true;
            this.state.creditsInfo = null;
            this.state.creditsError = null;
            this.state.creditsUsed = 0;
            this._lastValidatedFileCount = fileCount;
            return;
        }

        // Cancel previous request if still pending (prevents race conditions)
        if (this._creditsAbortController) {
            this._creditsAbortController.abort();
            console.log('[UploadManager] Cancelled previous credits validation request');
        }

        // Create new AbortController for this request
        this._creditsAbortController = new AbortController();
        const requestId = Date.now(); // Unique ID for this request
        console.log('[UploadManager] Starting credits validation request #' + requestId);

        try {
            const formData = new FormData();
            formData.append('page_slug', this.config.pageSlug);
            formData.append('file_count', fileCount);

            // For email-to-pdf and merge-email-to-pdf: include attachment count and mail_part selection
            const isEmailPage = this.config.pageSlug === 'email-to-pdf' ||
                               this.config.pageSlug === 'converteer-email-naar-pdf' ||
                               this.config.pageSlug === 'merge-email-to-pdf' ||
                               this.config.pageSlug === 'email-samenvoegen-naar-pdf';

            if (isEmailPage && this.state.emailAttachmentsCount > 0) {
                formData.append('email_attachments_count', this.state.emailAttachmentsCount);
                console.log('[UploadManager] Including email attachments count:', this.state.emailAttachmentsCount);

                // Include mail_part selection (L/B/A)
                const mailPart = this.state.conversionOptions.mail_part || 'L';
                formData.append('mail_part', mailPart);
                console.log('[UploadManager] Including mail_part selection:', mailPart);
            }

            if (this.state.activeWorkflow && this.state.activeWorkflow.id) {
                formData.append('workflow_id', this.state.activeWorkflow.id);
            }

            const response = await fetch('/api/validate-credits', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: formData,
                signal: this._creditsAbortController.signal,
            });

            if (!response.ok) {
                console.error('[UploadManager] Credits validation failed:', response.status);
                this.state.creditsLimit.valid = true; // Don't block on API errors
                return;
            }

            const data = await response.json();

            console.log('[UploadManager] Request #' + requestId + ' completed successfully');

            this.state.creditsLimit.available = data.credits_available || 0;
            this.state.creditsLimit.needed = data.credits_needed || 0;
            this.state.creditsLimit.valid = data.valid || false;
            this.state.creditsLimit.deficit = Math.max(0, this.state.creditsLimit.needed - this.state.creditsLimit.available);

            // Set message state for UI badges
            if (data.valid) {
                this.state.creditsInfo = data.message || `This will consume ${data.credits_needed} credits`;
                this.state.creditsError = null;
                this.state.creditsUsed = data.credits_needed || 0;
            } else {
                this.state.creditsError = data.message || 'Insufficient credits';
                this.state.creditsInfo = null;
                this.state.creditsUsed = 0;
            }

            console.log('[UploadManager] Credits validated:', this.state.creditsLimit);
            console.log('[UploadManager] Credits UI state:', {
                creditsInfo: this.state.creditsInfo,
                creditsError: this.state.creditsError,
                creditsUsed: this.state.creditsUsed
            });

            // Remember file count to prevent re-validation
            this._lastValidatedFileCount = fileCount;

            // Trigger ONE re-render if we're in step 2 and not already rendering
            // SKIP if we're in the middle of auth refresh (prevents double render that hides button)
            if (this.state.currentStep === 2 && !this._isRendering && !this._skipCreditsRender) {
                this.render();
            } else if (this._skipCreditsRender) {
                console.log('[UploadManager] Skipping credits render during auth refresh');
            }
        } catch (error) {
            // Ignore aborted requests (they were intentionally cancelled)
            if (error.name === 'AbortError') {
                console.log('[UploadManager] Request #' + requestId + ' was aborted');
                return;
            }
            console.error('[UploadManager] Credits validation error:', error);
            this.state.creditsLimit.valid = true; // Don't block on errors
        }
    }

    /**
     * Render configuration panel (workflow or conversion options)
     */
    renderConfigurationPanel() {
        // Only show workflow display for CUSTOM workflows (not default ones)
        if (this.state.activeWorkflow && !this.state.activeWorkflow.is_default) {
            return this.renderWorkflowDisplay();
        }

        // For default workflows or no workflow: show conversion options + email options + cover page
        return this.renderConversionOptions() +
               this.renderEmailOptions() +
               this.renderCoverPageSection();
    }

    /**
     * Render workflow display
     */
    renderWorkflowDisplay() {
        const workflow = this.state.activeWorkflow;

        return `
            <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4">
                <!-- Workflow Header -->
                <div class="flex items-center gap-2 mb-3">
                    <svg class="w-5 h-5 text-indigo-600" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                    </svg>
                    <div>
                        <div class="text-sm font-medium text-indigo-900">${this.escapeHtml(workflow.name)}</div>
                        <div class="text-xs text-indigo-700">${workflow.steps.length} ${workflow.steps.length === 1 ? 'step' : 'steps'}</div>
                    </div>
                </div>

                <!-- Workflow Steps (always visible) -->
                <div class="space-y-2">
                    ${workflow.steps.map((step, index) => `
                        <!-- Step Card -->
                        <div class="flex items-start gap-3 p-3 bg-white rounded-lg border border-indigo-200">
                            <div class="flex-shrink-0">
                                <div class="w-6 h-6 bg-indigo-600 text-white rounded-full flex items-center justify-center text-xs font-semibold">
                                    ${index + 1}
                                </div>
                            </div>

                            <div class="flex-1 min-w-0">
                                <div class="text-xs font-medium text-gray-900">${this.getStepDisplayName(step.type)}</div>
                                ${step.options && Object.keys(step.options).length > 0 ? `
                                    <div class="mt-1 text-xs text-gray-600">
                                        ${Object.entries(step.options).map(([key, value]) => `
                                            <div class="truncate">
                                                <span class="text-gray-500">${this.formatOptionKey(key)}:</span>
                                                <span class="font-medium">${this.formatOptionValue(value)}</span>
                                            </div>
                                        `).join('')}
                                    </div>
                                ` : ''}
                            </div>
                        </div>

                        <!-- Arrow Connector -->
                        ${index < workflow.steps.length - 1 ? `
                            <div class="flex justify-center py-1">
                                <svg class="w-4 h-4 text-indigo-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v10.586l2.293-2.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L9 14.586V4a1 1 0 011-1z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                        ` : ''}
                    `).join('')}

                    <!-- Output Indicator -->
                    <div class="flex items-center gap-3 p-3 bg-green-50 rounded-lg border border-green-200">
                        <div class="flex-shrink-0">
                            <svg class="w-6 h-6 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-xs font-medium text-gray-900">${this.config.translations.download_ready || 'Download Ready'}</div>
                            <div class="text-xs text-gray-600">Single PDF file via download</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render conversion options (dynamically loaded from API)
     */
    renderConversionOptions() {
        const config = this.state.conversionOptionsConfig;

        // If no config loaded yet or no options available
        if (!config || !config.has_options) {
            return '';
        }

        const basicOptions = config.basic_options || {};
        const advancedOptions = config.advanced_options || {};
        const hasBasicOptions = Object.keys(basicOptions).length > 0;
        const hasAdvancedOptions = Object.keys(advancedOptions).length > 0;
        const showToggle = hasBasicOptions && hasAdvancedOptions;

        let html = `
            <div class="bg-gray-50 rounded-lg p-4">
                <!-- Header -->
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-sm font-medium text-gray-900">${this.t('conversion_options_title', 'Conversion Options')}</h3>
                        <p class="text-xs text-gray-500 mt-1">${this.t('conversion_options_subtitle', 'Customize your conversion settings')}</p>
                    </div>
                    <button type="button" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium" data-reset-options>
                        ${this.t('reset_to_defaults', 'Reset to Defaults')}
                    </button>
                </div>

                <!-- Basic Options -->
                ${hasBasicOptions ? '<div class="space-y-4">' : '<div class="space-y-4">'}
        `;

        // Render basic options
        Object.entries(basicOptions).forEach(([key, option]) => {
            html += this.renderSingleOption(key, option);
        });

        // Render advanced options (inline if no toggle needed)
        if (hasAdvancedOptions && !showToggle) {
            Object.entries(advancedOptions).forEach(([key, option]) => {
                html += this.renderSingleOption(key, option);
            });
        }

        html += '</div>';

        // Show More Options button (only if there are both basic AND advanced options)
        if (showToggle) {
            html += `
                <button type="button" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium mt-4 mb-2" data-toggle-advanced>
                    ${this.t('show_more_options', 'Show More Options')} ▼
                </button>

                <!-- Advanced Options (Hidden by default) -->
                <div class="space-y-4 hidden" data-advanced-options>
            `;

            Object.entries(advancedOptions).forEach(([key, option]) => {
                html += this.renderSingleOption(key, option);
            });

            html += '</div>';
        }

        // Help Text
        html += `
                <div class="mt-4 p-3 bg-blue-50 rounded-lg">
                    <div class="flex items-start gap-2">
                        <svg class="w-4 h-4 text-blue-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                        <div class="text-xs text-blue-800">
                            <p class="font-medium">${this.t('options_help_text', 'These settings customize how your files are converted.')}</p>
                            ${showToggle ? `<p class="mt-1">${this.t('options_advanced_help_text', 'Default values work well for most documents. Click "Show More Options" for additional customization options.')}</p>` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;

        return html;
    }

    /**
     * Render a single conversion option field
     */
    renderSingleOption(key, option) {
        let currentValue = this.state.conversionOptions[key] || option.default_value;

        // Convert string booleans to actual booleans for checkbox rendering
        if (option.data_type === 'boolean' && typeof currentValue === 'string') {
            currentValue = currentValue === 'true' || currentValue === '1';
        }

        const isLocked = option.is_locked || false;
        const requiredTier = option.required_tier || null;
        const tierBadge = option.tier_badge || null;
        const upgradeMessage = option.upgrade_message || null;

        // Common styling classes
        const lockedOpacity = isLocked ? 'opacity-60' : '';
        const lockedTextColor = isLocked ? 'text-gray-500' : 'text-gray-700';
        const disabledStyle = isLocked ? 'bg-gray-100 cursor-not-allowed text-gray-600' : 'bg-white text-gray-900';
        const disabledAttr = isLocked ? 'disabled' : '';

        // Tier badge HTML with lock icon and link to pricing (ONLY for locked options)
        const tierBadgeHtml = isLocked && tierBadge ? `
            <a href="/pricing" class="ml-1 inline-flex items-center gap-1 px-1.5 py-0.5 text-xs font-medium rounded underline bg-amber-100 text-amber-700 hover:bg-amber-200">
                ${tierBadge}
                <svg class="inline-block w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                </svg>
            </a>
        ` : '';

        // No separate lock icon or upgrade prompt - everything is in the badge
        const lockIconHtml = '';
        const upgradePromptHtml = '';

        if (option.data_type === 'select') {
            // Handle both array and object (associative array from PHP) formats
            let optionsHtml;
            if (Array.isArray(option.choices)) {
                // Simple array: ['value1', 'value2']
                optionsHtml = option.choices.map(choice => `
                    <option value="${choice}" ${currentValue === choice ? 'selected' : ''}>${choice}</option>
                `).join('');
            } else {
                // Associative array (object): {key1: 'Label 1', key2: 'Label 2'}
                optionsHtml = Object.entries(option.choices).map(([value, label]) => `
                    <option value="${value}" ${currentValue === value ? 'selected' : ''}>${label}</option>
                `).join('');
            }

            return `
                <div class="mb-4 ${lockedOpacity}">
                    <label class="block text-sm font-medium ${lockedTextColor} mb-2">
                        ${option.name || this.t(option.name_key, key)}:
                        ${tierBadgeHtml}
                        ${lockIconHtml}
                    </label>
                    <select class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 ${disabledStyle}" data-option="${key}" ${disabledAttr}>
                        ${optionsHtml}
                    </select>
                    ${option.description || option.description_key ? `<p class="text-xs text-gray-500 mt-1">${option.description || this.t(option.description_key, '')}</p>` : ''}
                    ${upgradePromptHtml}
                </div>
            `;
        } else if (option.data_type === 'number') {
            return `
                <div class="mb-4 ${lockedOpacity}">
                    <label class="block text-sm font-medium ${lockedTextColor} mb-2">
                        ${option.name || this.t(option.name_key, key)}:
                        ${tierBadgeHtml}
                        ${lockIconHtml}
                    </label>
                    <input type="number" value="${currentValue}" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 ${disabledStyle}" data-option="${key}" ${disabledAttr}>
                    ${option.description || option.description_key ? `<p class="text-xs text-gray-500 mt-1">${option.description || this.t(option.description_key, '')}</p>` : ''}
                    ${upgradePromptHtml}
                </div>
            `;
        } else if (option.data_type === 'boolean') {
            return `
                <div class="mb-4 ${lockedOpacity}">
                    <label class="flex items-center">
                        <input type="checkbox" ${currentValue ? 'checked' : ''} class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 ${isLocked ? 'cursor-not-allowed' : ''}" data-option="${key}" ${disabledAttr}>
                        <span class="ml-2 text-sm ${lockedTextColor}">
                            ${option.name || this.t(option.name_key, key)}
                            ${tierBadgeHtml}
                            ${lockIconHtml}
                        </span>
                    </label>
                    ${option.description || option.description_key ? `<p class="text-xs text-gray-500 mt-1 ml-6">${option.description || this.t(option.description_key, '')}</p>` : ''}
                    ${upgradePromptHtml}
                </div>
            `;
        } else if (option.data_type === 'radio') {
            // Radio buttons with visual feedback
            const radioOptionsHtml = Object.entries(option.choices).map(([value, label]) => `
                <label class="flex items-center cursor-pointer p-3 rounded-lg border-2 transition-colors ${currentValue === value ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300'}">
                    <input type="radio"
                           name="${key}"
                           value="${value}"
                           ${currentValue === value ? 'checked' : ''}
                           class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500"
                           data-option="${key}">
                    <span class="ml-3 text-sm text-gray-900 font-medium">${label}</span>
                </label>
            `).join('');

            return `
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-3">${option.name || this.t(option.name_key, key)}</label>
                    <div class="space-y-2">
                        ${radioOptionsHtml}
                    </div>
                    ${option.description || option.description_key ? `<p class="text-xs text-gray-500 mt-2">${option.description || this.t(option.description_key, '')}</p>` : ''}
                </div>
            `;
        } else {
            // Default text input
            return `
                <div class="mb-4 ${lockedOpacity}">
                    <label class="block text-sm font-medium ${lockedTextColor} mb-2">
                        ${option.name || this.t(option.name_key, key)}:
                        ${tierBadgeHtml}
                        ${lockIconHtml}
                    </label>
                    <input type="text" value="${currentValue}" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 ${disabledStyle}" data-option="${key}" ${disabledAttr}>
                    ${option.description || option.description_key ? `<p class="text-xs text-gray-500 mt-1">${option.description || this.t(option.description_key, '')}</p>` : ''}
                    ${upgradePromptHtml}
                </div>
            `;
        }
    }

    /**
     * Render email-to-pdf specific options (mail parts selection)
     */
    renderEmailOptions() {
        const config = this.state.conversionOptionsConfig;

        // Only show for email-to-pdf conversion
        if (!config || !config.is_email_conversion) {
            return '';
        }

        const currentValue = this.state.conversionOptions.mail_part || 'L';

        return `
            <div class="mt-6 bg-gray-50 border border-gray-200 rounded-lg p-4">
                <h4 class="font-semibold text-gray-900 mb-3 text-sm">${this.t('mail_parts_title', 'Mail Parts Selection')}</h4>
                <div class="space-y-2">
                    <!-- Option 1: Both (Letter content + attachments) -->
                    <label class="flex items-center gap-3 p-3 bg-white border border-gray-200 rounded-lg cursor-pointer hover:border-blue-300 transition">
                        <input type="radio"
                               name="mail_part"
                               value="L"
                               ${currentValue === 'L' ? 'checked' : ''}
                               data-email-option="mail_part"
                               class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                        <div class="flex-1">
                            <p class="font-medium text-sm text-gray-900">${this.t('mail_part_both', 'Email Body + Attachments')}</p>
                            <p class="text-xs text-gray-500">${this.t('mail_part_both_desc', 'Complete email with all attachments (default)')}</p>
                        </div>
                    </label>

                    <!-- Option 2: Body Only -->
                    <label class="flex items-center gap-3 p-3 bg-white border border-gray-200 rounded-lg cursor-pointer hover:border-blue-300 transition">
                        <input type="radio"
                               name="mail_part"
                               value="B"
                               ${currentValue === 'B' ? 'checked' : ''}
                               data-email-option="mail_part"
                               class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                        <div class="flex-1">
                            <p class="font-medium text-sm text-gray-900">${this.t('mail_part_body', 'Email Body Only')}</p>
                            <p class="text-xs text-gray-500">${this.t('mail_part_body_desc', 'Only the email message, no attachments')}</p>
                        </div>
                    </label>

                    <!-- Option 3: Attachments Only -->
                    <label class="flex items-center gap-3 p-3 bg-white border border-gray-200 rounded-lg cursor-pointer hover:border-blue-300 transition">
                        <input type="radio"
                               name="mail_part"
                               value="A"
                               ${currentValue === 'A' ? 'checked' : ''}
                               data-email-option="mail_part"
                               class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                        <div class="flex-1">
                            <p class="font-medium text-sm text-gray-900">${this.t('mail_part_attachments', 'Attachments Only')}</p>
                            <p class="text-xs text-gray-500">${this.t('mail_part_attachments_desc', 'Only attachments, skip email body')}</p>
                        </div>
                    </label>
                </div>
            </div>
        `;
    }

    /**
     * Render cover page section
     */
    renderCoverPageSection() {
        // Only show cover page when output is PDF
        if (this.config.outputFormat !== 'pdf') {
            return '';
        }

        // Only enable cover page for merge mode (not for split mode)
        // Check both conversionOptions.output_mode (user-selectable) and config.actionType (page config)
        const outputMode = this.state.conversionOptions?.output_mode || this.config.actionType || 'merge';
        const isSplitMode = outputMode === 'split';

        // Hide cover page entirely for split mode
        if (isSplitMode) {
            return '';
        }

        const isGuest = this.state.isGuestUser;
        const disabledAttr = isGuest ? 'disabled' : '';
        const disabledClasses = isGuest ? 'opacity-50 cursor-not-allowed' : '';
        const buttonClasses = isGuest
            ? 'bg-gray-400 text-white cursor-not-allowed opacity-50'
            : 'bg-blue-600 text-white hover:bg-blue-700';

        // Version 1: HAS TEMPLATE (compact version with template name)
        if (this.state.coverTemplateName) {
            return `
                <div class="mt-6" data-test-id="cover-page-section">
                    <div class="bg-gray-50 border-2 border-gray-200 rounded-lg p-4">
                        <div class="flex gap-4">
                            <!-- Left: Small Preview Icon -->
                            <div class="flex-shrink-0">
                                <img src="/site/coverpage_for_pdf_files.webp"
                                     alt="Cover page preview"
                                     class="w-16 h-auto rounded shadow-sm">
                            </div>

                            <!-- Right: Content -->
                            <div class="flex-1 flex flex-col gap-2">
                                <!-- Header with checkbox -->
                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox"
                                               ${this.state.coverPageEnabled && !isGuest ? 'checked' : ''}
                                               ${disabledAttr}
                                               data-cover-toggle
                                               class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 ${disabledClasses}">
                                        <span class="text-sm font-semibold text-gray-900">${this.t('add_cover_page', 'Add Cover Page')}</span>
                                    </div>
                                    <button type="button"
                                            data-cover-configure
                                            ${disabledAttr}
                                            class="text-sm px-4 py-2 rounded-lg transition font-medium ${buttonClasses}">
                                        ${this.t('btn_configure', 'Configure')}
                                    </button>
                                </div>

                                <!-- Template name on separate line -->
                                <div class="text-sm text-gray-700">
                                    <span class="font-medium">${this.escapeHtml(this.state.coverTemplateName)}</span>
                                </div>

                                ${isGuest ? `
                                <div class="mt-2 flex items-start gap-2 text-sm text-gray-600">
                                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                                    </svg>
                                    <span>${this.t('cover_login_required', 'Cover pages are available for registered users. Please log in or create a free account.')}</span>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        // Version 2: NO TEMPLATE (expanded version with image, no checkbox until template is created)
        return `
            <div class="mt-6" data-test-id="cover-page-section">
                <div class="bg-gray-50 border-2 border-gray-200 rounded-lg p-6">
                    <div class="flex gap-6 items-start">
                        <!-- Left: Preview Image -->
                        <div class="flex-shrink-0">
                            <img src="/site/coverpage_for_pdf_files.webp"
                                 alt="Cover page preview"
                                 class="w-48 h-auto rounded-lg shadow-md">
                        </div>

                        <!-- Right: Content -->
                        <div class="flex-1 flex flex-col gap-4">
                            <!-- Title (no checkbox - user must configure first) -->
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-900">${this.t('add_cover_page', 'Add Cover Page')}</h3>
                            </div>

                            <!-- Description -->
                            <p class="text-sm text-gray-600 leading-relaxed">
                                ${this.t('cover_page_description', 'Add a professional cover page to your PDF with your logo, title, and table of contents.')}
                            </p>

                            ${isGuest ? `
                            <!-- Login Required Message -->
                            <div class="flex items-start gap-2 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800">
                                <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                                </svg>
                                <span>${this.t('cover_login_required', 'Cover pages are available for registered users. Please log in or create a free account.')}</span>
                            </div>
                            ` : ''}

                            <!-- Configure Button -->
                            <div>
                                <button type="button"
                                        data-cover-configure
                                        ${disabledAttr}
                                        class="px-6 py-2.5 rounded-lg transition font-medium ${buttonClasses}">
                                    ${this.t('btn_configure', 'Configure')}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Get display name for workflow step
     */
    getStepDisplayName(type) {
        const names = {
            'merge_pdfs': 'Merge PDFs',
            'compress_pdf': 'Compress PDF',
            'images_to_pdf': 'Images to PDF',
            'word_to_pdf': 'Word to PDF',
            'excel_to_pdf': 'Excel to PDF',
            'powerpoint_to_pdf': 'PowerPoint to PDF',
            'zip_output': 'ZIP Output',
            'generic_convert_to_pdf': 'Convert to PDF'
        };
        return names[type] || type;
    }

    /**
     * Format option key for display
     */
    formatOptionKey(key) {
        return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }

    /**
     * Format option value for display
     */
    formatOptionValue(value) {
        if (typeof value === 'boolean') {
            return value ? 'Yes' : 'No';
        }
        if (typeof value === 'string' || typeof value === 'number') {
            return value;
        }
        return JSON.stringify(value);
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Render Step 2: Configure (3-column layout)
     */
    renderStep2() {
        // Update limits before rendering
        this.updateFileLimit();
        this.updateSizeLimit();
        this.updateFileSizeLimit();
        this.updateMergeMinimumFilesLimit();

        // Validate credits in background (no re-render to prevent infinite loop)
        // Credits info will be available on next manual render
        this.validateCredits();

        // Keep ALL files but only show non-ZIP containers in list
        const allFiles = this.state.uploadedFiles;
        const visibleFiles = allFiles.filter(f => !f.isZipContainer);
        const selectedFile = allFiles[this.state.selectedFileIndex];

        // Debug mode (check localStorage)
        const debugMode = localStorage.getItem('vanilla_upload_debug') === 'true';

        this.config.container.innerHTML = `
            <div class="relative">
                <!-- Credits Available Badge (top-left) -->
                ${this.state.creditsLimit.available !== null ? `
                    <div class="absolute -top-3 left-4 z-10">
                        <div class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm font-medium border border-blue-200">
                            <span>${this.state.creditsLimit.available} credits available</span>
                        </div>
                    </div>
                ` : ''}

                <div class="bg-white rounded-[28px] shadow-xl border border-gray-100 p-8">
                    <!-- Back Button -->
                    <button type="button" class="mb-6 text-gray-600 hover:text-gray-900 flex items-center gap-2 transition" data-back>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        ${this.config.translations.back || 'Back'}
                    </button>

                    <!-- Login Gate Banner (for verified guests who need to log in) -->
                ${this.state.emailStatusCheck.requiresLogin ? this.renderLoginGateBanner() : ''}

                <!-- File Limit Warning - Full Width -->
                ${!this.state.fileLimit.valid ? `
                <div class="mb-6 p-4 bg-red-50 border-2 border-red-200 rounded-lg">
                    <div class="flex items-start gap-3">
                        <svg class="w-6 h-6 text-red-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 9.586 8.707 8.293z" clip-rule="evenodd"/>
                        </svg>
                        <div class="flex-1">
                            <h4 class="text-base font-semibold text-red-900 mb-2">Bestandslimiet Overschreden</h4>
                            <p class="text-sm text-red-700 mb-1">
                                Je hebt ${this.state.fileLimit.current} bestanden, maar je limiet is ${this.state.fileLimit.limit}.
                            </p>
                            <p class="text-sm font-medium text-red-800 mb-2">
                                Verwijder ${this.state.fileLimit.excess} bestand(en) om door te gaan.
                            </p>
                            <p class="text-sm text-red-700">
                                <a href="/pricing" class="font-medium underline hover:text-red-900">Upgrade je licentie</a> voor meer en grotere bestanden.
                            </p>
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- Size Limit Warning - Full Width -->
                ${!this.state.sizeLimit.valid ? `
                <div class="mb-6 p-4 bg-orange-50 border-2 border-orange-200 rounded-lg">
                    <div class="flex items-start gap-3">
                        <svg class="w-6 h-6 text-orange-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        <div class="flex-1">
                            <h4 class="text-base font-semibold text-orange-900 mb-2">Bestand is te groot</h4>
                            <p class="text-sm text-orange-700 mb-1">
                                Je bestanden zijn ${(this.state.sizeLimit.current / 1024 / 1024).toFixed(2)}MB, maar je limiet is ${Math.round(this.state.sizeLimit.limit / 1024 / 1024)}MB.
                            </p>
                            <p class="text-sm font-medium text-orange-800 mb-2">
                                Verwijder bestanden of comprimeer ze (${(this.state.sizeLimit.excess / 1024 / 1024).toFixed(2)}MB te veel).
                            </p>
                            <p class="text-sm text-orange-700">
                                <a href="/pricing" class="font-medium underline hover:text-orange-900">Upgrade je licentie</a> voor meer en grotere bestanden.
                            </p>
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- Credits Warning - Full Width -->
                ${!this.state.creditsLimit.valid && !this.state.isGuestUser ? `
                <div class="mb-6 p-4 bg-purple-50 border-2 border-purple-200 rounded-lg">
                    <div class="flex items-start gap-3">
                        <svg class="w-6 h-6 text-purple-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"/>
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"/>
                        </svg>
                        <div class="flex-1">
                            <h4 class="text-base font-semibold text-purple-900 mb-2">Onvoldoende Credits</h4>
                            <p class="text-sm text-purple-700 mb-1">
                                Je hebt ${this.state.creditsLimit.available} credits, maar je hebt ${this.state.creditsLimit.needed} credits nodig.
                            </p>
                            <p class="text-sm font-medium text-purple-800 mb-2">
                                Je komt ${this.state.creditsLimit.deficit} credit(s) tekort.
                            </p>
                            <p class="text-sm text-purple-700">
                                <a href="/pricing" class="font-medium underline hover:text-purple-900">Koop meer credits</a> om door te gaan.
                            </p>
                        </div>
                    </div>
                </div>
                ` : ''}

                ${!this.state.fileSizeLimit.valid ? `
                <div class="mb-6 p-4 bg-yellow-50 border-2 border-yellow-200 rounded-lg">
                    <div class="flex items-start gap-3">
                        <svg class="w-6 h-6 text-yellow-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        <div class="flex-1">
                            <h4 class="text-base font-semibold text-yellow-900 mb-2">Bestand(en) Te Groot</h4>
                            <p class="text-sm text-yellow-700 mb-2">
                                De volgende bestanden overschrijden de limiet van ${this.formatFileSize(this.state.fileSizeLimit.limit)} per bestand:
                            </p>
                            <ul class="list-disc list-inside text-sm text-yellow-700 mb-2 space-y-1">
                                ${this.state.fileSizeLimit.oversizedFiles.map(f => `
                                    <li><strong>${f.name}</strong> (${this.formatFileSize(f.size)})</li>
                                `).join('')}
                            </ul>
                            <p class="text-sm text-yellow-700">
                                <a href="/pricing" class="font-medium underline hover:text-yellow-900">Upgrade je account</a> voor hogere limieten of verwijder de grote bestanden.
                            </p>
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- Merge Minimum Files Warning - Full Width -->
                ${!this.state.mergeMinimumFilesLimit.valid ? `
                <div class="mb-6 p-4 bg-blue-50 border-2 border-blue-200 rounded-lg">
                    <div class="flex items-start gap-3">
                        <svg class="w-6 h-6 text-blue-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                        <div class="flex-1">
                            <h4 class="text-base font-semibold text-blue-900 mb-2">${this.t('error_merge_minimum_files').replace(':count', this.state.mergeMinimumFilesLimit.current)}</h4>
                            <p class="text-sm text-blue-700 mb-2">
                                ${this.t('error_merge_minimum_files_suggestion')}
                            </p>
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- No Files Warning - Full Width -->
                ${!this.state.minimumFilesLimit.valid ? `
                <div class="mb-6 p-4 bg-blue-50 border-l-4 border-blue-400 rounded-r-lg">
                    <div class="flex items-start gap-3">
                        <svg class="w-6 h-6 text-blue-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                        <div class="flex-1">
                            <h4 class="text-base font-semibold text-blue-900 mb-1">${this.t('no_files_to_convert')}</h4>
                            <p class="text-sm text-blue-700">
                                ${this.t('upload_files_to_continue')}
                            </p>
                        </div>
                    </div>
                </div>
                ` : ''}

                <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
                    ${debugMode ? this.renderDebugPanel() : ''}
                    <!-- LEFT: File List (20% on desktop, full width on mobile) -->
                    <div class="w-full lg:w-1/5 lg:min-w-[240px]">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-2">
                                <h3 class="font-semibold text-gray-900">Files</h3>
                                <span class="px-2 py-0.5 text-xs font-medium rounded ${this.state.fileLimit.valid ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}">
                                    ${this.state.fileLimit.current} / ${this.state.fileLimit.limit}
                                </span>
                            </div>
                            <button type="button" class="text-sm text-blue-600 hover:text-blue-700 transition" data-add-more>${this.config.translations.add_files || '+ Add'}</button>
                        </div>

                        <div class="space-y-2 max-h-96 overflow-y-auto" data-file-list>
                            ${allFiles.filter(f => !f.isZipContainer).map((file, visibleIndex) => {
                                // Get original index from allFiles for proper selection
                                const originalIndex = allFiles.indexOf(file);
                                return `
                                <div class="w-full p-3 rounded-lg transition cursor-pointer group ${originalIndex === this.state.selectedFileIndex ? 'bg-blue-50 border-2 border-blue-500' : 'bg-gray-50 hover:bg-gray-100 border-2 border-transparent'}"
                                     draggable="true"
                                     data-file-index="${originalIndex}"
                                     data-select-file="${originalIndex}">
                                    <div class="flex items-center gap-3">
                                        <!-- Drag Handle -->
                                        <div class="drag-handle cursor-grab active:cursor-grabbing text-gray-400 hover:text-gray-600 flex-shrink-0" title="Drag to reorder">
                                            <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
                                                <path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/>
                                            </svg>
                                        </div>
                                        <div class="w-10 h-10 rounded flex items-center justify-center flex-shrink-0">
                                            <img src="${this.getFileIconPath(file.name.split('.').pop())}"
                                                 class="w-10 h-10"
                                                 alt="${this.getFileTypeName(file.name.split('.').pop())}" />
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <div class="text-sm font-medium text-gray-900" title="${this.escapeHtml(file.name)}">${this.escapeHtml(this.truncateFileName(file.name))}</div>
                                                ${file.isFromZip ? `
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800">
                                                        ${this.t('from_zip', 'from ZIP')}
                                                    </span>
                                                ` : ''}
                                            </div>
                                            <div class="text-xs text-gray-500">${this.formatFileSize(file.size)}</div>
                                        </div>
                                        <button type="button" class="text-gray-500 hover:text-red-600 transition flex-shrink-0 p-1" data-remove-file="${originalIndex}" onclick="event.stopPropagation()">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            `; }).join('')}
                        </div>

                        <input type="file" multiple style="display: none;" data-add-more-input
                               accept="${this.config.allowedExtensions.map(e => '.' + e).join(',')}">
                    </div>

                    <!-- CENTER: Preview Area (20% on desktop, full width on mobile) -->
                    <div class="w-full lg:w-1/5 lg:min-w-[240px]">
                        <h3 class="font-semibold text-gray-900 mb-4">${this.config.translations.preview || 'Preview'}</h3>
                        <div class="bg-gray-100 rounded-lg p-6 min-h-[500px] flex flex-col">
                            <div class="flex-1 bg-white rounded-lg shadow-inner flex items-center justify-center mb-4 overflow-hidden" data-preview-container>
                                ${this.state.previewLoading ? `
                                    <div class="text-gray-400 flex items-center gap-2">
                                        <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        ${this.config.translations.loading_preview || 'Loading preview...'}
                                    </div>
                                ` : this.state.previewUrl ? `
                                    <img src="${this.state.previewUrl}" class="max-w-full max-h-full object-contain" alt="Preview">
                                ` : this.state.pdfDoc ? `
                                    <canvas data-preview-canvas class="max-w-full max-h-full"></canvas>
                                ` : selectedFile?.isZipContainer ? `
                                    <div class="text-gray-600 text-center">
                                        <svg class="w-16 h-16 mx-auto mb-2 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                                        </svg>
                                        <p class="text-sm font-medium mb-1">ZIP Archive</p>
                                        <p class="text-xs text-gray-500">Contains ${selectedFile.extractedCount || 0} files</p>
                                    </div>
                                ` : `
                                    <div class="text-center py-8">
                                        <img src="${this.getFileIconPath(selectedFile?.name?.split('.').pop() || 'blank')}"
                                             class="w-24 h-24 mx-auto mb-4"
                                             alt="${this.getFileTypeName(selectedFile?.name?.split('.').pop() || '')}" />
                                        <p class="text-base font-semibold text-gray-900 mb-1">${selectedFile?.name || ''}</p>
                                        <p class="text-sm text-gray-600 mb-2">${this.formatFileSize(selectedFile?.size || 0)}</p>
                                        <p class="text-sm text-gray-500">${this.getFileTypeName(selectedFile?.name?.split('.').pop() || '')}</p>
                                    </div>
                                `}
                            </div>

                            ${this.state.pdfDoc && this.state.totalPages > 1 ? `
                                <div class="flex items-center justify-between">
                                    <button type="button" class="px-3 py-1 text-sm text-gray-600 hover:text-gray-900 disabled:opacity-30 disabled:cursor-not-allowed"
                                            data-prev-page ${this.state.currentPage === 1 ? 'disabled' : ''}>
                                        ${this.config.translations.previous_page || '← Previous'}
                                    </button>
                                    <span class="text-sm text-gray-600">${(this.config.translations.page_of || 'Page :current of :total').replace(':current', this.state.currentPage).replace(':total', this.state.totalPages)}</span>
                                    <button type="button" class="px-3 py-1 text-sm text-gray-600 hover:text-gray-900 disabled:opacity-30 disabled:cursor-not-allowed"
                                            data-next-page ${this.state.currentPage === this.state.totalPages ? 'disabled' : ''}>
                                        ${this.config.translations.next_page || 'Next →'}
                                    </button>
                                </div>
                            ` : ''}
                        </div>
                    </div>

                    <!-- RIGHT: Configuration (60% on desktop, full width on mobile) -->
                    <div class="w-full lg:w-3/5">
                        ${this.renderConfigurationPanel()}
                    </div>
                </div>

                <!-- Bottom: Convert Button -->
                <div class="mt-8 pt-6 border-t border-gray-200">
                    <div class="text-center">
                        <button type="button"
                                class="px-12 py-4 rounded-lg text-lg font-semibold transition ${this.state.minimumFilesLimit.valid && this.state.fileLimit.valid && this.state.sizeLimit.valid && this.state.creditsLimit.valid && this.state.fileSizeLimit.valid && this.state.mergeMinimumFilesLimit.valid && !this.state.emailStatusCheck.requiresLogin ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-300 text-gray-500 cursor-not-allowed'}"
                                data-convert-now
                                ${!this.state.minimumFilesLimit.valid || !this.state.fileLimit.valid || !this.state.sizeLimit.valid || !this.state.creditsLimit.valid || !this.state.fileSizeLimit.valid || !this.state.mergeMinimumFilesLimit.valid || this.state.emailStatusCheck.requiresLogin ? 'disabled' : ''}>
                            ${this.getConvertButtonText()}
                        </button>
                        <!-- Credits Information/Error Messages -->
                        ${this.state.creditsInfo ? `
                            <div class="mt-3 text-sm text-gray-600 text-center">
                                ${this.state.creditsInfo}
                            </div>
                        ` : ''}
                        ${this.state.creditsError ? `
                            <div class="mt-3 text-sm text-red-600 text-center">
                                ${this.state.creditsError}
                            </div>
                        ` : ''}
                    </div>
                </div>
            </div>
        </div>
        `;

        this.attachStep2Events();
    }

    /**
     * Attach events for Step 2
     */
    attachStep2Events() {
        // Back button - clears everything and goes to step 1
        this.config.container.querySelector('[data-back]')?.addEventListener('click', () => {
            this.resetUpload();
        });

        // Add more files
        const addMoreBtn = this.config.container.querySelector('[data-add-more]');
        const addMoreInput = this.config.container.querySelector('[data-add-more-input]');

        addMoreBtn?.addEventListener('click', () => {
            addMoreInput.click();
        });

        addMoreInput?.addEventListener('change', async (e) => {
            const newFiles = Array.from(e.target.files);
            await this.addMoreFiles(newFiles);
        });

        // File selection
        this.config.container.querySelectorAll('[data-select-file]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const index = parseInt(btn.dataset.selectFile);
                this.state.selectedFileIndex = index;
                await this.loadPreview(index);
                this.render();
            });
        });

        // Remove file
        this.config.container.querySelectorAll('[data-remove-file]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const index = parseInt(btn.dataset.removeFile);
                this.removeFile(index);
            });
        });

        // PDF navigation
        this.config.container.querySelector('[data-prev-page]')?.addEventListener('click', () => {
            this.prevPage();
        });

        this.config.container.querySelector('[data-next-page]')?.addEventListener('click', () => {
            this.nextPage();
        });

        // Conversion options toggle
        const advancedToggle = this.config.container.querySelector('[data-toggle-advanced]');
        const advancedOptions = this.config.container.querySelector('[data-advanced-options]');

        if (advancedToggle && advancedOptions) {
            advancedToggle.addEventListener('click', () => {
                const isHidden = advancedOptions.classList.contains('hidden');
                advancedOptions.classList.toggle('hidden');
                advancedToggle.textContent = isHidden
                    ? `${this.t('show_fewer_options', 'Show Fewer Options')} ▲`
                    : `${this.t('show_more_options', 'Show More Options')} ▼`;
            });
        }

        // Reset options
        this.config.container.querySelector('[data-reset-options]')?.addEventListener('click', () => {
            // Reset all option fields to defaults
            const pageSize = this.config.container.querySelector('[data-option="page_size"]');
            const orientation = this.config.container.querySelector('[data-option="orientation"]');
            const quality = this.config.container.querySelector('[data-option="quality"]');
            const dpi = this.config.container.querySelector('[data-option="dpi"]');

            if (pageSize) pageSize.value = 'A4';
            if (orientation) orientation.value = 'portrait';
            if (quality) quality.value = 'default';
            if (dpi) dpi.value = '300';

            this.state.conversionOptions = {};
        });

        // Track conversion option changes
        this.config.container.querySelectorAll('[data-option]').forEach(field => {
            field.addEventListener('change', (e) => {
                const optionName = e.target.dataset.option;
                let value;

                if (e.target.type === 'checkbox') {
                    value = e.target.checked;
                } else if (e.target.type === 'radio') {
                    value = e.target.value;
                } else {
                    value = e.target.value;
                }

                this.state.conversionOptions[optionName] = value;
                console.log(`[UploadManager] Option changed: ${optionName} = ${value}`);

                // NOTE: We don't need to re-render for output_mode changes anymore
                // Alpine.js handles show/hide of cover page section reactively via x-show="outputMode === 'merge'"
                // Re-rendering was causing Alpine state resets and visual bugs

            });
        });

        // Track email-specific option changes
        this.config.container.querySelectorAll('[data-email-option]').forEach(field => {
            field.addEventListener('change', (e) => {
                const optionName = e.target.dataset.emailOption;
                const value = e.target.value;
                this.state.conversionOptions[optionName] = value;
                console.log(`[UploadManager] Email option changed: ${optionName} = ${value}`);

                // If mail_part changed, recalculate credits instantly
                if (optionName === 'mail_part' && this.state.emailAnalysis) {
                    const newCredits = this.calculateCreditsForMailPart(value);
                    const fileCount = this.state.uploadedFiles.filter(f => !f.isZipContainer).length;

                    this.state.creditsUsed = newCredits;
                    this.state.creditsInfo = `This conversion costs ${newCredits} ${newCredits === 1 ? 'credit' : 'credits'} (${fileCount} ${fileCount === 1 ? 'file' : 'files'})`;

                    console.log(`[UploadManager] Credits recalculated for mail_part=${value}: ${newCredits}`);

                    // Update the UI
                    this.render();
                }
            });
        });

        // Cover page toggle
        this.config.container.querySelector('[data-cover-toggle]')?.addEventListener('change', (e) => {
            this.state.coverPageEnabled = e.target.checked;
        });

        // Cover page configure button
        this.config.container.querySelector('[data-cover-configure]')?.addEventListener('click', () => {
            console.log('[UploadManager] Configure button clicked');

            // Dispatch Livewire event to open modal (v3 syntax)
            if (typeof Livewire !== 'undefined') {
                console.log('[UploadManager] Dispatching openModal event to Livewire');
                Livewire.dispatch('openModal');
                console.log('[UploadManager] openModal event dispatched');
            } else {
                console.warn('[UploadManager] Livewire not available - cannot open cover page modal');
            }
        });

        // Convert button
        this.config.container.querySelector('[data-convert-now]')?.addEventListener('click', () => {
            this.startConversion();
        });

        // Render PDF preview if needed
        if (this.state.pdfDoc) {
            this.renderPdfPage();
        }

        // Attach drag-drop events for file reordering
        this.attachDragDropEvents();
    }

    /**
     * Attach drag-drop events for file reordering
     */
    attachDragDropEvents() {
        const fileList = this.config.container.querySelector('[data-file-list]');
        if (!fileList) return;

        const items = fileList.querySelectorAll('[data-file-index]');
        if (items.length < 2) return; // No need for drag-drop with 0 or 1 file

        items.forEach(item => {
            item.addEventListener('dragstart', (e) => this.handleDragStart(e));
            item.addEventListener('dragend', (e) => this.handleDragEnd(e));
            item.addEventListener('dragover', (e) => this.handleDragOver(e));
            item.addEventListener('drop', (e) => this.handleDrop(e));
            item.addEventListener('dragenter', (e) => this.handleDragEnter(e));
            item.addEventListener('dragleave', (e) => this.handleDragLeave(e));
        });
    }

    handleDragStart(e) {
        const item = e.target.closest('[data-file-index]');
        if (!item) return;

        this._draggedIndex = parseInt(item.dataset.fileIndex);
        item.classList.add('opacity-50');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', this._draggedIndex.toString());
    }

    handleDragEnd(e) {
        const item = e.target.closest('[data-file-index]');
        if (item) item.classList.remove('opacity-50');

        // Remove all drag-over styling
        this.config.container.querySelectorAll('.drag-over').forEach(el => {
            el.classList.remove('drag-over', 'border-blue-400', 'bg-blue-50');
        });
    }

    handleDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }

    handleDragEnter(e) {
        e.preventDefault();
        const item = e.target.closest('[data-file-index]');
        if (item && parseInt(item.dataset.fileIndex) !== this._draggedIndex) {
            item.classList.add('drag-over', 'border-blue-400', 'bg-blue-50');
        }
    }

    handleDragLeave(e) {
        const item = e.target.closest('[data-file-index]');
        if (item && !item.contains(e.relatedTarget)) {
            item.classList.remove('drag-over', 'border-blue-400', 'bg-blue-50');
        }
    }

    handleDrop(e) {
        e.preventDefault();
        const item = e.target.closest('[data-file-index]');
        if (!item) return;

        const dropIndex = parseInt(item.dataset.fileIndex);
        if (dropIndex === this._draggedIndex) return;

        this.reorderFiles(this._draggedIndex, dropIndex);
    }

    /**
     * Reorder files in state
     */
    reorderFiles(fromIndex, toIndex) {
        const files = [...this.state.uploadedFiles];
        const [movedFile] = files.splice(fromIndex, 1);
        files.splice(toIndex, 0, movedFile);

        this.state.uploadedFiles = files;
        this.state.selectedFileIndex = toIndex;

        console.log(`[UploadManager] Reordered file from index ${fromIndex} to ${toIndex}`);
        this.render();
    }

    /**
     * Add more files
     */
    async addMoreFiles(newFiles) {
        // Non-blocking validation - just add files and let UI show warnings
        // No need to block here, user will see banners if limits exceeded

        // Log file add action
        const extensions = Array.from(new Set(newFiles.map(f => f.name.split('.').pop().toLowerCase())));
        const totalSizeKb = newFiles.reduce((sum, f) => sum + (f.size / 1024), 0);
        this.logFileAction('add', newFiles.length, extensions, Math.round(totalSizeKb * 100) / 100);

        // Extract ZIP files first (same as in processFiles)
        await this.extractZipFiles(newFiles);

        // Analyze all email files (including existing + new) for attachment count
        // We need to re-analyze ALL files, not just new ones, to get accurate total
        const isEmailPage = this.config.pageSlug === 'email-to-pdf' ||
                           this.config.pageSlug === 'converteer-email-naar-pdf' ||
                           this.config.pageSlug === 'merge-email-to-pdf' ||
                           this.config.pageSlug === 'email-samenvoegen-naar-pdf';

        if (isEmailPage) {
            const allEmailFiles = [
                ...this.state.uploadedFiles.map(f => f.file).filter(f => f),
                ...newFiles
            ].filter(f => {
                const ext = f.name.split('.').pop().toLowerCase();
                return ext === 'eml' || ext === 'msg';
            });

            if (allEmailFiles.length > 0) {
                await this.analyzeEmailFiles(allEmailFiles);
            }
        }

        // Process files (handle ZIPs and regular files)
        const newUploadedFiles = [];
        for (const [index, file] of newFiles.entries()) {
            const extension = file.name.split('.').pop().toLowerCase();

            // Check if this is a ZIP file
            if (extension === 'zip') {
                await this.processZipFile(file, newUploadedFiles);
            } else {
                // Regular file
                newUploadedFiles.push({
                    id: Date.now() + index + Math.random(),
                    name: file.name,
                    size: file.size,
                    file: file,
                    isFromZip: false,
                    isZipContainer: false,
                });
            }
        }

        this.state.uploadedFiles = [...this.state.uploadedFiles, ...newUploadedFiles];

        // Reset validation cache so credits are re-validated
        this._lastValidatedFileCount = undefined;

        // Update limits (same as removeFile)
        this.updateFileLimit();
        this.updateSizeLimit();
        this.updateFileSizeLimit();
        this.updateMergeMinimumFilesLimit();
        this.updateMinimumFilesLimit();

        // Sync to server if we have a pre-upload batch
        if (this.state.preUploadedBatchId) {
            this.syncPreUpload();
        }

        this.render();
    }

    /**
     * Remove file
     */
    removeFile(index) {
        const removedFile = this.state.uploadedFiles[index];
        const extension = removedFile.name.split('.').pop().toLowerCase();
        const fileSizeKb = Math.round((removedFile.size / 1024) * 100) / 100;

        // Log file remove action
        this.logFileAction('remove', 1, [extension], fileSizeKb);

        this.state.uploadedFiles.splice(index, 1);

        // Reset validation cache so credits are re-validated
        this._lastValidatedFileCount = undefined;

        // For email-to-pdf and merge-email-to-pdf: re-analyze remaining email files to update attachment count
        const isEmailPage = this.config.pageSlug === 'email-to-pdf' ||
                           this.config.pageSlug === 'converteer-email-naar-pdf' ||
                           this.config.pageSlug === 'merge-email-to-pdf' ||
                           this.config.pageSlug === 'email-samenvoegen-naar-pdf';

        if (isEmailPage) {
            const remainingEmailFiles = this.state.uploadedFiles
                .map(f => f.file)
                .filter(f => {
                    if (!f) return false;
                    const ext = f.name.split('.').pop().toLowerCase();
                    return ext === 'eml' || ext === 'msg';
                });

            if (remainingEmailFiles.length > 0) {
                // Re-analyze in background (don't await to keep UI responsive)
                this.analyzeEmailFiles(remainingEmailFiles).then(() => {
                    // Re-render to update credit display
                    this.render();
                });
            } else {
                // No email files left, reset analysis
                this.state.emailAnalysis = null;
                this.state.emailAttachmentsCount = 0;
            }
        }

        // ZIP container cleanup: If no visible files remain, remove all ZIP containers
        const hasVisibleFiles = this.state.uploadedFiles.some(f => !f.isZipContainer);
        if (!hasVisibleFiles) {
            // All extracted files removed, remove ZIP containers too
            this.state.uploadedFiles = this.state.uploadedFiles.filter(f => !f.isZipContainer);
            console.log('[UploadManager] Removed ZIP containers (no visible files remain)');
        }

        if (this.state.selectedFileIndex >= this.state.uploadedFiles.length) {
            this.state.selectedFileIndex = Math.max(0, this.state.uploadedFiles.length - 1);
        }

        // Update limits (including new minimum files limit)
        this.updateFileLimit();
        this.updateSizeLimit();
        this.updateFileSizeLimit();
        this.updateMergeMinimumFilesLimit();
        this.updateMinimumFilesLimit(); // NEW: Check if we still have files

        // Sync to server if we have a pre-upload batch
        if (this.state.preUploadedBatchId) {
            this.syncPreUpload();
        }

        this.render();
    }

    /**
     * Sync files to pre-upload batch on server
     * Called after add/remove to keep server in sync
     */
    async syncPreUpload() {
        // Debounce rapid changes
        if (this._syncTimeout) {
            clearTimeout(this._syncTimeout);
        }

        this._syncTimeout = setTimeout(async () => {
            console.log('[UploadManager] Syncing pre-upload after file change');

            // Clear the old pre-upload batch ID since we're creating a new one
            this.state.preUploadedBatchId = null;
            this.state.batchId = null;

            // Re-upload all current files
            await this.preUploadFiles();
        }, 500); // Wait 500ms for rapid changes to settle
    }

    /**
     * Load preview for file
     */
    async loadPreview(index) {
        const file = this.state.uploadedFiles[index];
        if (!file) return;

        this.updateState({
            previewLoading: true,
            previewUrl: null,
            pdfDoc: null
        });

        // Check if this is a ZIP container
        if (file.isZipContainer) {
            this.updateState({
                previewLoading: false,
                previewUrl: null,
                pdfDoc: null
            });
            return;
        }

        const extension = file.name.split('.').pop().toLowerCase();
        const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff'];

        if (imageExtensions.includes(extension)) {
            // Image preview
            let url;

            // Check if this is a virtual file (from ZIP) with previewContent
            if (file.previewContent) {
                // Use base64 previewContent from ZIP extraction
                url = `data:image/${extension};base64,${file.previewContent}`;
            } else if (file.file) {
                // Regular file - create object URL
                url = URL.createObjectURL(file.file);
            }

            this.updateState({
                previewUrl: url,
                previewLoading: false
            });
        } else if (extension === 'pdf') {
            // PDF preview (requires PDF.js)
            if (file.previewContent) {
                // Virtual PDF from ZIP with base64 content
                await this.loadPdfPreviewFromBase64(file.previewContent);
            } else if (file.file) {
                // Regular PDF file
                await this.loadPdfPreview(file.file);
            } else {
                // Virtual PDF file - no preview available
                this.updateState({ previewLoading: false });
            }
        } else {
            // No preview available
            this.updateState({ previewLoading: false });
        }
    }

    /**
     * Load PDF preview (requires PDF.js)
     */
    async loadPdfPreview(file) {
        try {
            // Prevent concurrent loads
            if (this.state.pdfLoading) {
                return;
            }
            this.state.pdfLoading = true;

            // Cancel any existing render and wait for it to complete
            if (this.state.currentRenderTask) {
                try {
                    this.state.currentRenderTask.cancel();
                    await this.state.currentRenderTask.promise;
                } catch (e) {
                    // Expected: RenderingCancelledException
                }
                this.state.currentRenderTask = null;
            }

            // Destroy previous PDF document to free resources
            if (this.state.pdfDoc) {
                this.state.pdfDoc.destroy();
                this.state.pdfDoc = null;
            }

            // Clear existing canvas
            const canvas = this.config.container.querySelector('[data-preview-canvas]');
            if (canvas) {
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);
            }

            // Load PDF.js if not already loaded
            if (!window.pdfjsLib) {
                await window.loadPdfJs?.();
            }

            if (!window.pdfjsLib) {
                this.state.pdfLoading = false;
                this.updateState({ previewLoading: false });
                return;
            }

            const arrayBuffer = await file.arrayBuffer();
            const pdf = await window.pdfjsLib.getDocument({ data: arrayBuffer }).promise;

            this.state.pdfDoc = pdf;
            this.state.totalPages = pdf.numPages;
            this.state.currentPage = 1;
            this.state.previewLoading = false;
            this.state.pdfLoading = false;

            this.render();
            await this.renderPdfPage();

        } catch (error) {
            this.state.pdfLoading = false;
            // Ignore cancellation errors
            if (error.name !== 'RenderingCancelledException') {
                console.error('PDF preview error:', error);
            }
            this.updateState({ previewLoading: false });
        }
    }

    /**
     * Load PDF preview from base64 (for virtual files from ZIP)
     */
    async loadPdfPreviewFromBase64(base64Content) {
        try {
            // Prevent concurrent loads
            if (this.state.pdfLoading) {
                return;
            }
            this.state.pdfLoading = true;

            // Cancel any existing render and wait for it to complete
            if (this.state.currentRenderTask) {
                try {
                    this.state.currentRenderTask.cancel();
                    await this.state.currentRenderTask.promise;
                } catch (e) {
                    // Expected: RenderingCancelledException
                }
                this.state.currentRenderTask = null;
            }

            // Destroy previous PDF document to free resources
            if (this.state.pdfDoc) {
                this.state.pdfDoc.destroy();
                this.state.pdfDoc = null;
            }

            // Load PDF.js if not already loaded
            if (!window.pdfjsLib) {
                await window.loadPdfJs?.();
            }

            if (!window.pdfjsLib) {
                this.state.pdfLoading = false;
                this.updateState({ previewLoading: false });
                return;
            }

            // Convert base64 to Uint8Array
            const binaryString = atob(base64Content);
            const bytes = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }

            const pdf = await window.pdfjsLib.getDocument({ data: bytes }).promise;

            this.state.pdfDoc = pdf;
            this.state.totalPages = pdf.numPages;
            this.state.currentPage = 1;
            this.state.previewLoading = false;
            this.state.pdfLoading = false;

            this.render();
            await this.renderPdfPage();

        } catch (error) {
            this.state.pdfLoading = false;
            if (error.name !== 'RenderingCancelledException') {
                console.error('PDF preview from base64 error:', error);
            }
            this.updateState({ previewLoading: false });
        }
    }

    /**
     * Render PDF page to canvas
     */
    async renderPdfPage() {
        if (!this.state.pdfDoc) return;

        // Cancel previous render and wait for it to complete
        if (this.state.currentRenderTask) {
            try {
                this.state.currentRenderTask.cancel();
                await this.state.currentRenderTask.promise;
            } catch (e) {
                // Expected: RenderingCancelledException
            }
            this.state.currentRenderTask = null;
        }

        const canvas = this.config.container.querySelector('[data-preview-canvas]');
        if (!canvas) return;

        const page = await this.state.pdfDoc.getPage(this.state.currentPage);
        const viewport = page.getViewport({ scale: 1.5 });

        canvas.width = viewport.width;
        canvas.height = viewport.height;

        // Clear canvas before rendering
        const context = canvas.getContext('2d');
        context.clearRect(0, 0, canvas.width, canvas.height);

        const renderTask = page.render({ canvasContext: context, viewport });
        this.state.currentRenderTask = renderTask;

        try {
            await renderTask.promise;
        } catch (error) {
            // Ignore cancellation errors
            if (error.name !== 'RenderingCancelledException') {
                throw error;
            }
        }
        this.state.currentRenderTask = null;
    }

    /**
     * Previous PDF page
     */
    async prevPage() {
        if (this.state.currentPage > 1) {
            this.state.currentPage--;
            this.render();
            await this.renderPdfPage();
        }
    }

    /**
     * Next PDF page
     */
    async nextPage() {
        if (this.state.currentPage < this.state.totalPages) {
            this.state.currentPage++;
            this.render();
            await this.renderPdfPage();
        }
    }

    /**
     * Render Step 3: Processing/Download
     */
    renderStep3() {
        console.log('[UploadManager] renderStep3 called. Status:', this.state.conversionStatus);

        // Log guest email page view (only once per session)
        const { conversionStatus, isGuestUser } = this.state;
        const sessionKey = `guest_email_logged_${this.config.pageSlug}`;

        if (conversionStatus === 'awaiting_email' && isGuestUser && !sessionStorage.getItem(sessionKey)) {
            this.logGuestEmailPageView();
            sessionStorage.setItem(sessionKey, 'true');
        }

        const creditsRemaining = (this.state.creditsLimit.available !== null && this.state.creditsUsed > 0)
            ? this.state.creditsLimit.available - this.state.creditsUsed
            : null;

        this.config.container.innerHTML = `
            <div class="relative">
                <!-- Credits Remaining Badge (top-right) -->
                ${creditsRemaining !== null ? `
                    <div class="absolute -top-3 -right-3 bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-sm font-medium border border-orange-200 z-10">
                        <span>${creditsRemaining} credits left</span>
                    </div>
                ` : ''}

                <div class="bg-white rounded-[28px] shadow-xl border border-gray-100 p-20">
                    ${this.renderProcessingState()}
                </div>
            </div>
        `;

        console.log('[UploadManager] Calling attachStep3Events...');
        this.attachStep3Events();
    }

    /**
     * Render processing state
     */
    renderProcessingState() {
        const { conversionStatus, batchId, isGuestUser } = this.state;
        const translations = this.config.translations || {};

        console.log('[UploadManager] renderProcessingState - Status:', conversionStatus, 'BatchId:', batchId, 'IsGuest:', isGuestUser);

        // Guest email capture state (NEW)
        if (conversionStatus === 'awaiting_email' && isGuestUser) {
            return this.renderGuestEmailCapture();
        }

        if (conversionStatus === 'processing') {
            return `
                <div class="text-center">
                    <div class="mb-8">
                        <svg class="w-20 h-20 mx-auto text-blue-600 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-semibold text-gray-900 mb-2">${translations.converting_files || 'Converting files...'}</h3>
                    <p class="text-gray-500">${translations.conversion_in_progress || 'This may take a moment'}</p>
                </div>
            `;
        }

        if (conversionStatus === 'done') {
            return `
                <div class="text-center">
                    <div class="mb-8">
                        <svg class="w-20 h-20 mx-auto text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-semibold text-gray-900 mb-8">${translations.file_ready || 'File ready!'}</h3>
                    <a href="${this.state.downloadUrl}" class="inline-block bg-green-600 text-white px-10 py-4 rounded-lg text-lg font-semibold hover:bg-green-700 transition mb-4">
                        <svg class="inline-block w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        ${this.state.downloadButtonText}
                    </a>
                    <div class="mt-8 flex items-center justify-center gap-6">
                        <button type="button" class="flex items-center gap-2 text-gray-600 hover:text-gray-900 font-medium transition" data-reset>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                            </svg>
                            ${translations.start_new || 'Start New'}
                        </button>
                        ${!this.state.isGuestUser && this.state.batchId ? `
                            <button type="button" onclick="window.location.href='/profile/transactions'" class="flex items-center gap-2 text-red-600 hover:text-red-800 font-medium transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                                ${translations.delete_file || 'Delete file'}
                            </button>
                            <button type="button" class="flex items-center gap-2 text-blue-600 hover:text-blue-800 font-medium transition" data-share-link>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                </svg>
                                ${translations.share_download_link || 'Share download link'}
                            </button>
                        ` : ''}
                    </div>
                    ${this.state.creditsUsed > 0 ? `
                        <div class="mt-6 text-sm text-gray-500">
                            ${this.state.creditsUsed} ${this.state.creditsUsed === 1 ? (translations.credit_used_singular || 'credit verbruikt') : (translations.credits_used_label || 'credits verbruikt')}
                        </div>
                    ` : ''}
                    ${this.renderFeedbackWidget()}
                    ${this.renderNextStepOptions()}
                </div>
            `;
        }

        if (conversionStatus === 'error') {
            return `
                <div class="text-center">
                    <div class="mb-8">
                        <svg class="w-20 h-20 mx-auto text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-semibold text-gray-900 mb-4">${translations.something_went_wrong || 'Something went wrong'}</h3>
                    <div class="max-w-md mx-auto mb-8">
                        <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-left">
                            <p class="text-gray-800 font-medium mb-2">${this.state.errorMessage || translations.conversion_failed || 'Conversion failed'}</p>
                        </div>
                    </div>
                    <div class="flex items-center justify-center gap-4">
                        <button type="button" class="bg-gray-100 text-gray-700 px-6 py-3 rounded-lg font-semibold hover:bg-gray-200 transition" data-reset>
                            ← ${translations.start_over || 'Start Over'}
                        </button>
                        <button type="button" class="bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition" data-retry>
                            ${translations.try_again || 'Try Again'}
                        </button>
                    </div>
                </div>
            `;
        }

        return `<div class="text-center text-gray-500">${translations.ready_to_start || 'Ready to convert'}</div>`;
    }

    /**
     * Render guest email capture UI with status checking
     */
    renderGuestEmailCapture() {
        const { emailStatusCheck, guestEmailError } = this.state;
        const { checking, status, savedEmail, message } = emailStatusCheck;
        const translations = this.config.translations || {};

        console.log('[UploadManager] renderGuestEmailCapture - Status:', status, 'SavedEmail:', savedEmail);

        // Show loading state while checking
        if (checking) {
            return `
                <div class="text-center max-w-md mx-auto">
                    <div class="mb-6">
                        <svg class="w-16 h-16 mx-auto text-blue-600 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-2">${translations.checking_email_status || 'Checking email status...'}</h3>
                    <p class="text-sm text-gray-500">${translations.please_wait_moment || 'Please wait a moment'}</p>
                </div>
            `;
        }

        // Email verified - show success and start conversion button
        if (status === 'verified') {
            return `
                <div class="text-center max-w-md mx-auto">
                    <div class="mb-6">
                        <svg class="w-16 h-16 mx-auto text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-semibold text-gray-900 mb-2">Email Verified!</h3>
                    <p class="text-sm text-gray-600 mb-6">Your email <strong>${savedEmail}</strong> has been confirmed.</p>

                    <button type="button"
                            data-start-conversion
                            class="w-full bg-green-600 text-white px-6 py-3 rounded-lg text-lg font-semibold hover:bg-green-700 transition">
                        ${this.config.translations.start_conversion || 'Start Conversion'}
                    </button>
                </div>
            `;
        }

        // Email pending - show status and resend option
        if (status === 'pending') {
            const canResend = this.canResendEmail;
            const countdown = this.resendCountdown;

            return `
                <div class="text-center max-w-md mx-auto">
                    <div class="mb-6">
                        <svg class="w-16 h-16 mx-auto text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-semibold text-gray-900 mb-2">${translations.check_your_email || 'Check Your Email'}</h3>
                    <p class="text-sm text-gray-600 mb-2">${translations.email_sent_to || 'We sent a confirmation link to:'}</p>
                    <p class="text-md font-semibold text-gray-900 mb-6">${savedEmail}</p>

                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6 text-left">
                        <p class="text-sm text-yellow-800">
                            <strong>${translations.next_steps || 'Next steps:'}</strong><br>
                            1. ${translations.guest_check_email_step_1 || 'Check your email inbox (and spam folder)'}<br>
                            2. ${translations.guest_check_email_step_2 || 'Click the confirmation link'}<br>
                            3. ${translations.guest_check_email_step_3 || 'Return here and click "Check Status"'}
                        </p>
                    </div>

                    <div class="space-y-3">
                        <button type="button"
                                data-check-email-status
                                class="w-full bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition">
                            ${translations.check_status || 'Check Status'}
                        </button>

                        <button type="button"
                                data-resend-email
                                ${!canResend ? 'disabled' : ''}
                                class="w-full bg-gray-100 text-gray-700 px-6 py-3 rounded-lg font-semibold hover:bg-gray-200 transition disabled:opacity-50 disabled:cursor-not-allowed">
                            ${canResend ? (translations.resend_confirmation_email || 'Resend Confirmation Email') : (translations.resend_in_seconds || 'Resend in :secondss').replace(':seconds', countdown)}
                        </button>

                        <button type="button"
                                data-use-different-email
                                class="text-sm text-gray-600 hover:text-gray-900 underline">
                            ${translations.use_different_email || 'Use a different email address'}
                        </button>
                    </div>
                </div>
            `;
        }

        // Email not found or no saved email - show email input form
        return `
            <div class="text-center max-w-md mx-auto">
                <div class="mb-6">
                    <svg class="w-16 h-16 mx-auto text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h3 class="text-2xl font-semibold text-gray-900 mb-2">${translations.guest_email_prompt || 'Enter Your Email'}</h3>
                <p class="text-sm text-gray-600 mb-6">${translations.guest_email_info || 'We\'ll send you a confirmation email to start the conversion.'}</p>

                ${guestEmailError ? `
                    <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4 text-left">
                        <p class="text-sm text-red-800">${guestEmailError}</p>
                    </div>
                ` : ''}

                <form data-guest-email-form class="space-y-4">
                    <div class="text-left">
                        <label for="guest-email-input" class="block text-sm font-medium text-gray-700 mb-1">
                            ${translations.email_address || 'Email Address'} <span class="text-red-500">*</span>
                        </label>
                        <input type="email"
                               id="guest-email-input"
                               name="email"
                               required
                               placeholder="${translations.email_placeholder || 'your@email.com'}"
                               value="${savedEmail || ''}"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-gray-900">
                    </div>

                    <button type="submit"
                            data-submit-guest-email
                            class="w-full bg-blue-600 text-white px-6 py-3 rounded-lg text-lg font-semibold hover:bg-blue-700 transition">
                        ${translations.continue_with_email || 'Continue with Email'}
                    </button>
                </form>

                <p class="text-xs text-gray-500 mt-4">
                    ${translations.email_notification_agreement || 'By continuing, you agree to receive email notifications about your conversion.'}
                </p>
            </div>
        `;
    }

    /**
     * Render login gate banner (for verified users who need to log in)
     */
    renderLoginGateBanner() {
        const { message } = this.state.emailStatusCheck;
        const translations = this.config.translations || {};

        return `
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <svg class="h-6 w-6 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                        </svg>
                    </div>
                    <div class="ml-3 flex-1">
                        <h3 class="text-lg font-medium text-blue-800 mb-2">
                            ${translations.account_created_title || 'Account Created!'}
                        </h3>
                        <div class="mt-2 text-sm text-blue-700">
                            <p>${translations.requires_login_message || 'Check your email and confirm your account to continue.'}</p>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Attach events for Step 3
     */
    attachStep3Events() {
        const resetBtn = this.config.container.querySelector('[data-reset]');
        const retryBtn = this.config.container.querySelector('[data-retry]');
        const shareBtn = this.config.container.querySelector('[data-share-link]');

        // Guest email form elements
        const guestEmailForm = this.config.container.querySelector('[data-guest-email-form]');
        const checkStatusBtn = this.config.container.querySelector('[data-check-email-status]');
        const resendEmailBtn = this.config.container.querySelector('[data-resend-email]');
        const useDifferentEmailBtn = this.config.container.querySelector('[data-use-different-email]');
        const startConversionBtn = this.config.container.querySelector('[data-start-conversion]');

        console.log('[UploadManager] attachStep3Events - Found buttons:', {
            reset: !!resetBtn,
            retry: !!retryBtn,
            share: !!shareBtn,
            guestEmailForm: !!guestEmailForm,
            checkStatus: !!checkStatusBtn,
            resendEmail: !!resendEmailBtn,
            useDifferentEmail: !!useDifferentEmailBtn,
            startConversion: !!startConversionBtn
        });

        resetBtn?.addEventListener('click', () => {
            this.resetUpload();
        });

        retryBtn?.addEventListener('click', () => {
            this.startConversion();
        });

        shareBtn?.addEventListener('click', () => {
            console.log('[UploadManager] Share button clicked!');
            this.createShareLink();
        });

        // Feedback widget event listeners
        const feedbackThumbUp = this.config.container.querySelector('[data-feedback-thumb="up"]');
        const feedbackThumbDown = this.config.container.querySelector('[data-feedback-thumb="down"]');
        const feedbackSubmitBtn = this.config.container.querySelector('[data-feedback-submit]');
        const feedbackContentArea = this.config.container.querySelector('[data-feedback-content]');

        feedbackThumbUp?.addEventListener('click', () => {
            this.selectFeedbackThumb('up');
        });

        feedbackThumbDown?.addEventListener('click', () => {
            this.selectFeedbackThumb('down');
        });

        feedbackSubmitBtn?.addEventListener('click', () => {
            this.submitFeedback();
        });

        feedbackContentArea?.addEventListener('input', (e) => {
            this.updateFeedbackContent(e.target.value);
        });

        // Guest email form submission
        guestEmailForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const emailInput = guestEmailForm.querySelector('[name="email"]');
            const email = emailInput?.value.trim();

            if (!email) {
                this.updateState({ guestEmailError: 'Please enter a valid email address' });
                this.render();
                return;
            }

            await this.submitGuestEmail(email);
        });

        // Check email status button
        checkStatusBtn?.addEventListener('click', async () => {
            this.logGuestEmailButtonClick('check_status');
            await this.checkSavedEmailStatus();
            this.render();
        });

        // Resend confirmation email button
        resendEmailBtn?.addEventListener('click', async () => {
            if (this.canResendEmail) {
                this.logGuestEmailButtonClick('resend_confirmation');
                await this.resendConfirmationEmail();
            }
        });

        // Use different email button
        useDifferentEmailBtn?.addEventListener('click', () => {
            this.logGuestEmailButtonClick('use_different_email');
            this.clearSavedEmail();
        });

        // Start conversion button (for verified email)
        startConversionBtn?.addEventListener('click', async () => {
            await this.proceedWithConversion();
        });
    }

    /**
     * Check saved email status (for returning guests)
     */
    async checkSavedEmailStatus(options = {}) {
        const { silent = false } = options; // silent = true means no spinner during polling
        console.log('[UploadManager] checkSavedEmailStatus - isGuestUser:', this.state.isGuestUser, 'silent:', silent);

        // Only for guests
        if (!this.state.isGuestUser) {
            console.log('[UploadManager] Not a guest, skipping email status check');
            return;
        }

        // Check localStorage for saved email
        const savedEmail = localStorage.getItem('app_guest_email');
        console.log('[UploadManager] localStorage email:', savedEmail);

        if (!savedEmail) {
            // No saved email - show normal email form
            this.updateState({
                emailStatusCheck: {
                    ...this.state.emailStatusCheck,
                    status: 'not_found'
                }
            });
            console.log('[UploadManager] No savedEmail, status set to not_found');
            return;
        }

        // Only show spinner if not silent (i.e., manual check, not auto-polling)
        if (!silent) {
            this.updateState({
                emailStatusCheck: {
                    ...this.state.emailStatusCheck,
                    checking: true,
                    savedEmail: savedEmail
                }
            });
        }
        console.log('[UploadManager] Checking email status via API...');

        try {
            // ALWAYS make fresh API call (no caching for cross-device sync)
            const response = await fetch(`/api/check-email-status?email=${encodeURIComponent(savedEmail)}`);

            if (response.ok) {
                const data = await response.json();
                this.updateState({
                    emailStatusCheck: {
                        checking: false,
                        savedEmail: savedEmail,
                        status: data.status,
                        message: data.message,
                        canResend: data.can_resend || false,
                        lastSentAt: data.last_sent_at,
                        requiresLogin: data.requires_login || false,
                    }
                });
                console.log('[UploadManager] Email status checked:', data.status, 'requires_login:', data.requires_login);
            } else {
                // Fallback on API failure
                this.updateState({
                    emailStatusCheck: {
                        ...this.state.emailStatusCheck,
                        checking: false,
                        status: 'not_found'
                    }
                });
                console.warn('[UploadManager] Email status API failed, falling back to not_found');
            }
        } catch (error) {
            // Fallback on error
            this.updateState({
                emailStatusCheck: {
                    ...this.state.emailStatusCheck,
                    checking: false,
                    status: 'not_found'
                }
            });
            console.error('[UploadManager] Email status check error:', error);
        }
    }

    /**
     * Clear saved email (called by "Use Different Email" button)
     */
    clearSavedEmail() {
        localStorage.removeItem('app_guest_email');
        localStorage.removeItem('app_guest_email_saved_at');
        this.updateState({
            emailStatusCheck: {
                checking: false,
                savedEmail: null,
                status: null,
                message: null,
                canResend: false,
                lastSentAt: null,
            },
            guestEmail: '',
            guestEmailError: '',
        });
        this.render();
    }

    /**
     * Save email to localStorage after guest submits
     */
    saveEmailToLocalStorage(email) {
        localStorage.setItem('app_guest_email', email);
        localStorage.setItem('app_guest_email_saved_at', Date.now());
        console.log('[UploadManager] Email saved to localStorage:', email);
    }

    /**
     * Resend confirmation email
     */
    async resendConfirmationEmail() {
        const email = this.state.emailStatusCheck.savedEmail;
        if (!email) return;

        try {
            const response = await fetch('/api/guest/resend-confirmation', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ email }),
            });

            const data = await response.json();

            if (data.success) {
                // Update last sent timestamp
                this.updateState({
                    emailStatusCheck: {
                        ...this.state.emailStatusCheck,
                        canResend: false,
                        lastSentAt: new Date().toISOString(),
                    }
                });
                this.render();
                alert(data.message || 'Confirmation email resent!');
            } else {
                alert(data.message || 'Failed to resend email');
            }
        } catch (error) {
            console.error('[UploadManager] Resend email failed:', error);
            alert('Failed to resend email. Please try again.');
        }
    }

    /**
     * Computed: can resend email (rate limiting)
     */
    get canResendEmail() {
        if (!this.state.emailStatusCheck.canResend) return false;

        const lastSent = this.state.emailStatusCheck.lastSentAt;
        if (!lastSent) return true;

        const secondsSince = (Date.now() - new Date(lastSent).getTime()) / 1000;
        return secondsSince >= 60;
    }

    /**
     * Computed: resend countdown
     */
    get resendCountdown() {
        const lastSent = this.state.emailStatusCheck.lastSentAt;
        if (!lastSent) return 0;

        const secondsSince = (Date.now() - new Date(lastSent).getTime()) / 1000;
        return Math.max(0, Math.ceil(60 - secondsSince));
    }

    /**
     * Start email status polling (every 5 seconds)
     */
    startEmailPolling() {
        console.log('[UploadManager] Starting email status polling...');

        // Stop any existing polling first
        this.stopEmailPolling();

        // Poll every 5 seconds (silent mode - no spinner)
        this.emailPollingInterval = setInterval(async () => {
            console.log('[UploadManager] Polling email status...');
            await this.checkSavedEmailStatus({ silent: true }); // Silent = no spinner

            // Re-render to update UI after polling
            this.render();

            // Stop polling if verified
            if (this.state.emailStatusCheck.status === 'verified') {
                this.stopEmailPolling();

                // CRITICAL: Refresh CSRF token WITHOUT reloading page (preserves upload state)
                // When user verifies email in another tab, this tab needs fresh auth state
                console.log('[UploadManager] Email verified - refreshing auth state without reload');
                await this.refreshAuthState();

                // STOP - don't render again in this poll iteration
                return;
            }
        }, 5000);
    }

    /**
     * Stop email status polling
     */
    stopEmailPolling() {
        if (this.emailPollingInterval) {
            console.log('[UploadManager] Stopping email status polling');
            clearInterval(this.emailPollingInterval);
            this.emailPollingInterval = null;
        }
    }

    /**
     * Refresh auth state after email verification in another tab
     * Updates CSRF token and window.auth without reloading page
     */
    async refreshAuthState() {
        console.log('[UploadManager] Refreshing auth state...');

        try {
            // STEP 1: Get fresh CSRF token from server
            const csrfResponse = await fetch('/api/csrf-token', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!csrfResponse.ok) {
                console.warn('[UploadManager] Failed to get CSRF token:', csrfResponse.status);
                return;
            }

            const csrfData = await csrfResponse.json();

            // STEP 2: Update CSRF token in meta tag (critical for subsequent requests)
            const metaTag = document.querySelector('meta[name="csrf-token"]');
            if (metaTag && csrfData.csrf_token) {
                metaTag.setAttribute('content', csrfData.csrf_token);
                console.log('[UploadManager] CSRF token updated in meta tag');
            }

            // STEP 3: Get user credits (now that we have fresh CSRF)
            const creditsResponse = await fetch('/api/user/credits', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfData.csrf_token, // Use fresh token
                },
                credentials: 'same-origin',
            });

            if (creditsResponse.ok) {
                const data = await creditsResponse.json();

                // STEP 4: Update window.auth object (now user is logged in)
                window.auth = {
                    check: true,
                    user: {
                        email: this.state.emailStatusCheck.savedEmail,
                        credits: data.credits || 0
                    }
                };

                // STEP 5: Update internal state - RESET to step 2 (configure)
                this.state.isGuestUser = false;
                this.state.creditsLimit.available = data.credits || 0;
                this.state.creditsLimit.valid = true; // Reset credit validation
                this.state.currentStep = 2; // Back to configure step with files intact
                this.state.conversionStatus = 'ready'; // Ready to convert
                this.state.guestEmailCaptured = false; // No longer in guest email flow
                this.state.isProcessing = false; // Not processing

                // CRITICAL: Completely clear email status check to prevent re-triggering
                this.state.emailStatusCheck = {
                    checking: false,
                    savedEmail: null,
                    status: null,
                    message: null,
                    canResend: false,
                    lastSentAt: null,
                    requiresLogin: false,
                };

                console.log('[UploadManager] Auth state fully refreshed - user authenticated with fresh CSRF token, returning to configure step');
                console.log('[UploadManager] State before render:', {
                    isGuestUser: this.state.isGuestUser,
                    currentStep: this.state.currentStep,
                    conversionStatus: this.state.conversionStatus,
                    guestEmailCaptured: this.state.guestEmailCaptured,
                    emailStatusCheck: this.state.emailStatusCheck,
                    uploadedFiles: this.state.uploadedFiles.length
                });

                // CRITICAL: Prevent validateCredits from re-rendering while we're setting up
                this._skipCreditsRender = true;

                // Re-render ONCE to show authenticated UI with convert button
                this.render();

                // Re-enable credits rendering after initial render
                setTimeout(() => {
                    this._skipCreditsRender = false;
                }, 500);

                // Scroll to bottom to show convert button (it's below the fold)
                setTimeout(() => {
                    const convertButton = this.config.container.querySelector('[data-convert-now]');
                    if (convertButton) {
                        convertButton.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        console.log('[UploadManager] Scrolled to convert button');
                    } else {
                        console.warn('[UploadManager] Convert button not found in DOM!');
                    }
                }, 300); // Small delay to ensure render is complete
            } else {
                console.warn('[UploadManager] Failed to get user credits:', creditsResponse.status);
            }
        } catch (error) {
            console.error('[UploadManager] Error refreshing auth state:', error);
        }
    }

    /**
     * Start countdown timer for resend button (updates every second)
     */
    startCountdownTimer() {
        console.log('[UploadManager] Starting countdown timer...');

        // Stop any existing timer first
        this.stopCountdownTimer();

        // Update countdown every second
        this.countdownInterval = setInterval(() => {
            const countdown = this.resendCountdown;

            // Stop when countdown reaches 0 or status changes
            if (countdown <= 0 || this.state.emailStatusCheck.status !== 'pending') {
                this.stopCountdownTimer();
            }

            // Re-render to update countdown display
            this.render();
        }, 1000);
    }

    /**
     * Stop countdown timer
     */
    stopCountdownTimer() {
        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
            this.countdownInterval = null;
        }
    }

    /**
     * Submit guest email and register
     */
    async submitGuestEmail(email) {
        console.log('[UploadManager] submitGuestEmail:', email);

        // Save email to localStorage
        this.saveEmailToLocalStorage(email);

        // Update state to show loading
        this.updateState({
            guestEmailError: '',
            emailStatusCheck: {
                ...this.state.emailStatusCheck,
                checking: true,
                savedEmail: email
            }
        });
        this.render();

        try {
            // Prepare form data
            const formData = new FormData();
            formData.append('guest_email', email);  // API expects 'guest_email'
            formData.append('page_slug', this.config.pageSlug);
            formData.append('locale', this.config.locale);

            // Add conversion options
            const conversionOptions = { ...this.state.conversionOptions };
            if (this.state.coverPageEnabled) {
                conversionOptions.coverPageEnabled = true;
                if (this.state.coverTemplateId) {
                    conversionOptions.coverTemplateId = this.state.coverTemplateId;
                }
            }
            if (Object.keys(conversionOptions).length > 0) {
                console.log('[UploadManager] Sending conversion options to backend:', conversionOptions);
                formData.append('conversion_options', JSON.stringify(conversionOptions));
            }

            // Add workflow ID if present
            if (this.state.activeWorkflow && this.state.activeWorkflow.id) {
                formData.append('workflow_id', this.state.activeWorkflow.id);
            }

            // Add files
            console.log('[UploadManager] submitGuestEmail - uploadedFiles count:', this.state.uploadedFiles.length);
            let filesAdded = 0;
            this.state.uploadedFiles.forEach((file) => {
                // Include all real files (including ZIP containers)
                // Skip only virtual files extracted from ZIPs (they have file: null)
                if (file.file) {
                    formData.append('files[]', file.file);
                    filesAdded++;
                    console.log('[UploadManager] Added file to FormData:', file.name);
                }
            });
            console.log('[UploadManager] Total files added to FormData:', filesAdded);

            // For ZIP files: send selected_files to filter which files to process
            this.appendSelectedFilesFilter(formData);

            // Verify files were added
            if (filesAdded === 0) {
                throw new Error('No files to upload. Please refresh the page and try again.');
            }

            // Call guest registration API
            const response = await fetch('/api/guest/register-and-upload', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || data.message || 'Failed to submit email');
            }

            // Update state with pending status
            this.updateState({
                emailStatusCheck: {
                    checking: false,
                    savedEmail: email,
                    status: 'pending',
                    message: data.message || 'Confirmation email sent',
                    canResend: true,
                    lastSentAt: new Date().toISOString(),
                },
                batchId: data.batch_id || null,
            });

            // Save pending upload for cross-tab/navigation scenarios
            // Guest might verify email in different tab/device and navigate back
            if (data.batch_id) {
                this.savePendingUpload();
            }

            this.render();

            // Start polling for email verification (every 5 seconds)
            this.startEmailPolling();

            // Start countdown timer for resend button (updates every second)
            this.startCountdownTimer();

        } catch (error) {
            console.error('[UploadManager] submitGuestEmail failed:', error);
            this.updateState({
                guestEmailError: error.message || 'Failed to submit email',
                emailStatusCheck: {
                    ...this.state.emailStatusCheck,
                    checking: false,
                }
            });
            this.render();
        }
    }

    /**
     * Proceed with conversion (after email verified)
     */
    async proceedWithConversion() {
        console.log('[UploadManager] proceedWithConversion - Email verified, starting conversion');

        // Change status to processing
        this.updateState({
            conversionStatus: 'processing',
            isProcessing: true
        });
        this.render();

        try {
            // Prepare form data
            const formData = new FormData();
            formData.append('page_slug', this.config.pageSlug);

            // Add conversion options
            const conversionOptions = { ...this.state.conversionOptions };
            if (this.state.coverPageEnabled) {
                conversionOptions.coverPageEnabled = true;
                if (this.state.coverTemplateId) {
                    conversionOptions.coverTemplateId = this.state.coverTemplateId;
                }
            }

            // Add email attachments count for email pages (needed for credit calculation)
            const isEmailPage = this.config.pageSlug === 'email-to-pdf' ||
                               this.config.pageSlug === 'converteer-email-naar-pdf' ||
                               this.config.pageSlug === 'merge-email-to-pdf' ||
                               this.config.pageSlug === 'email-samenvoegen-naar-pdf';
            if (isEmailPage && this.state.emailAttachmentsCount > 0) {
                conversionOptions.email_attachments_count = this.state.emailAttachmentsCount;
                console.log('[UploadManager] Including email_attachments_count in conversion:', this.state.emailAttachmentsCount);
            }

            if (Object.keys(conversionOptions).length > 0) {
                console.log('[UploadManager] Sending conversion options to backend:', conversionOptions);
                formData.append('conversion_options', JSON.stringify(conversionOptions));
            }

            // Add workflow ID if present
            if (this.state.activeWorkflow && this.state.activeWorkflow.id) {
                formData.append('workflow_id', this.state.activeWorkflow.id);
            }

            // Add files
            this.state.uploadedFiles.forEach((file) => {
                // Include all real files (including ZIP containers)
                // Skip only virtual files extracted from ZIPs (they have file: null)
                if (file.file) {
                    formData.append('files[]', file.file);
                }
            });

            // For ZIP files: send selected_files to filter which files to process
            this.appendSelectedFilesFilter(formData);

            // Call authenticated upload API (user is now verified)
            const response = await fetch('/api/upload-and-convert', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            const data = await response.json();

            if (!response.ok) {
                if (response.status === 503 && data.type === 'conversion_service_error') {
                    throw {
                        isServiceError: true,
                        title: data.title || 'Service Error',
                        message: data.error,
                        reference: data.reference,
                    };
                }
                throw new Error(data.error || data.message || 'Conversion failed');
            }

            // Store execution ID and start polling
            this.state.executionId = data.execution_id;
            await this.pollConversionStatus();

        } catch (error) {
            if (error.isServiceError) {
                this.updateState({
                    conversionStatus: 'error',
                    errorMessage: `${error.title}\n\n${error.message}\n\nReference: ${error.reference}`,
                    errorReference: error.reference,
                    isProcessing: false,
                });
            } else {
                this.updateState({
                    conversionStatus: 'error',
                    errorMessage: error.message,
                    isProcessing: false,
                });
            }
            this.render();
        }
    }

    /**
     * Start conversion
     */
    async startConversion() {
        // FOR GUESTS: Check email status first
        if (this.state.isGuestUser) {
            console.log('[UploadManager] Guest user detected - checking email status...');

            // First check saved email status (don't change step yet)
            await this.checkSavedEmailStatus();

            // GATE: If email verified but login required, stay in step 2 and show banner
            if (this.state.emailStatusCheck.requiresLogin) {
                console.log('[UploadManager] Login required - showing login gate in step 2');
                this.updateState({
                    conversionStatus: 'requires_login',
                    isProcessing: false
                });
                this.render(); // Re-render to show login gate banner
                return; // STOP - user must log in
            }

            // Go to step 3 for email capture
            this.updateState({
                conversionStatus: 'awaiting_email',
                isProcessing: false
            });
            this.goToStep(3);
            this.scrollToTop();
            this.render();

            // Start polling if status is pending
            if (this.state.emailStatusCheck.status === 'pending') {
                this.startEmailPolling();
                this.startCountdownTimer(); // Also start countdown for resend button
            }

            // Stop here - conversion will continue after email verified
            return;
        }

        // FOR AUTHENTICATED USERS: Continue with normal conversion
        console.log('[UploadManager] Authenticated user - starting conversion immediately');

        this.updateState({
            conversionStatus: 'processing',
            isProcessing: true
        });
        this.goToStep(3);

        // Scroll to top of upload component to see processing status
        this.scrollToTop();

        try {
            const formData = new FormData();
            formData.append('page_slug', this.config.pageSlug);

            // Prepare conversion options with cover page data
            const conversionOptions = { ...this.state.conversionOptions };

            // Add cover page data to conversion options
            if (this.state.coverPageEnabled) {
                conversionOptions.coverPageEnabled = true;
                if (this.state.coverTemplateId) {
                    conversionOptions.coverTemplateId = this.state.coverTemplateId;
                }
            }

            // Add email attachments count for email pages (needed for credit calculation)
            const isEmailPage = this.config.pageSlug === 'email-to-pdf' ||
                               this.config.pageSlug === 'converteer-email-naar-pdf' ||
                               this.config.pageSlug === 'merge-email-to-pdf' ||
                               this.config.pageSlug === 'email-samenvoegen-naar-pdf';
            if (isEmailPage && this.state.emailAttachmentsCount > 0) {
                conversionOptions.email_attachments_count = this.state.emailAttachmentsCount;
                console.log('[UploadManager] Including email_attachments_count in conversion:', this.state.emailAttachmentsCount);
            }

            // Add conversion options if present
            if (Object.keys(conversionOptions).length > 0) {
                console.log('[UploadManager] Sending conversion options to backend:', conversionOptions);
                formData.append('conversion_options', JSON.stringify(conversionOptions));
            }

            // Add workflow ID if present
            if (this.state.activeWorkflow && this.state.activeWorkflow.id) {
                formData.append('workflow_id', this.state.activeWorkflow.id);
            }

            // Use pre-uploaded batch if available, otherwise upload files directly
            if (this.state.preUploadedBatchId) {
                console.log('[UploadManager] Using pre-uploaded batch:', this.state.preUploadedBatchId);
                formData.append('source_batch_id', this.state.preUploadedBatchId);
            } else {
                // Upload files directly
                this.state.uploadedFiles.forEach((file) => {
                    // Include all real files (including ZIP containers)
                    // Skip only virtual files extracted from ZIPs (they have file: null)
                    if (file.file) {
                        formData.append('files[]', file.file);
                    }
                });
            }

            // For ZIP files: send selected_files to filter which files to process
            this.appendSelectedFilesFilter(formData);

            const response = await fetch('/api/upload-and-convert', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            const data = await response.json();

            if (!response.ok) {
                // Check if this is a ConvertAPI service error
                if (response.status === 503 && data.type === 'conversion_service_error') {
                    throw {
                        isServiceError: true,
                        title: data.title || 'Service Error',
                        message: data.error,
                        reference: data.reference,
                    };
                }

                throw new Error(data.error || data.message || 'Conversion failed');
            }

            // Store execution ID and start polling for status
            this.state.executionId = data.execution_id;
            await this.pollConversionStatus();

        } catch (error) {
            // Display service errors with title and reference
            if (error.isServiceError) {
                this.updateState({
                    conversionStatus: 'error',
                    errorMessage: `${error.title}\n\n${error.message}\n\nReference: ${error.reference}`,
                    errorReference: error.reference,
                    isProcessing: false,
                });
            } else {
                this.updateState({
                    conversionStatus: 'error',
                    errorMessage: error.message,
                    isProcessing: false,
                });
            }
        }
    }

    /**
     * Poll conversion status until done or error
     */
    async pollConversionStatus() {
        const maxAttempts = 120; // 2 minutes at 1 second intervals
        let attempts = 0;

        const poll = async () => {
            try {
                const response = await fetch(`/api/workflow-execution/${this.state.executionId}/status`, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    }
                });

                if (!response.ok) {
                    throw new Error('Status check failed');
                }

                const data = await response.json();

                if (data.status === 'done') {
                    // Conversion complete - clear pending upload
                    this.clearPendingUpload();

                    console.log('[UploadManager] Conversion done. API Response:', data);
                    console.log('[UploadManager] Batch ID from response:', data.batch_id);

                    const translations = this.config.translations || {};
                    const downloadText = data.file_size
                        ? (translations.download_with_size || 'Download (:size)').replace(':size', data.file_size)
                        : (translations.download || 'Download');

                    this.updateState({
                        conversionStatus: 'done',
                        downloadUrl: data.download_url,
                        batchId: data.batch_id,
                        isProcessing: false,
                        downloadButtonText: downloadText
                    });
                    return;
                } else if (data.status === 'error') {
                    // Conversion failed
                    throw new Error(data.error_message || 'Conversion failed');
                } else if (data.status === 'processing' && attempts < maxAttempts) {
                    // Still processing, poll again
                    attempts++;
                    setTimeout(poll, 1000); // Poll every 1 second
                } else if (attempts >= maxAttempts) {
                    // Timeout
                    throw new Error('Conversion timeout. Please try again.');
                }
            } catch (error) {
                this.updateState({
                    conversionStatus: 'error',
                    errorMessage: error.message,
                    isProcessing: false,
                });
            }
        };

        // Start polling
        await poll();
    }

    /**
     * Reset upload
     */
    resetUpload() {
        // Clear pending upload from localStorage
        this.clearPendingUpload();

        this.state.uploadedFiles = [];
        this.state.selectedFileIndex = 0;
        this.state.errorMessage = null;
        this.state.errorSuggestions = [];
        this.state.conversionStatus = 'ready';
        this.state.batchId = null;
        this.state.preUploadedBatchId = null; // Clear pre-upload batch
        this.goToStep(1);
    }

    /**
     * Render debug panel (shown when localStorage.vanilla_upload_debug = 'true')
     */
    renderDebugPanel() {
        return `
            <!-- Debug Panel -->
            <div class="w-1/4 bg-purple-50 border-2 border-purple-200 rounded-lg p-4 overflow-y-auto max-h-[600px]">
                <h3 class="font-bold text-purple-900 mb-4">Debug Information</h3>

                <div class="space-y-3 text-xs">
                    <div>
                        <div class="font-semibold text-purple-800">Page:</div>
                        <div class="text-purple-600">${this.config.pageSlug}</div>
                    </div>

                    <div>
                        <div class="font-semibold text-purple-800">Max Files:</div>
                        <div class="text-purple-600">${this.config.maxFiles}</div>
                    </div>

                    <div>
                        <div class="font-semibold text-purple-800">Max Size:</div>
                        <div class="text-purple-600">${this.formatFileSize(this.config.maxTotalSize)}</div>
                    </div>

                    <div>
                        <div class="font-semibold text-purple-800">Allowed Types:</div>
                        <div class="text-purple-600">${this.config.allowedExtensions.join(', ')}</div>
                    </div>

                    <hr class="border-purple-200">

                    <div>
                        <div class="font-semibold text-purple-800 mb-1">Live State:</div>
                    </div>

                    <div>
                        <div class="font-semibold text-purple-800">Current Step:</div>
                        <div class="text-purple-600">${this.state.currentStep}</div>
                    </div>

                    <div>
                        <div class="font-semibold text-purple-800">Conversion Status:</div>
                        <div class="text-purple-600">${this.state.conversionStatus}</div>
                    </div>

                    <div>
                        <div class="font-semibold text-purple-800">Execution ID:</div>
                        <div class="text-purple-600">${this.state.executionId || '–'}</div>
                    </div>

                    <div>
                        <div class="font-semibold text-purple-800">Batch ID:</div>
                        <div class="text-purple-600">${this.state.batchId || 'null'}</div>
                    </div>

                    <div>
                        <div class="font-semibold text-purple-800">Is Guest:</div>
                        <div class="text-purple-600">${this.state.isGuestUser ? 'Yes' : 'No'}</div>
                    </div>

                    <div>
                        <div class="font-semibold text-purple-800">Files Uploaded:</div>
                        <div class="text-purple-600">${this.state.uploadedFiles.filter(f => !f.isZipContainer).length}</div>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Format file size
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    /**
     * Append selected_files filter to FormData for ZIP filtering
     * This allows backend to only process the files the user has selected
     */
    appendSelectedFilesFilter(formData) {
        const selectedFilesFromZip = this.state.uploadedFiles
            .filter(f => f.isFromZip)
            .map(f => f.name);

        if (selectedFilesFromZip.length > 0) {
            formData.append('selected_files', JSON.stringify(selectedFilesFromZip));
            console.log('[UploadManager] Sending selected_files filter:', selectedFilesFromZip);
        }
    }

    /**
     * Truncate filename while preserving extension
     * Example: "this is a very long filename.pdf" -> "this is a ver....pdf"
     */
    truncateFileName(filename, maxLength = 35) {
        if (!filename || filename.length <= maxLength) {
            return filename;
        }

        const lastDot = filename.lastIndexOf('.');
        if (lastDot === -1) {
            // No extension - simple truncate
            return filename.substring(0, maxLength - 3) + '...';
        }

        const extension = filename.substring(lastDot); // includes the dot
        const name = filename.substring(0, lastDot);

        // Reserve space for extension + "..."
        const availableLength = maxLength - extension.length - 3;

        if (availableLength <= 0) {
            // Extension is too long, just truncate everything
            return filename.substring(0, maxLength - 3) + '...';
        }

        return name.substring(0, availableLength) + '...' + extension;
    }

    /**
     * Get file icon path based on extension
     */
    getFileIconPath(extension) {
        const ext = extension.toLowerCase();

        // Icon aliases - map extensions to existing icons
        const iconAliases = {
            'dwf': 'dwg',      // DWF uses DWG icon (both AutoCAD)
            'dwfx': 'dwg',     // DWFX uses DWG icon
            'djvu': 'epub',    // DJVU uses EPUB icon (both ebooks)
            'wpd': 'doc',      // WordPerfect uses Word icon
            'xlsb': 'xls',     // Excel Binary uses Excel icon
            'odt': 'doc',      // OpenDocument Text uses Word icon
            'ods': 'xls',      // OpenDocument Spreadsheet uses Excel icon
            'odp': 'ppt',      // OpenDocument Presentation uses PowerPoint icon
            'odg': 'svg',      // OpenDocument Graphics uses SVG icon
            'heic': 'jpg',     // HEIC uses JPG icon
            'heif': 'jpg',     // HEIF uses JPG icon
            'jpeg': 'jpg',     // JPEG uses JPG icon
            'htm': 'html',     // HTM uses HTML icon
        };

        // List of available icons (downloaded from file-icon-vectors)
        const availableIcons = [
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'pdf',
            'txt', 'log', 'csv', 'html', 'htm', 'dwg', 'dxf',
            'eps', 'ps', 'pub', 'rtf', 'epub', 'mobi', 'vsd',
            'vsdx', 'md', 'eml', 'msg', 'jpg', 'png', 'gif',
            'svg', 'webp', 'tiff', 'zip', 'heic', 'dotx', 'docm', 'xltx'
        ];

        // Check if extension has an alias
        const mappedExt = iconAliases[ext] || ext;

        // Return specific icon if available, otherwise use blank fallback
        const iconFile = availableIcons.includes(mappedExt) ? mappedExt : 'blank';
        return `/images/file-icons/${iconFile}.svg`;
    }

    /**
     * Get human-readable file type name
     */
    getFileTypeName(extension) {
        const ext = extension.toLowerCase();

        const typeNames = {
            // Office
            'doc': 'Word Document',
            'docx': 'Word Document',
            'xls': 'Excel Spreadsheet',
            'xlsx': 'Excel Spreadsheet',
            'ppt': 'PowerPoint Presentation',
            'pptx': 'PowerPoint Presentation',

            // PDF
            'pdf': 'PDF Document',

            // Text
            'txt': 'Text Document',
            'log': 'Log File',
            'csv': 'CSV File',
            'rtf': 'Rich Text Format',
            'md': 'Markdown Document',

            // Web
            'html': 'HTML Document',
            'htm': 'HTML Document',

            // Images
            'jpg': 'JPEG Image',
            'jpeg': 'JPEG Image',
            'png': 'PNG Image',
            'gif': 'GIF Image',
            'svg': 'SVG Image',
            'webp': 'WebP Image',
            'tiff': 'TIFF Image',
            'heic': 'HEIC Image',

            // CAD
            'dwg': 'AutoCAD Drawing',
            'dxf': 'AutoCAD DXF',
            'dwf': 'Design Web Format',

            // Other
            'eps': 'PostScript File',
            'ps': 'PostScript File',
            'pub': 'Publisher Document',
            'epub': 'EPUB eBook',
            'mobi': 'Mobi eBook',
            'djvu': 'DjVu Document',
            'vsd': 'Visio Drawing',
            'vsdx': 'Visio Drawing',
            'eml': 'Email Message',
            'msg': 'Outlook Message',
            'zip': 'ZIP Archive',

            // OpenDocument
            'odt': 'OpenDocument Text',
            'ods': 'OpenDocument Spreadsheet',
            'odp': 'OpenDocument Presentation',
            'odg': 'OpenDocument Graphics',
        };

        return typeNames[ext] || ext.toUpperCase() + ' File';
    }

    /**
     * Scroll to top of component
     */
    scrollToTop() {
        // Scroll the container into view
        this.config.container.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }

    /**
     * Show error
     */
    showError(message, suggestions = []) {
        this.updateState({
            errorMessage: message,
            errorSuggestions: suggestions,
        });
        this.config.onError({ message, suggestions });
    }

    /**
     * Clear error
     */
    clearError() {
        this.updateState({
            errorMessage: null,
            errorSuggestions: [],
        });
    }

    /**
     * Get current state (for debugging)
     */
    getState() {
        return this.state;
    }

    /**
     * Get translation by key
     */
    t(key, fallback = '') {
        return this.config.translations[key] || fallback;
    }

    /**
     * Get context-aware convert button text
     *
     * Dynamic button text based on action type, output format, and file count
     */
    getConvertButtonText() {
        const fileCount = this.state.uploadedFiles.filter(f => !f.isZipContainer).length;
        const actionType = this.config.actionType; // 'merge', 'convert', 'optimize', 'organize'
        const outputFormat = this.config.outputFormat; // 'pdf', 'docx', 'xlsx', etc.
        const outputFormatDisplay = this.config.outputFormatDisplay || outputFormat.toUpperCase();

        // Scenario 4: Optimize/Organize - always simple
        if (actionType === 'optimize' || actionType === 'organize') {
            return this.t('convert_button_simple', 'Convert');
        }

        // Scenario 1: Merge - always multiple files
        if (actionType === 'merge') {
            return this.t('convert_button_merge', 'Merge into 1 :format')
                .replace(':format', outputFormatDisplay);
        }

        // Scenario 2 & 3: Convert (TO PDF or FROM PDF)
        if (actionType === 'convert') {
            const toPdf = outputFormat === 'pdf';
            const isImageOutput = ['jpg', 'jpeg', 'png', 'webp', 'tiff'].includes(outputFormat?.toLowerCase());
            const isWordOutput = ['doc', 'docx', 'rtf', 'odt'].includes(outputFormat?.toLowerCase());

            if (fileCount === 1) {
                // Enkelvoud
                if (toPdf) {
                    return this.t('convert_button_to_pdf_single', 'Convert to PDF');
                } else if (isImageOutput || isWordOutput) {
                    // Voor afbeeldingen en Word: alleen "Converteer" zonder formaat
                    return this.t('convert_button', 'Convert');
                } else {
                    return this.t('convert_button_from_pdf_single', 'Convert to :format')
                        .replace(':format', outputFormatDisplay);
                }
            } else {
                // Meervoud
                if (toPdf) {
                    return this.t('convert_button_to_pdf_multiple', 'Convert each file to PDF');
                } else if (isImageOutput || isWordOutput) {
                    // Voor afbeeldingen en Word: alleen "Converteer" zonder formaat
                    return this.t('convert_button', 'Convert');
                } else {
                    return this.t('convert_button_from_pdf_multiple', 'Convert each PDF to :format')
                        .replace(':format', outputFormatDisplay);
                }
            }
        }

        // Fallback
        return this.t('convert_now', 'Convert Now');
    }

    /**
     * Create share link for batch
     */
    async createShareLink() {
        console.log('[UploadManager] createShareLink called');
        console.log('[UploadManager] Current state.batchId:', this.state.batchId);
        console.log('[UploadManager] Full state:', this.state);

        if (!this.state.batchId) {
            console.error('[UploadManager] No batch ID available for sharing');
            alert('No batch ID available. Please wait for the conversion to complete.');
            return;
        }

        try {
            const url = `/share/create/${this.state.batchId}`;
            console.log('[UploadManager] Calling share API:', url);

            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                }
            });

            console.log('[UploadManager] Share API response status:', response.status);
            const data = await response.json();
            console.log('[UploadManager] Share API response data:', data);

            if (data.success) {
                console.log('[UploadManager] Share link created successfully');

                // Use vanilla share modal with retry mechanism
                const openShareModal = (retries = 0) => {
                    if (window.vanillaShareModal) {
                        window.vanillaShareModal.open(data.share_url, data);
                    } else if (retries < 10) {
                        // Wait 100ms and retry (up to 1 second total)
                        console.log('[UploadManager] Waiting for share modal...', retries + 1);
                        setTimeout(() => openShareModal(retries + 1), 100);
                    } else {
                        console.error('[UploadManager] Vanilla share modal not initialized after retries');
                        // Fallback: show share URL in alert
                        prompt('Share link:', data.share_url);
                    }
                };
                openShareModal();
            } else {
                console.error('[UploadManager] Share failed:', data);
                alert(data.error || 'Could not create share link');
            }
        } catch (error) {
            console.error('[UploadManager] Share link error:', error);
            alert('Could not create share link: ' + error.message);
        }
    }

    /**
     * Render feedback widget (inline, no modal)
     */
    renderFeedbackWidget() {
        // Only show for authenticated users
        if (!this.config.feedbackEnabled) {
            return '';
        }

        const translations = this.config.translations || {};
        const { feedbackThumb, feedbackContent, feedbackSubmitting, feedbackSubmitted, feedbackError } = this.state;

        // If already submitted, show thank you
        if (feedbackSubmitted) {
            return `
                <div class="mt-10 pt-6 border-t border-gray-200">
                    <div class="flex items-center justify-center gap-2 text-green-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        <span>${translations.feedback_thank_you || 'Thank you for your feedback!'}</span>
                    </div>
                </div>
            `;
        }

        return `
            <div class="mt-10 pt-6 border-t border-gray-200">
                <div class="flex flex-wrap items-start justify-center gap-6">
                    <!-- Thumb buttons -->
                    <div class="flex flex-col items-center">
                        <p class="text-gray-600 mb-3 text-sm">${translations.feedback_question || 'How do you like our service?'}</p>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                data-feedback-thumb="up"
                                class="w-16 h-16 flex items-center justify-center border-2 rounded-lg transition-all duration-200 text-3xl ${feedbackThumb === 'up' ? 'border-green-500 bg-green-50' : 'border-gray-200 hover:border-green-300'}"
                            >👍</button>
                            <button
                                type="button"
                                data-feedback-thumb="down"
                                class="w-16 h-16 flex items-center justify-center border-2 rounded-lg transition-all duration-200 text-3xl ${feedbackThumb === 'down' ? 'border-red-500 bg-red-50' : 'border-gray-200 hover:border-red-300'}"
                            >👎</button>
                        </div>
                    </div>

                    <!-- Feedback form (shows after thumb selection) -->
                    ${feedbackThumb ? `
                        <div class="flex-1 min-w-[280px] max-w-md text-left">
                            <p class="text-gray-700 font-medium mb-2">
                                ${feedbackThumb === 'up'
                                    ? (translations.feedback_prompt_positive || 'What do you like?') + ' 👍'
                                    : (translations.feedback_prompt_negative || 'What could be better?') + ' 👎'}
                            </p>
                            <textarea
                                data-feedback-content
                                maxlength="500"
                                rows="3"
                                class="w-full border border-gray-300 rounded-lg p-3 text-sm text-gray-900 bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-y"
                                placeholder="${feedbackThumb === 'up'
                                    ? (translations.feedback_placeholder_positive || 'Tell us what you liked... (optional)')
                                    : (translations.feedback_placeholder_negative || 'Tell us what we can improve... (optional)')}"
                            >${feedbackContent}</textarea>
                            <div class="flex items-center justify-between mt-2">
                                <button
                                    type="button"
                                    data-feedback-submit
                                    ${feedbackSubmitting ? 'disabled' : ''}
                                    class="px-6 py-2 bg-white border-2 border-blue-500 text-blue-600 rounded-full hover:bg-blue-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed font-medium"
                                >
                                    ${feedbackSubmitting
                                        ? (translations.feedback_submitting || 'Submitting...')
                                        : (translations.feedback_submit || 'Submit')}
                                </button>
                                <span class="text-sm text-gray-500">
                                    <span data-feedback-char-count>${feedbackContent.length}</span>/500 ${translations.feedback_characters || 'characters'}
                                </span>
                            </div>
                            ${feedbackError ? `
                                <div class="mt-2 text-red-600 text-sm">${feedbackError}</div>
                            ` : ''}
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    }

    /**
     * Render next step options for PDF output
     * Only shown for PDF output and authenticated users
     */
    renderNextStepOptions() {
        // Only show for authenticated users with a batchId
        if (this.state.isGuestUser || !this.state.batchId) {
            return '';
        }

        const translations = this.config.translations || {};
        const locale = this.config.locale || 'en';
        const currentSlug = this.config.pageSlug;

        // Check if this is a PDF output page based on slug patterns
        // Pages that output PDF: X-to-pdf, compress-pdf, split-pdf, merge-pdf, ocr-pdf, pdfa-pdf, protect-pdf, etc.
        const pdfOutputPatterns = [
            '-to-pdf', '-naar-pdf',  // X to PDF conversions
            'compress-pdf', 'pdf-verkleinen',
            'pdfs-to-pdf', 'pdf-samenvoegen',
            'pdf-to-split', 'pdf-splitsen',
            'ocr-pdf',
            'pdfa-pdf',
            'pdf-to-protect', 'pdf-beveiligen'
        ];
        const isPdfOutput = pdfOutputPatterns.some(pattern => currentSlug && currentSlug.includes(pattern));

        if (!isPdfOutput) {
            return '';
        }

        // Define next step options with localized slugs and labels
        const nextStepOptions = [
            {
                slug: locale === 'nl' ? 'pdf-verkleinen' : 'compress-pdf',
                label: locale === 'nl' ? 'PDF comprimeren' : 'Compress PDF',
                icon: '🗜️',
                iconText: 'PDF',
                iconColor: '#10B981'
            },
            {
                slug: locale === 'nl' ? 'pdf-samenvoegen' : 'pdfs-to-pdf',
                label: locale === 'nl' ? 'PDF samenvoegen' : 'Merge PDF',
                icon: '📑',
                iconText: 'PDF',
                iconColor: '#F59E0B'
            },
            {
                slug: locale === 'nl' ? 'pdf-splitsen' : 'pdf-to-split',
                label: locale === 'nl' ? 'PDF splitsen' : 'Split PDF',
                icon: '✂️',
                iconText: 'PDF',
                iconColor: '#8B5CF6'
            },
            {
                slug: 'ocr-pdf',
                label: 'OCR PDF',
                icon: '🔍',
                iconText: 'OCR',
                iconColor: '#3B82F6'
            },
            {
                slug: locale === 'nl' ? 'pdfa-pdf' : 'pdfa-pdf',
                label: locale === 'nl' ? 'PDF naar PDF/A' : 'Convert to PDF/A',
                icon: '📄',
                iconText: 'A',
                iconColor: '#EF4444'
            },
            {
                slug: locale === 'nl' ? 'pdf-beveiligen' : 'pdf-to-protect',
                label: locale === 'nl' ? 'PDF beveiligen' : 'Protect PDF',
                icon: '🔒',
                iconText: 'PDF',
                iconColor: '#6366F1'
            }
        ];

        // Filter out current conversion type
        const filteredOptions = nextStepOptions.filter(opt => {
            // Check against both NL and EN slugs
            const nlMapping = {
                'pdf-verkleinen': 'compress-pdf',
                'pdf-samenvoegen': 'pdfs-to-pdf',
                'pdf-splitsen': 'pdf-to-split',
                'pdf-beveiligen': 'pdf-to-protect'
            };
            const enSlug = nlMapping[opt.slug] || opt.slug;
            const nlSlug = Object.keys(nlMapping).find(k => nlMapping[k] === opt.slug) || opt.slug;

            return currentSlug !== opt.slug && currentSlug !== enSlug && currentSlug !== nlSlug;
        });

        // Take first 6 options
        const displayOptions = filteredOptions.slice(0, 6);

        if (displayOptions.length === 0) {
            return '';
        }

        const continueLabel = locale === 'nl' ? 'Ga verder met...' : 'Continue to...';
        const seeMoreLabel = locale === 'nl' ? 'Meer opties' : 'See more';
        const nextStepUrl = `/${locale}/next-step/batch/${this.state.batchId}`;

        return `
            <div class="mt-10 pt-8 border-t border-gray-100">
                <h4 class="text-lg font-semibold text-gray-900 mb-4">${continueLabel}</h4>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    ${displayOptions.map(opt => `
                        <a href="/${locale}/${opt.slug}?batch=${this.state.batchId}&next_step=true"
                           class="flex items-center gap-3 p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition group">
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                                 style="background-color: ${opt.iconColor}20;">
                                <span class="text-sm font-bold" style="color: ${opt.iconColor};">${opt.iconText}</span>
                            </div>
                            <span class="text-sm font-medium text-gray-700 group-hover:text-gray-900">${opt.label}</span>
                            <svg class="w-4 h-4 text-gray-400 group-hover:text-gray-600 ml-auto flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </a>
                    `).join('')}
                </div>
                <div class="mt-4 text-right">
                    <a href="${nextStepUrl}" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                        ${seeMoreLabel} →
                    </a>
                </div>
            </div>
        `;
    }

    /**
     * Handle feedback thumb selection
     */
    selectFeedbackThumb(thumb) {
        this.updateState({
            feedbackThumb: thumb,
            feedbackSubmitted: false,
            feedbackError: null,
        });
    }

    /**
     * Handle feedback content change
     */
    updateFeedbackContent(content) {
        this.state.feedbackContent = content;
        // Update character count display
        const charCount = this.config.container.querySelector('[data-feedback-char-count]');
        if (charCount) {
            charCount.textContent = content.length;
        }
    }

    /**
     * Submit feedback
     */
    async submitFeedback() {
        if (this.state.feedbackSubmitting) return;

        this.updateState({
            feedbackSubmitting: true,
            feedbackError: null,
        });

        try {
            const response = await fetch(this.config.feedbackUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.config.csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    thumb: this.state.feedbackThumb,
                    content: this.state.feedbackContent,
                    converter_type: this.config.pageSlug,
                    page_url: window.location.href,
                }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                this.updateState({
                    feedbackSubmitted: true,
                    feedbackSubmitting: false,
                    feedbackContent: '',
                });
                console.log('[UploadManager] Feedback submitted successfully');
            } else {
                this.updateState({
                    feedbackSubmitting: false,
                    feedbackError: data.message || this.config.translations.feedback_error || 'Something went wrong',
                });
            }
        } catch (error) {
            console.error('[UploadManager] Feedback submission error:', error);
            this.updateState({
                feedbackSubmitting: false,
                feedbackError: this.config.translations.feedback_error || 'Something went wrong',
            });
        }
    }
}
