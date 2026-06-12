/**
 * Alpine Components Bundle
 * Export all Alpine components for easy import
 */

// Import individual modules
import { UploadUtils } from './upload-utils.js';
import { UploadAPI } from './upload-api.js';
import { PreviewHandler } from './preview-handler.js';
import { uploadFlowV2 } from './upload-flow.js';

// Register components with Alpine when it's ready
if (typeof window !== 'undefined') {
    // Wait for Alpine to be available
    document.addEventListener('alpine:init', () => {
        // Register the main upload flow component globally
        Alpine.data('uploadFlowV2', uploadFlowV2);

        // Make utilities available globally if needed
        window.AppUtils = {
            UploadUtils,
            UploadAPI,
            PreviewHandler
        };
    });
}

// Export for module usage
export {
    UploadUtils,
    UploadAPI,
    PreviewHandler,
    uploadFlowV2
};