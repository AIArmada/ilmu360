@php
    $pickerTitle = $title ?? null;
    $pickerDescription = $description ?? null;
    $pickerSearchLabel = $searchLabel ?? __('Search for an institution or address');
    $pickerFieldLabel = $label ?? $pickerSearchLabel;
    $pickerSelectedLabel = $selectedLabel ?? __('Selected location');
    $pickerFailureMessage = $failureMessage ?? __('Location search is temporarily unavailable. Continue with the manual address fields below.');
    $pickerApplyMethod = $applyMethod ?? 'applyLocationPickerSelection';
    $pickerTargetStatePath = $targetStatePath ?? $getStatePath();
@endphp

@once
    <style>
        .mi-institution-location-picker-search gmp-place-autocomplete {
            background: transparent;
            border: 0;
            border-radius: inherit;
            box-shadow: none;
            display: block;
            width: 100%;
            min-height: 2.5rem;
            color-scheme: light;
        }

        .mi-institution-location-picker-search gmp-place-autocomplete::part(input) {
            border: 0;
            box-shadow: none;
            font: inherit;
            min-height: 2.5rem;
            padding: 0;
        }
    </style>
@endonce

<div
    wire:ignore
    x-data="{
        apiKey: @js($mapsApiKey),
        applyMethod: @js($pickerApplyMethod),
        targetStatePath: @js($pickerTargetStatePath),
        enabled: true,
        loading: true,
        errorMessage: null,
        selectedName: null,
        selectedAddress: null,
        map: null,
        marker: null,
        autocompleteElement: null,
        async init() {
            try {
                await this.loadGoogleMaps();
                await this.mountAutocomplete();
                this.loading = false;
            } catch (error) {
                console.error(error);
                this.disablePicker(@js($pickerFailureMessage));
            }
        },
        async loadGoogleMaps() {
            if (window.google?.maps?.importLibrary) {
                return window.google.maps;
            }

            window.__majlisGoogleMapsPromise ??= new Promise((resolve, reject) => {
                const existingScript = document.querySelector('script[data-majlis-google-maps]');

                if (existingScript) {
                    existingScript.addEventListener('load', () => resolve(window.google?.maps));
                    existingScript.addEventListener('error', () => reject(new Error('Google Maps could not load.')));

                    return;
                }

                window.google ??= {};
                window.google.maps ??= {};

                const callbackName = '__majlisGoogleMapsLoaded';
                window.google.maps[callbackName] = () => {
                    resolve(window.google.maps);
                    delete window.google.maps[callbackName];
                };

                const script = document.createElement('script');
                script.async = true;
                script.defer = true;
                script.dataset.majlisGoogleMaps = 'true';
                script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(this.apiKey)}&v=weekly&loading=async&callback=google.maps.${callbackName}`;
                script.onerror = () => {
                    reject(new Error('Google Maps could not load.'));
                    delete window.google.maps[callbackName];
                };

                document.head.appendChild(script);
            });

            return window.__majlisGoogleMapsPromise;
        },
        async mountAutocomplete() {
            const [{ PlaceAutocompleteElement }] = await Promise.all([
                google.maps.importLibrary('places'),
                google.maps.importLibrary('maps'),
            ]);

            const element = new PlaceAutocompleteElement();

            element.placeholder = @js($pickerSearchLabel);
            element.className = 'block w-full text-sm text-slate-900';

            element.addEventListener('gmp-select', async (event) => {
                await this.handleSelection(event);
            });

            this.$refs.autocompleteHost.innerHTML = '';
            this.$refs.autocompleteHost.appendChild(element);
            this.autocompleteElement = element;
        },
        async handleSelection(event) {
            try {
                const placePrediction = event.placePrediction ?? event.detail?.placePrediction ?? null;

                if (! placePrediction) {
                    throw new Error('Missing place prediction payload.');
                }

                const place = placePrediction.toPlace();

                await place.fetchFields({
                    fields: [
                        'addressComponents',
                        'displayName',
                        'formattedAddress',
                        'googleMapsURI',
                        'location',
                        'viewport',
                    ],
                });

                const normalizedLocation = this.normalizeLocation(place.location);
                const displayName = this.normalizeDisplayName(place.displayName);

                if (! normalizedLocation) {
                    throw new Error('Selected place did not include coordinates.');
                }

                const payload = {
                    addressComponents: (place.addressComponents ?? []).map((component) => ({
                        longText: component.longText ?? null,
                        shortText: component.shortText ?? null,
                        types: Array.isArray(component.types) ? component.types : [],
                    })),
                    displayName,
                    formattedAddress: place.formattedAddress ?? null,
                    googleMapsURI: place.googleMapsURI ?? null,
                    location: normalizedLocation,
                    placeId: placePrediction.placeId ?? null,
                };

                await this.$wire.call(this.applyMethod, this.targetStatePath, payload);

                this.selectedName = displayName;
                this.selectedAddress = place.formattedAddress ?? null;

                this.renderMap(normalizedLocation, place.viewport ?? null);
            } catch (error) {
                console.error(error);
                this.disablePicker(@js($pickerFailureMessage));
            }
        },
        normalizeLocation(location) {
            if (! location) {
                return null;
            }

            const lat = typeof location.lat === 'function' ? location.lat() : location.lat;
            const lng = typeof location.lng === 'function' ? location.lng() : location.lng;

            if (typeof lat !== 'number' || typeof lng !== 'number') {
                return null;
            }

            return { lat, lng };
        },
        normalizeDisplayName(displayName) {
            if (! displayName) {
                return null;
            }

            if (typeof displayName === 'string') {
                return displayName;
            }

            if (typeof displayName.text === 'string') {
                return displayName.text;
            }

            return null;
        },
        async renderMap(location, viewport) {
            const { Map } = await google.maps.importLibrary('maps');

            if (! this.map) {
                this.map = new Map(this.$refs.mapCanvas, {
                    center: location,
                    zoom: 16,
                    fullscreenControl: false,
                    mapTypeControl: false,
                    streetViewControl: false,
                });
            }

            if (viewport) {
                this.map.fitBounds(viewport);
            } else {
                this.map.setCenter(location);
                this.map.setZoom(16);
            }

            this.marker ??= new google.maps.Marker({
                map: this.map,
            });

            this.marker.setPosition(location);
            this.$refs.mapCard.classList.remove('hidden');
        },
        disablePicker(message) {
            this.enabled = false;
            this.loading = false;
            this.errorMessage = message;
            this.autocompleteElement = null;
        },
    }"
    x-init="init()"
    class="mi-institution-location-picker space-y-2"
>
    <div
        x-show="enabled"
        x-cloak
        class="space-y-2"
    >
        @if (filled($pickerTitle) || filled($pickerDescription))
            <div class="space-y-1.5">
                @if (filled($pickerTitle))
                    <h3 class="text-sm font-semibold text-slate-900">{{ $pickerTitle }}</h3>
                @endif

                @if (filled($pickerDescription))
                    <p class="text-sm leading-6 text-slate-600">
                        {{ $pickerDescription }}
                    </p>
                @endif
            </div>
        @endif

        <div class="space-y-2">
            <div
                id="institution-location-search"
                x-ref="autocompleteHost"
                class="mi-institution-location-picker-search w-full rounded-xl border border-slate-200 bg-white shadow-sm transition"
            ></div>

            <p x-show="loading" x-cloak class="text-xs text-slate-500">
                {{ __('Loading Google location search...') }}
            </p>
        </div>

        <div
            x-ref="mapCard"
            class="hidden overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"
        >
            <div x-ref="mapCanvas" class="h-72 w-full"></div>

            <div class="space-y-1 border-t border-slate-100 px-4 py-2">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-600">
                    {{ $pickerSelectedLabel }}
                </p>
                <p x-show="selectedName" x-text="selectedName" class="text-sm font-semibold text-slate-900"></p>
                <p x-show="selectedAddress" x-text="selectedAddress" class="text-sm leading-6 text-slate-600"></p>
            </div>
        </div>
    </div>

    <div
        x-show="! enabled && errorMessage"
        x-cloak
        class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
    >
        <p x-text="errorMessage"></p>
    </div>
</div>
