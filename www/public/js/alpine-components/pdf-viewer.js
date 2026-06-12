/**
 * PDF Viewer Module
 *
 * Standalone PDF.js viewer that communicates with Alpine components via CustomEvents.
 * This module is completely isolated from Alpine to prevent DOM manipulation conflicts.
 *
 * Usage:
 *   1. Include pdf.js library before this script
 *   2. Call window.pdfViewerCommand('load', pdfUrl) to load a PDF
 *   3. Listen for 'pdfviewer:state' events for state updates
 *
 * @requires pdf.js (pdfjsLib)
 */
(function() {
    'use strict';

    // PDF.js worker configuration - must be set before any PDF loading
    if (typeof pdfjsLib !== 'undefined') {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    }

    /**
     * PdfViewer - Completely isolated PDF viewer
     * Communication with Alpine is via CustomEvents only
     */
    const PdfViewer = {
        pdfDoc: null,
        currentPage: 1,
        totalPages: 0,
        scale: 1.0,
        rendering: false,
        renderingThumbnails: false,

        /**
         * Load a PDF from URL or data URL
         * @param {string} url - URL to PDF file or base64 data URL
         */
        async loadPdf(url) {
            try {
                console.log('PdfViewer: Loading PDF from URL length:', url ? url.length : 'null');
                if (!url) {
                    console.error('PdfViewer: No URL provided');
                    return;
                }
                const loadingTask = pdfjsLib.getDocument(url);
                this.pdfDoc = await loadingTask.promise;
                this.totalPages = this.pdfDoc.numPages;
                this.currentPage = 1;
                console.log('PdfViewer: PDF loaded, pages:', this.totalPages);

                // Calculate optimal initial scale to fit container
                await this.calculateFitScale();

                this.emitState();
                await this.renderPage(this.currentPage);
                this.renderThumbnails();
            } catch (error) {
                console.error('PdfViewer: Error loading PDF:', error);
                // Try to show error on canvas
                const canvas = document.getElementById('pdfMainCanvas');
                if (canvas) {
                    const ctx = canvas.getContext('2d');
                    canvas.width = 400;
                    canvas.height = 200;
                    ctx.fillStyle = '#f8d7da';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    ctx.fillStyle = '#721c24';
                    ctx.font = '14px sans-serif';
                    ctx.fillText('Error loading PDF', 20, 100);
                }
            }
        },

        /**
         * Calculate optimal scale to fit PDF in container
         */
        async calculateFitScale() {
            if (!this.pdfDoc) return;

            try {
                const page = await this.pdfDoc.getPage(1);
                const viewport = page.getViewport({ scale: 1.0 });

                // Get container dimensions (PDF viewer area)
                const container = document.querySelector('.flex-1.overflow-auto.p-4');
                if (!container) {
                    console.warn('PdfViewer: Container not found, using default scale');
                    this.scale = 1.0;
                    return;
                }

                // Available space (minus padding and scrollbar margin)
                const availableWidth = container.clientWidth - 60;
                const availableHeight = container.clientHeight - 40;

                // PDF aspect ratio
                const pdfAspectRatio = viewport.width / viewport.height;
                const isLandscape = pdfAspectRatio > 1.2; // Wide/landscape document

                let optimalScale;

                if (isLandscape) {
                    // For landscape/wide documents (CAD, floor plans): fit entire page
                    const scaleToFitWidth = availableWidth / viewport.width;
                    const scaleToFitHeight = availableHeight / viewport.height;
                    optimalScale = Math.min(scaleToFitWidth, scaleToFitHeight, 1.5);
                } else {
                    // For portrait documents: fit width, allow vertical scroll
                    const scaleToFitWidth = availableWidth / viewport.width;
                    optimalScale = Math.min(scaleToFitWidth, 2.0);
                }

                // Round to nice increments (0.25)
                optimalScale = Math.round(optimalScale * 4) / 4;

                // Minimum scale of 0.5 for landscape, 0.75 for portrait
                const minScale = isLandscape ? 0.5 : 0.75;
                optimalScale = Math.max(optimalScale, minScale);

                // For normal portrait documents, ensure at least 1.0
                if (!isLandscape && optimalScale < 1.0 && viewport.width < 700) {
                    optimalScale = 1.0;
                }

                console.log('PdfViewer: PDF dimensions:', viewport.width, 'x', viewport.height);
                console.log('PdfViewer: Aspect ratio:', pdfAspectRatio.toFixed(2), isLandscape ? '(landscape)' : '(portrait)');
                console.log('PdfViewer: Container:', availableWidth, 'x', availableHeight);
                console.log('PdfViewer: Calculated optimal scale:', optimalScale);

                this.scale = optimalScale;
            } catch (error) {
                console.error('PdfViewer: Error calculating fit scale:', error);
                this.scale = 1.0; // Fallback to 100%
            }
        },

        /**
         * Render a specific page to the main canvas
         * @param {number} num - Page number (1-indexed)
         */
        async renderPage(num) {
            if (this.rendering || !this.pdfDoc) {
                console.log('PdfViewer: Skip render - rendering:', this.rendering, 'pdfDoc:', !!this.pdfDoc);
                return;
            }
            this.rendering = true;

            try {
                console.log('PdfViewer: Rendering page', num);
                const page = await this.pdfDoc.getPage(num);
                const canvas = document.getElementById('pdfMainCanvas');
                if (!canvas) {
                    console.error('PdfViewer: Canvas not found');
                    this.rendering = false;
                    return;
                }

                const ctx = canvas.getContext('2d');
                const viewport = page.getViewport({ scale: this.scale });

                canvas.height = viewport.height;
                canvas.width = viewport.width;

                await page.render({
                    canvasContext: ctx,
                    viewport: viewport
                }).promise;

                this.currentPage = num;
                this.emitState();
                console.log('PdfViewer: Page rendered successfully');
            } catch (error) {
                console.error('PdfViewer: Error rendering page:', error);
            } finally {
                this.rendering = false;
            }
        },

        /**
         * Render thumbnail images for all pages
         */
        async renderThumbnails() {
            if (!this.pdfDoc || this.renderingThumbnails) return;
            this.renderingThumbnails = true;
            console.log('PdfViewer: Rendering thumbnails');

            // Render thumbnails sequentially to avoid canvas conflicts
            for (let i = 1; i <= this.totalPages; i++) {
                try {
                    const page = await this.pdfDoc.getPage(i);
                    const canvas = document.getElementById('thumb-' + i);
                    if (!canvas) continue;

                    // Cancel any pending render on this canvas
                    if (canvas._renderTask) {
                        canvas._renderTask.cancel();
                        canvas._renderTask = null;
                    }

                    const ctx = canvas.getContext('2d');
                    const viewport = page.getViewport({ scale: 0.15 });

                    canvas.height = viewport.height;
                    canvas.width = viewport.width;

                    // Clear canvas before rendering
                    ctx.clearRect(0, 0, canvas.width, canvas.height);

                    const renderTask = page.render({
                        canvasContext: ctx,
                        viewport: viewport
                    });

                    canvas._renderTask = renderTask;
                    await renderTask.promise;
                    canvas._renderTask = null;
                } catch (error) {
                    if (error.name !== 'RenderingCancelledException') {
                        console.error('PdfViewer: Error rendering thumbnail', i, error);
                    }
                }
            }
            this.renderingThumbnails = false;
        },

        /**
         * Emit current viewer state via CustomEvent
         */
        emitState() {
            window.dispatchEvent(new CustomEvent('pdfviewer:state', {
                detail: {
                    currentPage: this.currentPage,
                    totalPages: this.totalPages,
                    scale: this.scale
                }
            }));
        },

        /**
         * Navigate to a specific page
         * @param {number} num - Page number (1-indexed)
         */
        goToPage(num) {
            if (num >= 1 && num <= this.totalPages) {
                this.renderPage(num);
            }
        },

        /**
         * Navigate to previous page
         */
        prevPage() {
            if (this.currentPage > 1) {
                this.renderPage(this.currentPage - 1);
            }
        },

        /**
         * Navigate to next page
         */
        nextPage() {
            if (this.currentPage < this.totalPages) {
                this.renderPage(this.currentPage + 1);
            }
        },

        /**
         * Zoom in (increase scale by 0.25)
         */
        zoomIn() {
            this.scale = Math.min(this.scale + 0.25, 3.0);
            this.emitState();
            this.renderPage(this.currentPage);
        },

        /**
         * Zoom out (decrease scale by 0.25)
         */
        zoomOut() {
            this.scale = Math.max(this.scale - 0.25, 0.5);
            this.emitState();
            this.renderPage(this.currentPage);
        },

        /**
         * Get current state
         * @returns {Object} Current viewer state
         */
        getState() {
            return {
                currentPage: this.currentPage,
                totalPages: this.totalPages,
                scale: this.scale,
                isLoaded: !!this.pdfDoc
            };
        }
    };

    /**
     * Public command interface
     * Expose ONLY command functions - not the viewer object itself
     *
     * @param {string} cmd - Command name
     * @param {*} arg - Command argument
     */
    window.pdfViewerCommand = function(cmd, arg) {
        switch(cmd) {
            case 'load':
                PdfViewer.loadPdf(arg);
                break;
            case 'goToPage':
                PdfViewer.goToPage(arg);
                break;
            case 'prevPage':
                PdfViewer.prevPage();
                break;
            case 'nextPage':
                PdfViewer.nextPage();
                break;
            case 'zoomIn':
                PdfViewer.zoomIn();
                break;
            case 'zoomOut':
                PdfViewer.zoomOut();
                break;
            case 'getState':
                return PdfViewer.getState();
            default:
                console.warn('PdfViewer: Unknown command:', cmd);
        }
    };

    // Log module initialization
    console.log('PdfViewer module loaded');
})();
