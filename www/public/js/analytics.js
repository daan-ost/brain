/**
 * Analytics Tracker for AI Factory
 *
 * Tracks user interactions (clicks, inputs, scroll) and frustration signals
 * (rage clicks, form abandonment) for UX analysis.
 *
 * Features:
 * - Throttled scroll tracking (300ms)
 * - Batched event sending (20 sec or 20 events)
 * - Multi-tab support via localStorage/sessionStorage
 * - Max 50 actions per session
 * - Blob-wrapped sendBeacon for browser compatibility
 */

class AnalyticsTracker {
    constructor() {
        this.sessionId = null;
        this.sessionGroupId = null;
        this.actions = [];
        this.lastClickTime = 0;
        this.rapidClicks = 0;
        this.rageClicks = 0;
        this.scrollDepth = 0;
        this.lastScrollTrack = 0;
        this.lastBatchSent = Date.now();
        this.totalActionsSent = 0;

        // Constants
        this.BATCH_INTERVAL = 20000;      // 20 seconds
        this.BATCH_SIZE_TRIGGER = 20;      // Or 20 events
        this.SCROLL_THROTTLE = 300;        // 300ms throttle
        this.MAX_ACTIONS = 50;             // Max actions to track per session
        this.MIN_BATCH_INTERVAL = 5000;    // Minimum 5 seconds between batches

        this.init();
    }

    init() {
        // Check kill-switch
        if (!window.analyticsTrackingEnabled) {
            return;
        }

        // Multi-tab handling: session_group_id shared via localStorage
        this.sessionGroupId = localStorage.getItem('analytics_group_id');
        if (!this.sessionGroupId) {
            this.sessionGroupId = crypto.randomUUID();
            localStorage.setItem('analytics_group_id', this.sessionGroupId);
        }

        // session_id unique per tab via sessionStorage
        this.sessionId = sessionStorage.getItem('analytics_session_id');
        if (!this.sessionId) {
            this.sessionId = window.analyticsSessionId; // From server
            if (this.sessionId) {
                sessionStorage.setItem('analytics_session_id', this.sessionId);
            }
        }

        if (!this.sessionId) {
            return;
        }

        // Store page load time for relative timestamps
        window.pageLoadTime = window.pageLoadTime || Date.now();

        // Track clicks
        document.addEventListener('click', (e) => this.trackClick(e));

        // Track inputs (debounced via action limit)
        document.addEventListener('input', (e) => this.trackInput(e));

        // Track scroll depth (throttled)
        window.addEventListener('scroll', () => this.trackScroll(), { passive: true });

        // Send on page unload
        window.addEventListener('beforeunload', () => this.sendFinal());

        // Periodic send (every 20 seconds)
        setInterval(() => this.sendBatch(), this.BATCH_INTERVAL);
    }

    trackScroll() {
        const now = Date.now();
        // Throttle: max 1 update per 300ms
        if (now - this.lastScrollTrack < this.SCROLL_THROTTLE) {
            return;
        }
        this.lastScrollTrack = now;

        const scrolled = (window.scrollY + window.innerHeight) / document.body.scrollHeight;
        this.scrollDepth = Math.max(this.scrollDepth, Math.min(1, scrolled));
    }

    trackClick(e) {
        // Respect max actions limit (total sent + pending)
        if (this.totalActionsSent + this.actions.length >= this.MAX_ACTIONS) {
            return;
        }

        const now = Date.now();
        const target = this.getSelector(e.target);

        // Detect rapid/rage clicks
        if (now - this.lastClickTime < 500) {
            this.rapidClicks++;
            if (this.rapidClicks >= 3) {
                this.rageClicks++;
            }
        } else {
            this.rapidClicks = 0;
        }
        this.lastClickTime = now;

        this.actions.push({
            type: 'click',
            target: target,
            t: Math.round((now - window.pageLoadTime) / 100) / 10 // 1 decimal
        });

        // Trigger batch if 20 events collected
        if (this.actions.length >= this.BATCH_SIZE_TRIGGER) {
            this.sendBatch();
        }
    }

    trackInput(e) {
        // Respect max actions limit
        if (this.totalActionsSent + this.actions.length >= this.MAX_ACTIONS) {
            return;
        }

        const target = this.getSelector(e.target);
        const now = Date.now();

        this.actions.push({
            type: 'input',
            target: target,
            t: Math.round((now - window.pageLoadTime) / 100) / 10
        });

        // Trigger batch if 20 events collected
        if (this.actions.length >= this.BATCH_SIZE_TRIGGER) {
            this.sendBatch();
        }
    }

    getSelector(el) {
        if (!el) return 'unknown';
        if (el.id) return '#' + el.id;
        if (el.className && typeof el.className === 'string') {
            const firstClass = el.className.split(' ')[0];
            if (firstClass) return '.' + firstClass;
        }
        return el.tagName ? el.tagName.toLowerCase() : 'unknown';
    }

    async sendBatch() {
        // Debounce: don't send more than once per 5 seconds
        const now = Date.now();
        if (now - this.lastBatchSent < this.MIN_BATCH_INTERVAL) {
            return;
        }

        if (this.actions.length === 0 && this.rageClicks === 0 && this.scrollDepth === 0) {
            return;
        }

        this.lastBatchSent = now;

        const actionsToSend = this.actions.slice(0, this.BATCH_SIZE_TRIGGER);

        const data = {
            session_id: this.sessionId,
            session_group_id: this.sessionGroupId,
            actions: actionsToSend,
            rage_clicks: this.rageClicks,
            rapid_click_count: this.rapidClicks,
            scroll_depth: Math.round(this.scrollDepth * 100) / 100 // 2 decimals
        };

        // Track sent actions count
        this.totalActionsSent += actionsToSend.length;

        // Clear sent actions, keep overflow for next batch
        this.actions = this.actions.slice(this.BATCH_SIZE_TRIGGER);
        this.rageClicks = 0;
        // Don't reset scrollDepth - we always send the max

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]');
            await fetch('/api/analytics/session', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken ? csrfToken.content : ''
                },
                body: JSON.stringify(data)
            });
        } catch (e) {
            // Silent fail - analytics should never break UX
            console.debug('Analytics batch failed:', e);
        }
    }

    sendFinal() {
        const lastActions = this.actions.slice(-10).map(a => a.type + ':' + a.target);

        const payload = {
            session_id: this.sessionId,
            session_group_id: this.sessionGroupId,
            exit_actions: lastActions,
            actions: this.actions.slice(0, this.MAX_ACTIONS - this.totalActionsSent),
            rage_clicks: this.rageClicks,
            scroll_depth: Math.round(this.scrollDepth * 100) / 100
        };

        // Use Blob for full browser compatibility with sendBeacon
        const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
        navigator.sendBeacon('/api/analytics/session', blob);
    }
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    window.analyticsTracker = new AnalyticsTracker();
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AnalyticsTracker;
}
