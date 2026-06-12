/**
 * Upload Flow Utilities
 * Shared helper functions for file handling and formatting
 */

export const UploadUtils = {
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

        // Check file size - no preview for files > 20MB
        const maxPreviewSize = 20 * 1024 * 1024; // 20MB in bytes
        if (file.size > maxPreviewSize) {
            return false;
        }

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
};