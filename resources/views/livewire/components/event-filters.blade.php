@php
    $showsGeolocationControls = $this->showsGeolocationControls();
@endphp

<div
    data-art-direction="living-majlis"
    class="living-majlis-field text-slate-900"
    x-init="window.dispatchEvent(new CustomEvent('home-quick-filter-state-updated', { detail: { activeQuickFilters: @js($this->activeHomeQuickFilters()) } }))"
    x-on:home-quick-filter-selected.window="handleHomeQuickFilter($event.detail)"
    x-data="{
        ...window.ilmu360.geolocationPermission({
            initiallyGranted: @js($showsGeolocationControls),
            cookieName: @js(\App\Support\Location\PublicGeolocationPermission::COOKIE_NAME),
        }),
        filtersOpen: $wire.entangle('filtersPanelOpen'),
        locating: false,
        locationNotice: null,
        setLocationNotice(message) {
            this.locationNotice = message;
        },
        clearLocationNotice() {
            this.locationNotice = null;
        },
        handleHomeQuickFilter(detail) {
            if (! detail?.quickFilterKey) return;
            this.filtersOpen = true;

            if (detail.quickFilterKey === 'nearby') {
                this.locate();
            }
        },
        async locate() {
            if (this.locating) return;
            this.clearLocationNotice();

            if (! navigator.geolocation) {
                this.setGeolocationPermission(false);
                this.setLocationNotice('{{ __('Geolocation is not supported by your browser.') }}');
                return;
            }

            if (navigator.permissions && typeof navigator.permissions.query === 'function') {
                try {
                    const permissionStatus = await navigator.permissions.query({ name: 'geolocation' });

                    if (permissionStatus.state === 'denied') {
                        this.setGeolocationPermission(false);
                    }
                } catch (error) {
                }
            }

            this.locating = true;
            navigator.geolocation.getCurrentPosition((position) => {
                this.clearLocationNotice();
                this.setGeolocationPermission(true);
                this.$wire.setLocation(position.coords.latitude, position.coords.longitude);
                this.locating = false;
            }, (error) => {
                this.locating = false;

                if (error?.code === 1) {
                    this.setGeolocationPermission(false);
                    this.setLocationNotice('{{ __('Allow location access in your browser settings to use nearby search.') }}');
                    return;
                }

                this.setLocationNotice('{{ __('Unable to get your location. Please enable location services.') }}');
            });
        },
    }"
>
    <form wire:submit.prevent
        data-signal-change-event="filter.changed"
        data-signal-category="filter"
        data-signal-component="events_index_filters"
        data-signal-control="filter_form"
        data-signal-props='@json(['surface' => 'homepage'])'
        class="space-y-5">
        @include('partials.event-filter-panel', ['showNearbyButton' => $showNearbyButton])
    </form>
</div>
