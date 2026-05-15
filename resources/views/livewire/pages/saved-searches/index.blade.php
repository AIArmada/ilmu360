@section('title', __('Saved Searches') . ' - ' . config('app.name'))

<div class="bg-slate-50 min-h-screen py-12 pb-32">
    <div class="container mx-auto px-6 lg:px-12">
        <div class="max-w-5xl mx-auto space-y-8">
            <div class="text-center">
                <h1 class="font-heading text-4xl font-bold text-slate-900">{{ __('Saved Searches') }}</h1>
                <p class="text-slate-500 mt-3 text-lg">
                    {{ __('Keep the filters you use often so you can open the same search again without setting everything from scratch.') }}
                </p>
            </div>

            {{-- Create section: only show when arriving from /majlis with actual filters --}}
            @if ($hasFilters)
                <section class="rounded-3xl border border-slate-100 bg-white p-6 shadow-sm">
                    <div class="mb-4 space-y-2">
                        <h2 class="font-heading text-xl font-bold text-slate-900">{{ __('Save this search') }}</h2>
                        <p class="text-sm text-slate-500">
                            {{ __('Give this search a name so you can find it again quickly.') }}
                        </p>
                    </div>

                    <form wire:submit="save" class="space-y-4">
                        <div class="space-y-2">
                            <label for="saved-search-name"
                                class="text-sm font-semibold text-slate-700">{{ __('Search name') }}</label>
                            <input id="saved-search-name" type="text" wire:model.blur="name"
                                placeholder="{{ __('e.g. Kuliah Maghrib KL') }}"
                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                            @error('name')
                                <p class="text-sm text-danger-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="max-w-sm space-y-2">
                            <label for="saved-search-notify"
                                class="text-sm font-semibold text-slate-700">{{ __('Update frequency') }}</label>
                            <select id="saved-search-notify" wire:model="notify"
                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                @foreach($this->notifyOptions() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('notify')
                                <p class="text-sm text-danger-600">{{ $message }}</p>
                            @enderror
                        </div>

                        @php
                            $capturedFilters = $this->formatCapturedFilters($filters, $radius_km, $lat, $lng);
                            $hasCriteria = $query || $capturedFilters !== [];
                        @endphp

                        @if($hasCriteria)
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-2">
                                    {{ __('What will be saved') }}
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    @if($query)
                                        <span
                                            class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">
                                            {{ __('Search term:') }} {{ $query }}
                                        </span>
                                    @endif
                                    @foreach($capturedFilters as $capturedFilter)
                                        <span
                                            class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
                                            {{ $capturedFilter['label'] }}: {{ $capturedFilter['value'] }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="flex justify-end">
                            <button type="submit"
                                class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 transition-colors">
                                {{ __('Save this search') }}
                            </button>
                        </div>
                    </form>
                </section>
            @endif

            <section class="space-y-4">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Searches you saved') }}</h2>
                    <a href="{{ route('events.index') }}" wire:navigate
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition-colors shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        {{ __('Create another saved search') }}
                    </a>
                </div>

                <div wire:loading.delay.short wire:target="save,startEdit,cancelEdit,update,delete">
                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach(range(1, 4) as $index)
                            <div class="animate-pulse rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="w-full space-y-2">
                                        <div class="h-5 w-2/3 rounded-full bg-slate-200"></div>
                                        <div class="h-4 w-1/3 rounded-full bg-slate-100"></div>
                                    </div>
                                    <div class="h-6 w-20 rounded-full bg-slate-100"></div>
                                </div>

                                <div class="mt-4 space-y-2">
                                    <div class="h-4 w-full rounded-full bg-slate-100"></div>
                                    <div class="h-4 w-5/6 rounded-full bg-slate-100"></div>
                                </div>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <div class="h-6 w-24 rounded-full bg-slate-100"></div>
                                    <div class="h-6 w-20 rounded-full bg-slate-100"></div>
                                    <div class="h-6 w-28 rounded-full bg-slate-100"></div>
                                </div>

                                <div class="mt-5 flex gap-3">
                                    <div class="h-9 w-24 rounded-lg bg-slate-200"></div>
                                    <div class="h-9 w-20 rounded-lg bg-slate-100"></div>
                                    <div class="h-9 w-20 rounded-lg bg-slate-100"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div wire:loading.remove wire:target="save,startEdit,cancelEdit,update,delete">
                @if($this->savedSearches->isEmpty())
                    <div class="rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center">
                        <p class="text-slate-600 font-medium">{{ __('You do not have any saved searches yet.') }}</p>
                        <p class="text-slate-500 text-sm mt-1">
                            {{ __('Go to the events page, choose the filters you want, then save that search from there.') }}
                        </p>
                        <a href="{{ route('events.index') }}" wire:navigate
                            class="inline-flex items-center gap-2 mt-4 px-5 py-2.5 rounded-xl bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition-colors">
                            {{ __('Browse Events') }}
                        </a>
                    </div>
                @else
                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach($this->savedSearches as $savedSearch)
                            <article wire:key="saved-search-{{ $savedSearch->id }}"
                                class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">

                                {{-- View mode --}}
                                @if($editingId !== $savedSearch->id)
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <h3 class="font-heading text-lg font-bold text-slate-900">{{ $savedSearch->name }}</h3>
                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ __('Updated :time', ['time' => $savedSearch->updated_at?->diffForHumans()]) }}
                                            </p>
                                        </div>
                                        <span
                                            class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 shrink-0">
                                            {{ $this->notifyLabel($savedSearch->notify) }}
                                        </span>
                                    </div>

                                    @if($savedSearch->query)
                                        <p class="mt-3 text-sm text-slate-600">
                                            <span class="font-semibold text-slate-800">{{ __('Search term:') }}</span>
                                            {{ $savedSearch->query }}
                                        </p>
                                    @endif

                                    @if(is_array($savedSearch->filters) && $savedSearch->filters !== [])
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @foreach($this->formatCapturedFilters($savedSearch->filters, $savedSearch->radius_km, $savedSearch->lat, $savedSearch->lng) as $capturedFilter)
                                                <span
                                                    class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                                    {{ $capturedFilter['label'] }}: {{ $capturedFilter['value'] }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif

                                    <div class="mt-4 flex items-center gap-3">
                                        <a href="{{ route('events.index', $this->toEventQueryParams($savedSearch)) }}" wire:navigate
                                            class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-600 transition-colors">
                                            {{ __('Open Search') }}
                                        </a>
                                        <button type="button" wire:click="startEdit('{{ $savedSearch->id }}')"
                                            class="inline-flex items-center justify-center rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition-colors gap-1.5">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                            {{ __('Edit') }}
                                        </button>
                                        <button type="button" wire:click="delete('{{ $savedSearch->id }}')"
                                            wire:confirm="{{ __('Delete this saved search?') }}"
                                            class="inline-flex items-center justify-center rounded-lg border border-red-200 px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50 transition-colors">
                                            {{ __('Delete') }}
                                        </button>
                                    </div>

                                    {{-- Edit mode --}}
                                @else
                                    <div class="space-y-4">
                                        <div class="flex items-center justify-between">
                                            <span
                                                class="text-xs font-bold uppercase tracking-wide text-emerald-600">{{ __('Editing search') }}</span>
                                            <button type="button" wire:click="cancelEdit"
                                                class="text-slate-400 hover:text-slate-700 transition-colors">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>

                                        <div class="space-y-2">
                                            <label for="edit-name-{{ $savedSearch->id }}"
                                                class="text-sm font-semibold text-slate-700">{{ __('Search name') }}</label>
                                            <input id="edit-name-{{ $savedSearch->id }}" type="text" wire:model.blur="editName"
                                                class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                            @error('editName')
                                                <p class="text-sm text-danger-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div class="space-y-2">
                                            <label for="edit-notify-{{ $savedSearch->id }}"
                                                class="text-sm font-semibold text-slate-700">{{ __('Update frequency') }}</label>
                                            <select id="edit-notify-{{ $savedSearch->id }}" wire:model="editNotify"
                                                class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none">
                                                @foreach($this->notifyOptions() as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            @error('editNotify')
                                                <p class="text-sm text-danger-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div class="flex items-center gap-3 pt-1">
                                            <button type="button" wire:click="update('{{ $savedSearch->id }}')"
                                                class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 transition-colors">
                                                {{ __('Save Changes') }}
                                            </button>
                                            <button type="button" wire:click="cancelEdit"
                                                class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors">
                                                {{ __('Cancel') }}
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
                </div>
            </section>
        </div>
    </div>
</div>
