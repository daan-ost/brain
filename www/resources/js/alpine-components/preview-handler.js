/**
 * Preview Handler Module
 * Handles file preview functionality including PDFs and images
 */

export const PreviewHandler = {
    /**
     * Initialize PDF preview for a file
     */
    async loadPdfPreview(url) {
        // Lazy load PDF.js if not already loaded
        if (!window.pdfjsLib) {
            if (window.loadPdfJs) {
                try {
                    await window.loadPdfJs();
                } catch (error) {
                    console.error('Failed to load PDF.js:', error);
                    return null;
                }
            } else {
                console.warn('PDF.js not loaded and loadPdfJs function not available');
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
};