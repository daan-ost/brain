/**
 * Vanilla Upload - Initialization Script
 *
 * Easy initialization for landing pages
 */
import { UploadManager } from './UploadManager.js';

/**
 * Initialize upload component on a container
 *
 * @param {string|HTMLElement} selector - CSS selector or DOM element
 * @param {Object} options - Configuration options
 * @returns {UploadManager} - Upload manager instance
 */
export function initUpload(selector, options = {}) {
    const container = typeof selector === 'string'
        ? document.querySelector(selector)
        : selector;

    if (!container) {
        console.error('[UploadManager] Container not found:', selector);
        return null;
    }

    // Get page config from data attributes
    const pageSlug = container.dataset.pageSlug || options.pageSlug || 'images-to-pdf';
    const allowedExtensions = container.dataset.allowedExtensions
        ? container.dataset.allowedExtensions.split(',')
        : options.allowedExtensions || [];
    const outputFormat = container.dataset.outputFormat || options.outputFormat || 'pdf';
    const outputFormatDisplay = container.dataset.outputFormatDisplay || options.outputFormatDisplay || outputFormat.toUpperCase();
    const actionType = container.dataset.actionType || options.actionType || 'convert';
    const locale = container.dataset.locale || options.locale || 'en';

    // Parse translations from data attribute
    let translations = {};
    if (container.dataset.translations) {
        try {
            translations = JSON.parse(container.dataset.translations);
        } catch (e) {
            console.warn('[UploadManager] Failed to parse translations:', e);
        }
    }

    // Feedback config
    const feedbackEnabled = container.dataset.feedbackEnabled === 'true';
    const feedbackUrl = container.dataset.feedbackUrl || '/feedback';
    const csrfToken = container.dataset.csrfToken || '';

    // Create manager instance
    const manager = new UploadManager({
        container,
        pageSlug,
        allowedExtensions,
        maxFiles: parseInt(container.dataset.maxFiles) || options.maxFiles,
        maxTotalSize: parseInt(container.dataset.maxTotalSize) || options.maxTotalSize,
        maxPages: parseInt(container.dataset.maxPages) || options.maxPages,
        outputFormat,
        outputFormatDisplay,
        actionType,
        locale,
        translations,
        feedbackEnabled,
        feedbackUrl,
        csrfToken,
        ...options,
    });

    // Store reference on container
    container._uploadManager = manager;

    return manager;
}

/**
 * Auto-initialize all upload containers on page load
 */
export function autoInit() {
    document.addEventListener('DOMContentLoaded', () => {
        const containers = document.querySelectorAll('[data-upload-component]');

        containers.forEach(container => {
            if (!container._uploadManager) {
                initUpload(container);
            }
        });
    });
}

/**
 * Global initialization helper for inline scripts
 */
window.VanillaUpload = {
    init: initUpload,
    autoInit,
    UploadManager,
};

// Auto-initialize by default
autoInit();
