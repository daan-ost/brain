/**
 * Error Handler Module
 * Centralized error handling with user-friendly messages
 */

export const ErrorHandler = {
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
};