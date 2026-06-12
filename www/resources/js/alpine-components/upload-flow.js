/**
 * Upload Flow V2 Alpine Component
 * Main component for handling file upload and conversion flow
 */

import { UploadUtils } from './upload-utils.js';
import { UploadAPI } from './upload-api.js';
import { PreviewHandler } from './preview-handler.js';

export function uploadFlowV2(pageSlug, pageConfig) {
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

        // Email Status Check (for returning guests)
        emailStatusCheck: {
            checking: false,
            savedEmail: null,
            status: null,    // 'pending', 'verified', 'not_found'
            message: null,
            canResend: false,
            lastSentAt: null
        },

        // File Limits
        fileLimit: {
            current: 0,      // Current number of files
            limit: 50,       // Max allowed (from license)
            valid: true,     // Whether limit is OK
            excess: 0        // How many over limit
        },

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
                   this.fileLimit.valid &&
                   (!this.isGuestUser || this.guestEmailCaptured) &&
                   !this.creditsError;
        },

        get canResendEmail() {
            if (!this.emailStatusCheck.canResend) return false;

            const lastSent = this.emailStatusCheck.lastSentAt;
            if (!lastSent) return true;

            const secondsSince = (Date.now() - new Date(lastSent).getTime()) / 1000;
            return secondsSince >= 60;
        },

        get resendCountdown() {
            const lastSent = this.emailStatusCheck.lastSentAt;
            if (!lastSent) return 0;

            const secondsSince = (Date.now() - new Date(lastSent).getTime()) / 1000;
            return Math.max(0, Math.ceil(60 - secondsSince));
        },

        // Lifecycle
        async init() {
            // Initialize file limit from pageConfig
            if (this.pageConfig.limits && this.pageConfig.limits.max_files) {
                this.fileLimit.limit = this.pageConfig.limits.max_files;
            }

            await this.loadUserCredits();
            await this.loadUserWorkflows();

            // Clear saved email if user is authenticated
            if (!this.isGuestUser) {
                localStorage.removeItem('app_guest_email');
                localStorage.removeItem('app_guest_email_saved_at');
            }
        },

        // User & Credits Methods
        async loadUserCredits() {
            // Skip API call if we already know this is a guest user
            // (isGuestUser is set via server-side rendering in the Blade template)
            if (this.isGuestUser) {
                return;
            }

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

            // Skip credits validation if file limit exceeded (prioritize file limit error)
            if (!this.fileLimit.valid) {
                this.creditsError = null;
                this.creditsInfo = null;
                return;
            }

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

        // Email Status Check Methods
        async checkSavedEmailStatus() {
            console.log('[checkSavedEmailStatus] Starting, isGuestUser:', this.isGuestUser);

            // Only for guests
            if (!this.isGuestUser) {
                console.log('[checkSavedEmailStatus] Not a guest, returning');
                return;
            }

            // Check localStorage for saved email
            const savedEmail = localStorage.getItem('app_guest_email');
            console.log('[checkSavedEmailStatus] localStorage email:', savedEmail);

            if (!savedEmail) {
                // No saved email - show normal email form
                this.emailStatusCheck.status = 'not_found';
                console.log('[checkSavedEmailStatus] No savedEmail, status set to not_found');
                return;
            }

            this.emailStatusCheck.checking = true;
            this.emailStatusCheck.savedEmail = savedEmail;
            console.log('[checkSavedEmailStatus] Checking email status via API...');

            // ALWAYS make fresh API call (no caching for cross-device sync)
            const data = await UploadAPI.checkEmailStatus(savedEmail);

            if (data) {
                this.emailStatusCheck.status = data.status;
                this.emailStatusCheck.message = data.message;
                this.emailStatusCheck.canResend = data.can_resend;
                this.emailStatusCheck.lastSentAt = data.last_sent_at;

                // Pre-fill email field if status is not_found
                if (data.status === 'not_found') {
                    this.guestEmail = savedEmail;
                }
            } else {
                // API call failed - reset to show normal email form
                console.warn('Email status check failed, showing normal email form');
                this.emailStatusCheck.savedEmail = null;
                this.emailStatusCheck.status = 'not_found';
                this.guestEmail = savedEmail; // Pre-fill the email
            }

            this.emailStatusCheck.checking = false;
        },

        saveEmailToLocalStorage(email) {
            localStorage.setItem('app_guest_email', email);
            localStorage.setItem('app_guest_email_saved_at', Date.now());
        },

        clearSavedEmail() {
            localStorage.removeItem('app_guest_email');
            localStorage.removeItem('app_guest_email_saved_at');
            this.emailStatusCheck = {
                checking: false,
                savedEmail: null,
                status: null,
                message: null,
                canResend: false,
                lastSentAt: null
            };
            this.guestEmail = '';
        },

        async resendConfirmationEmail() {
            const email = this.emailStatusCheck.savedEmail;
            if (!email) return;

            const data = await UploadAPI.resendConfirmation(email);

            if (data && data.success) {
                this.emailStatusCheck.message = data.message;
                this.emailStatusCheck.lastSentAt = new Date().toISOString();
                this.emailStatusCheck.canResend = false;
            } else {
                alert(data?.message || 'Failed to resend confirmation email');
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
            this.validateFileLimit();
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
        validateFileLimit() {
            // Count all files (including ZIP contents, excluding ZIP containers)
            const totalFiles = this.uploadedFiles.filter(f => !f.isZipContainer).length;

            this.fileLimit.current = totalFiles;
            this.fileLimit.valid = totalFiles <= this.fileLimit.limit;
            this.fileLimit.excess = Math.max(0, totalFiles - this.fileLimit.limit);
        },

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

            this.validateFileLimit();
            this.validateCredits();
        },

        // Conversion Methods
        async startConversion() {
            // Always move to Step 3 (for guests, show email capture form)
            this.currentStep = 3;

            console.log('[startConversion] Step 3, isGuestUser:', this.isGuestUser, 'guestEmailCaptured:', this.guestEmailCaptured);

            // For guest users, wait for email capture before starting actual conversion
            if (this.isGuestUser && !this.guestEmailCaptured) {
                // Check for saved email status
                await this.checkSavedEmailStatus();

                console.log('[startConversion] After check, status:', this.emailStatusCheck.status);
                console.log('[startConversion] savedEmail:', this.emailStatusCheck.savedEmail);
                console.log('[startConversion] conversionStatus will be set to ready');

                // If verified status, user must log in - don't auto-convert
                if (this.emailStatusCheck.status === 'verified') {
                    this.conversionStatus = 'ready';
                    return;
                }

                // If pending or not_found, show appropriate UI but don't start conversion yet
                this.conversionStatus = 'ready';
                return;
            }

            // For authenticated users or guests with captured email, proceed with conversion
            this.isProcessing = true;
            this.conversionStatus = 'processing';

            try {
                // Get only real files (not virtual files from ZIP)
                const realFiles = this.uploadedFiles.filter(f => f.file !== null && !f.isFromZip);

                let data;

                // Use different API endpoints for guest vs authenticated users
                if (this.isGuestUser) {
                    // Guest users: use guest registration API
                    data = await UploadAPI.uploadAndConvertGuest(
                        this.pageSlug,
                        realFiles,
                        this.guestEmail,
                        this.activeWorkflow?.id,
                        this.conversionOptions
                    );

                    // For guest users, set status to awaiting confirmation and start polling
                    this.conversionStatus = 'awaiting_confirmation';
                    this.batchId = data.batch_id;
                    this.pollBatchStatus();
                } else {
                    // Authenticated users: use standard upload API
                    data = await UploadAPI.uploadAndConvert(
                        this.pageSlug,
                        realFiles,
                        this.activeWorkflow?.id,
                        this.conversionOptions
                    );

                    this.workflowExecutionId = data.execution_id;
                    this.pollConversionStatus();
                }
            } catch (error) {
                this.conversionStatus = 'error';

                // Handle "should login" error for guests
                if (error.shouldLogin) {
                    this.errorMessage = error.message;
                    this.errorSuggestions = ['Please log in to your existing account', 'Or use a different email address'];
                } else {
                    this.errorMessage = error.message || 'Upload failed';
                }
            } finally {
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

            // Save email to localStorage for future visits
            this.saveEmailToLocalStorage(this.guestEmail);

            // Now start the actual conversion
            await this.startConversion();
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
                    this.batchId = data.batch_id || null;

                    console.log('Conversion complete, batch_id:', this.batchId);

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

        pollBatchStatus() {
            this.pollingInterval = UploadAPI.pollBatchStatus(
                this.batchId,
                (data) => {
                    // Update callback - status changed
                    if (data.status === 'processing') {
                        console.log('Email confirmed, conversion in progress');
                        this.conversionStatus = 'processing';
                    }
                },
                (data) => {
                    // Complete callback
                    console.log('Batch conversion complete:', data);
                    this.conversionStatus = 'done';
                    this.downloadUrl = data.download_url;
                    this.resultFileSize = data.file_size;
                },
                (error) => {
                    // Error callback
                    console.error('Batch polling error:', error);
                    this.conversionStatus = 'error';
                    this.errorMessage = error;
                }
            );
        },

        retryConversion() {
            this.conversionStatus = 'processing';
            this.startConversion();
        },

        async createShareLink() {
            if (!this.batchId) {
                console.error('No batch ID available for sharing');
                alert('Batch ID is not available yet. Please try again in a moment.');
                return;
            }

            try {
                const response = await fetch(`/share/create/${this.batchId}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                const data = await response.json();

                if (data.success) {
                    // Wait for Alpine to be ready and open share modal
                    if (typeof Alpine !== 'undefined' && Alpine.store) {
                        const shareModalStore = Alpine.store('shareModal');
                        if (shareModalStore) {
                            shareModalStore.open(data.share_url, data);
                        } else {
                            console.error('Share modal store not found');
                            alert('Share modal is not available. Please refresh the page.');
                        }
                    } else {
                        console.error('Alpine not loaded yet');
                        alert('Page is still loading. Please try again in a moment.');
                    }
                } else {
                    alert(data.error || 'Failed to create share link');
                }
            } catch (error) {
                console.error('Failed to create share link:', error);
                alert('Failed to create share link. Please try again.');
            }
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