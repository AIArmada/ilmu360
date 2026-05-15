@props(['surface' => 'public'])

@php($signalsTracker = app(\App\Services\Signals\SignalsTracker::class)->trackerConfig($surface))
@php($signalsUser = auth()->user())

@if ($signalsTracker !== null)
    <script>
        window.ilmu360 = window.ilmu360 || {};
        window.ilmu360.signals = {
            surface: @js($surface),
            endpoint: @js($signalsTracker['endpoint']),
            eventEndpoint: @js($signalsTracker['event_endpoint']),
            identifyEndpoint: @js($signalsTracker['identify_endpoint']),
            anonymousCookieName: @js($signalsTracker['anonymous_cookie_name']),
            sessionCookieName: @js($signalsTracker['session_cookie_name']),
            writeKey: @js($signalsTracker['write_key']),
            externalId: @js($signalsUser?->getAuthIdentifier()),
            email: @js($signalsUser?->email),
        };
    </script>

    <script
        defer
        data-signals-tracker
        src="{{ $signalsTracker['script_url'] }}"
        data-endpoint="{{ $signalsTracker['endpoint'] }}"
        data-event-endpoint="{{ $signalsTracker['event_endpoint'] }}"
        data-identify-endpoint="{{ $signalsTracker['identify_endpoint'] }}"
        data-anonymous-cookie-name="{{ $signalsTracker['anonymous_cookie_name'] }}"
        data-session-cookie-name="{{ $signalsTracker['session_cookie_name'] }}"
        data-write-key="{{ $signalsTracker['write_key'] }}"
        data-surface="{{ $surface }}"
        @if ($signalsUser !== null)
            data-external-id="{{ $signalsUser->getAuthIdentifier() }}"
            @if (filled($signalsUser->email))
                data-email="{{ $signalsUser->email }}"
            @endif
        @endif
        data-enable-geolocation="{{ config('signals.features.geolocation.enabled', true) ? 'true' : 'false' }}"></script>

    @once
        <script>
            (() => {
                window.ilmu360 = window.ilmu360 || {};

                if (window.ilmu360.signalsUiInitialized) {
                    return;
                }

                window.ilmu360.signalsUiInitialized = true;

                const storageValue = (storage, key) => {
                    try {
                        return storage.getItem(key);
                    } catch (error) {
                        return null;
                    }
                };

                const persistStorageValue = (storage, key, value) => {
                    try {
                        storage.setItem(key, value);
                    } catch (error) {
                    }
                };

                const readCookie = name => {
                    const prefix = `${name}=`;
                    const cookies = document.cookie ? document.cookie.split(';') : [];

                    for (const cookiePart of cookies) {
                        const cookie = cookiePart.trim();

                        if (cookie.startsWith(prefix)) {
                            return decodeURIComponent(cookie.slice(prefix.length));
                        }
                    }

                    return null;
                };

                const writeCookie = (name, value, maxAgeSeconds = null) => {
                    let cookie = `${name}=${encodeURIComponent(value)}; path=/; SameSite=Lax`;

                    if (typeof maxAgeSeconds === 'number') {
                        cookie += `; Max-Age=${maxAgeSeconds}`;
                    }

                    if (window.location.protocol === 'https:') {
                        cookie += '; Secure';
                    }

                    document.cookie = cookie;
                };

                const activeConfig = () => {
                    const configured = window.ilmu360.signals || {};

                    if (configured.writeKey && configured.eventEndpoint) {
                        return configured;
                    }

                    const script = document.querySelector('script[data-signals-tracker][data-write-key]');

                    if (! script) {
                        return null;
                    }

                    return {
                        surface: script.dataset.surface || 'public',
                        eventEndpoint: script.dataset.eventEndpoint,
                        anonymousCookieName: script.dataset.anonymousCookieName || 'mi_signals_anonymous_id',
                        sessionCookieName: script.dataset.sessionCookieName || 'mi_signals_session_id',
                        writeKey: script.dataset.writeKey,
                        externalId: script.dataset.externalId || null,
                        email: script.dataset.email || null,
                    };
                };

                const anonymousIdentifier = config => {
                    const storageKey = `signals:anonymous:${config.writeKey}`;
                    const existing = storageValue(localStorage, storageKey) || readCookie(config.anonymousCookieName);

                    if (existing) {
                        persistStorageValue(localStorage, storageKey, existing);
                        writeCookie(config.anonymousCookieName, existing, 31536000);

                        return existing;
                    }

                    const created = `sig_anon_${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;

                    persistStorageValue(localStorage, storageKey, created);
                    writeCookie(config.anonymousCookieName, created, 31536000);

                    return created;
                };

                const sessionIdentifier = config => {
                    const storageKey = `signals:session:${config.writeKey}`;
                    const existing = storageValue(sessionStorage, storageKey);

                    if (existing) {
                        writeCookie(config.sessionCookieName, existing);

                        return existing;
                    }

                    const created = `sig_${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;

                    persistStorageValue(sessionStorage, storageKey, created);
                    persistStorageValue(sessionStorage, `signals:session-started-at:${config.writeKey}`, new Date().toISOString());
                    writeCookie(config.sessionCookieName, created);

                    return created;
                };

                const sessionStartedAt = config => {
                    const storageKey = `signals:session-started-at:${config.writeKey}`;
                    const existing = storageValue(sessionStorage, storageKey);

                    if (existing) {
                        return existing;
                    }

                    const created = new Date().toISOString();

                    persistStorageValue(sessionStorage, storageKey, created);

                    return created;
                };

                const parseProperties = value => {
                    if (! value) {
                        return {};
                    }

                    try {
                        const parsed = JSON.parse(value);

                        return parsed && typeof parsed === 'object' && ! Array.isArray(parsed) ? parsed : {};
                    } catch (error) {
                        return {};
                    }
                };

                const elementLabel = element => {
                    if (element.dataset.signalLabel) {
                        return element.dataset.signalLabel;
                    }

                    if (element.getAttribute('aria-label')) {
                        return element.getAttribute('aria-label');
                    }

                    if (element.getAttribute('title')) {
                        return element.getAttribute('title');
                    }

                    return element.textContent?.replace(/\s+/g, ' ').trim().slice(0, 120) || null;
                };

                const inputValue = (target, trigger) => {
                    const includeValue = target.dataset.signalIncludeValue === 'true'
                        || trigger?.dataset.signalIncludeValue === 'true';

                    if (! includeValue) {
                        return null;
                    }

                    if (['password', 'email', 'tel'].includes(target.type)) {
                        return null;
                    }

                    if (target.type === 'checkbox') {
                        return target.checked;
                    }

                    if (target.type === 'radio') {
                        return target.checked ? target.value : null;
                    }

                    return target.value || null;
                };

                const browserPlatform = () => {
                    const userAgent = navigator.userAgent.toLowerCase();

                    if (userAgent.includes('ipad')) {
                        return 'ipados';
                    }

                    if (userAgent.includes('iphone') || userAgent.includes('ipod')) {
                        return 'ios';
                    }

                    if (userAgent.includes('android')) {
                        return 'android';
                    }

                    if (userAgent.includes('macintosh') || userAgent.includes('mac os')) {
                        return 'macos';
                    }

                    if (userAgent.includes('windows')) {
                        return 'windows';
                    }

                    if (userAgent.includes('linux')) {
                        return 'linux';
                    }

                    return 'web';
                };

                const browserFamily = platform => {
                    if (['ios', 'android', 'ipados'].includes(platform)) {
                        return 'mobile';
                    }

                    if (['macos', 'windows', 'linux'].includes(platform)) {
                        return 'desktop';
                    }

                    return 'web';
                };

                const baseProperties = (trigger, sourceElement = trigger) => {
                    const target = sourceElement instanceof HTMLElement ? sourceElement : trigger;
                    const platform = browserPlatform();

                    return {
                        ...parseProperties(trigger.dataset.signalProps),
                        surface: activeConfig()?.surface || 'public',
                        page: window.location.pathname,
                        client_origin: 'web',
                        client_origin_source: 'browser_tracker',
                        client_platform: platform,
                        client_family: browserFamily(platform),
                        client_transport: 'web',
                        component: trigger.dataset.signalComponent || trigger.closest('[data-signal-component]')?.dataset.signalComponent || null,
                        control: trigger.dataset.signalControl || target?.name || target?.id || null,
                        label: elementLabel(trigger),
                        entity_type: trigger.dataset.signalEntityType || null,
                        entity_id: trigger.dataset.signalEntityId || null,
                        href: trigger instanceof HTMLAnchorElement ? trigger.href : null,
                    };
                };

                const trackingPayload = (eventName, properties = {}, eventCategory = null) => {
                    const config = activeConfig();
                    const params = new URLSearchParams(window.location.search);

                    if (! config?.eventEndpoint || ! config.writeKey) {
                        return null;
                    }

                    return {
                        endpoint: config.eventEndpoint,
                        body: {
                            write_key: config.writeKey,
                            event_name: eventName,
                            event_category: eventCategory || eventName.split('.')[0] || 'ui',
                            external_id: config.externalId || null,
                            anonymous_id: anonymousIdentifier(config),
                            session_identifier: sessionIdentifier(config),
                            session_started_at: sessionStartedAt(config),
                            occurred_at: new Date().toISOString(),
                            path: window.location.pathname + window.location.search + window.location.hash,
                            url: window.location.href,
                            referrer: document.referrer || null,
                            utm_source: params.get('utm_source'),
                            utm_medium: params.get('utm_medium'),
                            utm_campaign: params.get('utm_campaign'),
                            utm_content: params.get('utm_content'),
                            utm_term: params.get('utm_term'),
                            properties,
                        },
                    };
                };

                window.ilmu360.trackSignal = (eventName, properties = {}, options = {}) => {
                    if (! eventName) {
                        return;
                    }

                    const payload = trackingPayload(eventName, properties, options.category || null);

                    if (! payload) {
                        return;
                    }

                    const body = JSON.stringify(payload.body);

                    if (navigator.sendBeacon) {
                        navigator.sendBeacon(payload.endpoint, new Blob([body], { type: 'application/json' }));

                        return;
                    }

                    fetch(payload.endpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body,
                        keepalive: true,
                        credentials: 'omit',
                    }).catch(() => {});
                };

                document.addEventListener('click', event => {
                    const trigger = event.target instanceof Element ? event.target.closest('[data-signal-event]') : null;

                    if (! trigger || trigger.matches('[disabled], [aria-disabled="true"]')) {
                        return;
                    }

                    window.ilmu360.trackSignal(
                        trigger.dataset.signalEvent,
                        baseProperties(trigger),
                        { category: trigger.dataset.signalCategory || null },
                    );
                }, true);

                document.addEventListener('submit', event => {
                    const form = event.target instanceof HTMLFormElement ? event.target : null;

                    if (! form?.dataset.signalSubmitEvent) {
                        return;
                    }

                    window.ilmu360.trackSignal(
                        form.dataset.signalSubmitEvent,
                        baseProperties(form),
                        { category: form.dataset.signalCategory || null },
                    );
                }, true);

                document.addEventListener('change', event => {
                    const target = event.target instanceof HTMLElement ? event.target : null;
                    const trigger = target?.closest('[data-signal-change-event]');

                    if (! target || ! trigger) {
                        return;
                    }

                    window.ilmu360.trackSignal(
                        trigger.dataset.signalChangeEvent,
                        {
                            ...baseProperties(trigger, target),
                            field_name: target.getAttribute('name') || target.id || null,
                            field_type: target.getAttribute('type') || target.tagName.toLowerCase(),
                            field_value: inputValue(target, trigger),
                        },
                        { category: trigger.dataset.signalCategory || null },
                    );
                }, true);
            })();
        </script>
    @endonce
@endif
