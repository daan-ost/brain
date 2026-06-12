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