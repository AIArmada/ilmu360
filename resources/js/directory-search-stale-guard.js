/**
 * Directory search stale-response guard.
 *
 * Race: `wire:model.live.debounce` fires one Livewire request per pause.
 * Two requests for "masjid" and "masjid tengku" can overlap (parallel
 * model.live updates), or the older response can land during the debounce
 * window before the newer request is even sent. Either way the stale
 * response must not morph: result regions are attributed to their query
 * via `data-search-query`, so a stale paint reads as a confusing swap.
 *
 * Two component shapes are covered:
 *
 * 1. Typing components (institutions, events, persons, references): the
 *    search input and the results live in the same component, so the
 *    component root contains `[data-directory-search-input]`. The rule
 *    compares the sent search value against the component's CURRENT
 *    client state (`component.reactive`) at the same dot-path. If they
 *    differ, the user has typed further (or cleared) since this request
 *    was sent, so the morph is cancelled. The newer request carries the
 *    full current state, so nothing is lost.
 * 2. Split components (venues): the search input lives in the parent
 *    while the results child applies `venue-filters-updated` snapshots.
 *    Livewire delivers those listener updates as `__dispatch` calls
 *    (see `call.params`: `[eventName, payload]`), not as model updates,
 *    so the child has no live input state to compare against. The rule
 *    instead compares the carried search snapshot against the LIVE input
 *    value, found via the input's `aria-controls` pointing at the child
 *    root id. If they differ, a newer query was typed after this snapshot
 *    was sent, so the morph is cancelled. Sequential deferred updates
 *    still morph in order (dimmed and query-attributed, so the swap is
 *    explainable); only genuinely superseded responses are skipped.
 *
 * A `clearSearch` / `clearFilters` call sent after a search message also
 * invalidates it on the same component.
 */
function searchUpdateKeys(updates) {
    if (!updates || typeof updates !== 'object') {
        return [];
    }

    return Object.keys(updates).filter((key) => key === 'search' || key.endsWith('.search'));
}

function getPathValue(obj, path) {
    if (obj === null || obj === undefined) {
        return undefined;
    }

    return String(path)
        .split('.')
        .reduce((acc, part) => (acc === null || acc === undefined ? acc : acc[part]), obj);
}

function messageCalls(message) {
    if (!Array.isArray(message.calls)) {
        return [];
    }

    return message.calls;
}

function isClearCall(call) {
    const name = call && call.method;

    return name === 'clearSearch' || name === 'clearFilters';
}

/**
 * Search snapshots carried by event-driven listener updates. Livewire
 * delivers `#[On]` listener invocations as `__dispatch` calls shaped
 * like `{method: '__dispatch', params: [eventName, payload]}` — e.g.
 * `syncFilters` never appears; venues arrives as
 * `__dispatch('venue-filters-updated', {filters: {search, ...}})`.
 */
function dispatchedSearches(calls) {
    const out = [];

    for (const call of calls) {
        if (!call || call.method !== '__dispatch' || !Array.isArray(call.params)) {
            continue;
        }

        const payload = call.params[1];

        if (payload !== null && typeof payload === 'object') {
            const filters = payload.filters;

            if (filters !== null && typeof filters === 'object' && 'search' in filters) {
                out.push({ key: 'search', sent: filters.search });
            } else if ('search' in payload) {
                out.push({ key: 'search', sent: payload.search });
            }
        }
    }

    return out;
}

function carriedSearches(message) {
    const out = searchUpdateKeys(message.updates).map((key) => ({ key, sent: message.updates[key] }));

    return out.concat(dispatchedSearches(messageCalls(message)));
}

function componentIsTypingComponent(component) {
    if (!component || !component.el || typeof component.el.querySelector !== 'function') {
        return false;
    }

    return component.el.querySelector('[data-directory-search-input]') !== null;
}

/**
 * Live search input driving a split results component, linked via
 * `aria-controls` pointing at the results root id (e.g. venue search
 * input `aria-controls="venue-results"`).
 */
function linkedSearchInput(component) {
    if (
        !component ||
        !component.el ||
        typeof component.el.getAttribute !== 'function' ||
        typeof document === 'undefined' ||
        typeof document.querySelector !== 'function'
    ) {
        return null;
    }

    const id = component.el.getAttribute('id');

    if (!id) {
        return null;
    }

    let escaped = id;

    if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
        escaped = CSS.escape(id);
    }

    return document.querySelector('[data-directory-search-input][aria-controls="' + escaped + '"]');
}

function installDirectorySearchStaleGuard() {
    if (!window.Livewire || typeof window.Livewire.interceptMessage !== 'function') {
        return false;
    }

    if (window.Livewire.__dirSearchGuardInstalled === true) {
        return true;
    }

    // Monotonic per-message sequence; WeakMaps drop entries with the component.
    let sequence = 0;
    const sentByComponent = new WeakMap();
    const clearSeqByComponent = new WeakMap();

    window.Livewire.interceptMessage(({ message, cancel, onSend, onSuccess }) => {
        const component = message.component;

        onSend(() => {
            if (!component) {
                return;
            }

            sequence += 1;
            const seq = sequence;
            message.__dirSearchSeq = seq;

            const carried = carriedSearches(message);

            if (carried.length > 0) {
                const values = {};

                for (const { key, sent } of carried) {
                    values[key] = sent;
                }

                sentByComponent.set(component, { seq, values });
            }

            if (messageCalls(message).some(isClearCall)) {
                clearSeqByComponent.set(component, seq);
            }
        });

        onSuccess(() => {
            if (!component) {
                return;
            }

            const carried = carriedSearches(message);

            if (carried.length === 0) {
                return;
            }

            const msgSeq = message.__dirSearchSeq || 0;

            if ((clearSeqByComponent.get(component) || 0) > msgSeq) {
                cancel();

                return;
            }

            if (componentIsTypingComponent(component)) {
                const stale = carried.some(({ key, sent }) => sent !== getPathValue(component.reactive, key));

                if (stale) {
                    cancel();
                }

                return;
            }

            // Split component: compare against the live input value.
            // Normalized: a cleared input reads '' while the snapshot
            // carries null, and both mean "no query".
            const input = linkedSearchInput(component);

            if (input) {
                const live = input.value.trim();
                const stale = carried.some(({ sent }) => (sent === null || sent === undefined ? '' : String(sent)).trim() !== live);

                if (stale) {
                    cancel();
                }

                return;
            }

            // No live input found: only a newer sent snapshot invalidates.
            const latest = sentByComponent.get(component);

            if (latest && latest.seq > msgSeq) {
                const superseded = carried.some(({ key, sent }) => latest.values[key] !== sent);

                if (superseded) {
                    cancel();
                }
            }
        });
    });

    window.Livewire.__dirSearchGuardInstalled = true;

    return true;
}

if (!installDirectorySearchStaleGuard()) {
    document.addEventListener('livewire:init', () => installDirectorySearchStaleGuard(), { once: true });
}
