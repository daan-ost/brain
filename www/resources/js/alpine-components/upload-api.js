/**
 * Upload Flow API Service
 * Handles all API communication for the upload flow
 */

export const UploadAPI = {
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
            // Silently handle 401 (guest users don't have credits)
            if (response.status === 401) {
                return null;
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
     * Check if user's email is verified
     */
    async checkEmailVerified() {
        try {
            const response = await fetch('/api/user/email-verified');
            if (response.ok) {
                return await response.json();
            }
            return { verified: false };
        } catch (error) {
            console.error('Email verification check error:', error);
            return { verified: false };
        }
    },

    /**
     * Check email status for guest user flow
     * Returns whether email exists, is verified, and can resend confirmation
     */
    async checkEmailStatus(email) {
        try {
            const response = await fetch(`/api/check-email-status?email=${encodeURIComponent(email)}`);
            if (response.ok) {
                return await response.json();
            }
            console.warn('Email status check failed with status:', response.status);
            return null;
        } catch (error) {
            console.error('Email status check error:', error);
            return null;
        }
    },

    /**
     * Resend confirmation email for guest user
     */
    async resendConfirmation(email) {
        try {
            const response = await fetch('/api/guest/resend-confirmation', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.getCsrfToken()
                },
                body: JSON.stringify({ email })
            });

            if (response.ok) {
                return await response.json();
            }

            const errorData = await response.json().catch(() => ({}));
            return {
                success: false,
                message: errorData.message || 'Failed to resend confirmation email'
            };
        } catch (error) {
            console.error('Resend confirmation error:', error);
            return {
                success: false,
                message: 'Failed to resend confirmation email'
            };
        }
    },

    /**
     * Upload and convert files (for authenticated users)
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

        // Check if email verification is required
        if (response.status === 403 && errorData.requires_verification) {
            throw {
                requiresVerification: true,
                email: errorData.user_email,
                message: errorData.message
            };
        }

        throw new Error(errorData.error || 'Upload failed');
    },

    /**
     * Upload and convert files (for guest users)
     */
    async uploadAndConvertGuest(pageSlug, files, guestEmail, workflowId = null, conversionOptions = {}) {
        const formData = new FormData();
        formData.append('page_slug', pageSlug);
        formData.append('guest_email', guestEmail);
        formData.append('locale', document.documentElement.lang || 'en');

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

        const response = await fetch('/api/guest/register-and-upload', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': this.getCsrfToken()
            },
            body: formData
        });

        if (response.ok) {
            const data = await response.json();
            return {
                success: true,
                batch_id: data.batch_id,
                message: data.message,
                user_id: data.user_id
            };
        }

        const errorData = await response.json().catch(() => ({}));

        // Check if user should log in instead
        if (response.status === 409 && errorData.should_login) {
            throw {
                shouldLogin: true,
                message: errorData.error
            };
        }

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
    },

    /**
     * Check batch status (for guest users awaiting email confirmation)
     */
    async checkBatchStatus(batchId) {
        const response = await fetch(`/batch/${batchId}/status`);
        if (response.ok) {
            return await response.json();
        }
        throw new Error('Failed to check batch status');
    },

    /**
     * Poll batch status for guests (covers both email confirmation and conversion)
     * Guests don't have access to execution endpoints, so we poll batch status throughout
     */
    pollBatchStatus(batchId, onUpdate, onComplete, onError) {
        const interval = setInterval(async () => {
            try {
                const data = await this.checkBatchStatus(batchId);

                // Check completion
                if (data.status === 'done') {
                    clearInterval(interval);

                    // Construct download URL from batch ID (guests use batch download endpoint)
                    const downloadUrl = `/batch/${batchId}/download`;

                    onComplete({
                        batch_id: batchId,
                        download_url: downloadUrl,
                        file_size: data.result_size ? this.formatFileSize(data.result_size) : null,
                        status: 'done'
                    });
                } else if (data.status === 'error') {
                    clearInterval(interval);
                    onError(data.error?.message || 'Batch processing failed');
                } else if (data.status === 'processing') {
                    // Email confirmed, conversion in progress
                    onUpdate({ status: 'processing' });
                } else if (data.status === 'pending_email_confirmation') {
                    // Still waiting for email confirmation
                    onUpdate({ status: 'awaiting_confirmation' });
                }
            } catch (error) {
                console.error('Batch polling error:', error);
                // Don't stop polling on temporary errors
            }
        }, 3000); // Poll every 3 seconds (faster for better UX)

        // Stop polling after 10 minutes
        setTimeout(() => {
            clearInterval(interval);
            onError('Processing timeout - please contact support if the issue persists');
        }, 600000);

        return interval;
    },

    formatFileSize(bytes) {
        if (!bytes) return null;
        const units = ['B', 'KB', 'MB', 'GB'];
        const factor = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, factor)).toFixed(1) + ' ' + units[factor];
    }
};