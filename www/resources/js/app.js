import './bootstrap';

// Import Alpine.js - Livewire will use the existing instance if available
import Alpine from 'alpinejs';

// Only start Alpine if it hasn't been started by Livewire yet
if (!window.Alpine) {
    window.Alpine = Alpine;
    Alpine.start();
}

// Detect and store browser timezone for locale detection
(function() {
    try {
        const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (timezone && !document.cookie.includes('browser_timezone=')) {
            document.cookie = `browser_timezone=${timezone}; path=/; max-age=31536000; SameSite=Lax`;
        }
    } catch (e) {
        // Timezone detection not supported
    }
})();
