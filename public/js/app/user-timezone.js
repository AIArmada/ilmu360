(() => {
    const COOKIE_MAX_AGE = 31536000;

    const detectTimezone = () => Intl.DateTimeFormat().resolvedOptions().timeZone;

    const getStoredTimezone = () => sessionStorage.getItem('user_timezone') || detectTimezone();

    const persistTimezone = (timezone) => {
        if (!timezone) {
            return;
        }

        sessionStorage.setItem('user_timezone', timezone);
        document.cookie = `user_timezone=${encodeURIComponent(timezone)}; path=/; max-age=${COOKIE_MAX_AGE}; SameSite=Lax`;
    };

    const appendTimezoneHeader = (headers, timezone) => {
        if (!timezone || !headers) {
            return;
        }

        if (headers instanceof Headers) {
            if (!headers.has('X-Timezone')) {
                headers.set('X-Timezone', timezone);
            }

            return;
        }

        if (!headers['X-Timezone']) {
            headers['X-Timezone'] = timezone;
        }
    };

    persistTimezone(detectTimezone());

    document.addEventListener('livewire:init', () => {
        if (typeof Livewire === 'undefined' || typeof Livewire.interceptRequest !== 'function') {
            return;
        }

        Livewire.interceptRequest(({ request }) => {
            const timezone = getStoredTimezone();

            if (!request?.options) {
                return;
            }

            request.options.headers ??= {};
            appendTimezoneHeader(request.options.headers, timezone);
        });
    });

    const originalFetch = window.fetch;

    if (typeof originalFetch === 'function') {
        window.fetch = (url, options = {}) => {
            const timezone = getStoredTimezone();
            options.headers ??= {};
            appendTimezoneHeader(options.headers, timezone);

            return originalFetch(url, options);
        };
    }
})();
