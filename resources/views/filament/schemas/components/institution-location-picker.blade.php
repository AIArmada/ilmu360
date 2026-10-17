@php
    $pickerTitle = $title ?? null;
    $pickerDescription = $description ?? null;
    $pickerSearchLabel = $searchLabel ?? __('Search for an institution or address');
    $pickerFieldLabel = $label ?? $pickerSearchLabel;
    $pickerSelectedLabel = $selectedLabel ?? __('Selected location');
    $pickerFailureMessage = $failureMessage ?? __('Location search is temporarily unavailable. Continue with the manual address fields below.');
    $pickerApplyMethod = $applyMethod ?? 'applyLocationPickerSelection';
    $pickerPhotoPreviewsMethod = $photoPreviewsMethod ?? 'loadPlacePhotoPreviews';
    $pickerRequiresSelection = (bool) ($requiredSelection ?? false);
    $pickerTargetStatePath = $targetStatePath ?? $getStatePath();
    $pickerOwnerStatePath = $ownerStatePath ?? null;
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
    x-data="{
        apiKey: @js($mapsApiKey),
        applyMethod: @js($pickerApplyMethod),
        photoPreviewsMethod: @js($pickerPhotoPreviewsMethod),
        requiredSelection: @js($pickerRequiresSelection),
        targetStatePath: @js($pickerTargetStatePath),
        ownerStatePath: @js($pickerOwnerStatePath),
        enabled: true,
        loading: true,
        errorMessage: null,
        selectedName: null,
        selectedAddress: null,
        selectedPlaceId: null,
        selectionNonce: null,
        photos: [],
        photosLoading: false,
        map: null,
        marker: null,
        autocompleteElement: null,
        async init() {
            try {
                if (this.ownerStatePath) {
                    this.selectionNonce = this.$wire.$get(`${this.targetStatePath}.maps_selection_nonce`);
                }

                this.enabled = true;
                this.errorMessage = null;
                await this.loadGoogleMaps();
                await this.mountAutocomplete();
                this.loading = false;
            } catch (error) {
                console.error(error);
                this.disablePicker(@js($pickerFailureMessage));
            }
        },
        async loadGoogleMaps() {
            if (! this.apiKey) {
                throw new Error('Google Maps API key is unavailable.');
            }

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

                this.photos = [];
                this.photosLoading = false;
                this.selectedName = null;
                this.selectedAddress = null;
                this.selectedPlaceId = null;
                this.$refs.mapCard.classList.add('hidden');

                const selectionPayload = {
                    placeId: placePrediction.placeId ?? null,
                };

                if (this.ownerStatePath) {
                    selectionPayload.ownerStatePath = this.ownerStatePath;
                    selectionPayload.nonce = this.selectionNonce;
                }

                const selection = await this.$wire.call(this.applyMethod, this.targetStatePath, selectionPayload);
                const normalizedLocation = this.normalizeLocation({
                    lat: selection?.latitude,
                    lng: selection?.longitude,
                });

                if (! normalizedLocation) {
                    throw new Error('Google Maps could not verify this place. Select it again.');
                }

                this.errorMessage = null;
                this.selectedName = selection.google_display_name ?? null;
                this.selectedAddress = selection.formatted_address ?? null;
                this.selectedPlaceId = selection.google_place_id ?? null;
                this.renderMap(normalizedLocation, null);

                if (this.requiredSelection && this.selectedPlaceId) {
                    this.loadPhotos(this.selectedPlaceId);
                }
            } catch (error) {
                console.error(error);
                this.photos = [];
                this.selectedName = null;
                this.selectedAddress = null;
                this.selectedPlaceId = null;
                this.disablePicker(@js($pickerFailureMessage));
            }
        },
        async loadPhotos(placeId) {
            this.photosLoading = true;

            try {
                const photoArguments = [placeId];

                if (this.ownerStatePath) {
                    photoArguments.push(this.targetStatePath, this.ownerStatePath);
                }

                const photos = await this.$wire.call(this.photoPreviewsMethod, ...photoArguments);

                if (this.selectedPlaceId === placeId) {
                    this.photos = Array.isArray(photos) ? photos : [];
                }
            } catch (error) {
                console.error(error);
            } finally {
                if (this.selectedPlaceId === placeId) {
                    this.photosLoading = false;
                }
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
            this.enabled = this.requiredSelection;
            this.loading = false;
            this.errorMessage = message;
        },
        retry() {
            this.errorMessage = null;
            this.loading = true;
            this.enabled = true;
            this.init();
        },
    }"
    x-init="init()"
    class="mi-institution-location-picker space-y-2"
>
    <div
        wire:ignore
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

            <div x-show="photos.length > 0" x-cloak class="space-y-3 border-t border-slate-100 px-4 py-4">
                <div>
                    <p class="text-sm font-semibold text-slate-900">{{ __('Google Maps photos') }}</p>
                    <p class="mt-1 text-xs leading-5 text-slate-600">{{ __('Preview attributed photos from Google Maps. Add your own institution photos in the Media section below.') }}</p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <template x-for="photo in photos" :key="photo.preview_url">
                        <figure class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                            <img
                                x-bind:src="photo.preview_url"
                                x-bind:width="photo.width ?? undefined"
                                x-bind:height="photo.height ?? undefined"
                                loading="lazy"
                                referrerpolicy="no-referrer"
                                alt="{{ __('Google Maps photo preview') }}"
                                class="h-40 w-full object-cover"
                            >
                            <figcaption class="space-y-1.5 p-3 text-xs text-slate-600">
                                <template x-for="attribution in photo.attributions" :key="attribution.uri ?? attribution.display_name">
                                    <p>
                                        <span>{{ __('Photo by') }}</span>
                                        <a
                                            x-show="attribution.uri"
                                            x-bind:href="attribution.uri"
                                            x-text="attribution.display_name ?? attribution.uri"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="font-medium text-slate-900 underline underline-offset-2"
                                        ></a>
                                        <span x-show="! attribution.uri" x-text="attribution.display_name"></span>
                                    </p>
                                </template>
                                <a
                                    x-bind:href="photo.google_maps_uri"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex font-medium text-slate-900 underline underline-offset-2"
                                >{{ __('View this photo on Google Maps') }}</a>
                            </figcaption>
                        </figure>
                    </template>
                </div>
            </div>
            <p x-show="photosLoading" x-cloak class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500">
                {{ __('Loading Google Maps photo previews...') }}
            </p>
        </div>
    </div>

    <div
        wire:ignore
        x-show="errorMessage"
        x-cloak
        class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
    >
        <p x-text="errorMessage"></p>
        <button
            x-show="requiredSelection"
            type="button"
            @click="retry()"
            class="mt-2 font-semibold underline underline-offset-2"
        >
            {{ __('Retry Google Maps search') }}
        </button>
    </div>

    @if ($pickerRequiresSelection)
        @error($pickerTargetStatePath . '.google_place_id')
            <p role="alert" class="text-sm text-red-600">{{ $message }}</p>
        @enderror
    @endif
</div>
