{{-- ════════════════════════════════════════════════════════════════
     SIDEBAR INSPIRATION — Random Islamic motivational content
     Shared component for event, speaker, and institution sidebars
     ════════════════════════════════════════════════════════════════ --}}
@php
    $inspiration = \App\Models\Inspiration::query()->with('media')->active()->forLocale()->inRandomOrder()->first();
@endphp

@if($inspiration)
    @php
        $category = $inspiration->category;
        $isComic = $category === \App\Enums\InspirationCategory::IslamicComic;
        $inspirationMedia = $inspiration->getFirstMedia('main');
        $mediaThumbUrl = $inspirationMedia?->getAvailableUrl(['thumb']) ?? '';
        $mediaFullUrl = $inspirationMedia?->getUrl() ?? '';
        $hasMedia = filled($mediaThumbUrl);
        $contentHtml = $inspiration->renderContentHtml();
        $contentPreview = $inspiration->contentPreviewText(160);

        $colorMap = [
            'emerald' => ['bg' => 'bg-emerald-50', 'border' => 'border-emerald-100', 'ring' => 'ring-emerald-100/60', 'icon_bg' => 'bg-emerald-100', 'icon_text' => 'text-emerald-600', 'badge_bg' => 'bg-emerald-100', 'badge_text' => 'text-emerald-700', 'source' => 'text-emerald-600/80'],
            'amber'   => ['bg' => 'bg-amber-50', 'border' => 'border-amber-100', 'ring' => 'ring-amber-100/60', 'icon_bg' => 'bg-amber-100', 'icon_text' => 'text-amber-600', 'badge_bg' => 'bg-amber-100', 'badge_text' => 'text-amber-700', 'source' => 'text-amber-600/80'],
            'sky'     => ['bg' => 'bg-sky-50', 'border' => 'border-sky-100', 'ring' => 'ring-sky-100/60', 'icon_bg' => 'bg-sky-100', 'icon_text' => 'text-sky-600', 'badge_bg' => 'bg-sky-100', 'badge_text' => 'text-sky-700', 'source' => 'text-sky-600/80'],
            'violet'  => ['bg' => 'bg-violet-50', 'border' => 'border-violet-100', 'ring' => 'ring-violet-100/60', 'icon_bg' => 'bg-violet-100', 'icon_text' => 'text-violet-600', 'badge_bg' => 'bg-violet-100', 'badge_text' => 'text-violet-700', 'source' => 'text-violet-600/80'],
            'rose'    => ['bg' => 'bg-rose-50', 'border' => 'border-rose-100', 'ring' => 'ring-rose-100/60', 'icon_bg' => 'bg-rose-100', 'icon_text' => 'text-rose-600', 'badge_bg' => 'bg-rose-100', 'badge_text' => 'text-rose-700', 'source' => 'text-rose-600/80'],
            'indigo'  => ['bg' => 'bg-indigo-50', 'border' => 'border-indigo-100', 'ring' => 'ring-indigo-100/60', 'icon_bg' => 'bg-indigo-100', 'icon_text' => 'text-indigo-600', 'badge_bg' => 'bg-indigo-100', 'badge_text' => 'text-indigo-700', 'source' => 'text-indigo-600/80'],
        ];
        $colors = $colorMap[$category->color()] ?? $colorMap['emerald'];
    @endphp

    <div class="scroll-reveal reveal-right revealed" x-data="{ showComicModal: false, showMediaModal: false }" x-intersect.once="$el.classList.add('revealed')" style="--reveal-d: 120ms">

        <div class="rounded-3xl border {{ $colors['border'] }} {{ $colors['bg'] }} p-5 shadow-sm ring-1 {{ $colors['ring'] }}">
            {{-- Category badge --}}
            <div class="flex items-center gap-2.5">
                <div class="rounded-xl {{ $colors['icon_bg'] }} p-2">
                    <x-dynamic-component :component="$category->icon()" class="size-4.5 {{ $colors['icon_text'] }}" />
                </div>
                <span class="rounded-full {{ $colors['badge_bg'] }} px-2.5 py-0.5 text-[11px] font-bold {{ $colors['badge_text'] }} tracking-wide uppercase">
                    {{ $category->label() }}
                </span>
            </div>

            {{-- Title --}}
            <h4 class="mt-3 text-sm font-bold leading-snug text-slate-900">
                {{ $inspiration->title }}
            </h4>

            {{-- Content --}}
            @if($hasMedia)
                <button type="button" @click="showMediaModal = true"
                        class="group mt-3 block w-full overflow-hidden rounded-2xl border border-current/10 bg-white/70">
                    <img src="{{ $mediaThumbUrl }}" alt="{{ $inspiration->title }}"
                         class="h-52 w-full object-cover transition duration-200 group-hover:scale-[1.02]" loading="lazy">
                </button>
            @elseif($isComic)
                <p class="mt-2 text-[13px] leading-relaxed text-slate-600 line-clamp-3">
                    {{ $contentPreview }}
                </p>
                <button @click="showComicModal = true"
                        class="mt-3 inline-flex items-center gap-1.5 rounded-xl {{ $colors['badge_bg'] }} px-3 py-1.5 text-xs font-semibold {{ $colors['badge_text'] }} transition-all duration-200 hover:shadow-sm hover:brightness-95">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                    {{ __('Baca Selanjutnya') }}
                </button>
            @else
                <div class="prose prose-slate mt-2.5 max-w-none border-l-2 border-current/10 pl-3 text-[13px] leading-relaxed prose-p:my-0">
                    {!! $contentHtml !!}
                </div>
            @endif

            {{-- Source attribution --}}
            @if($inspiration->source)
                <p class="mt-2.5 flex items-center gap-1.5 text-[11px] font-medium {{ $colors['source'] }}">
                    <svg class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/></svg>
                    {{ $inspiration->source }}
                </p>
            @endif
        </div>

        {{-- Image modal --}}
        @if($hasMedia)
            <template x-teleport="body">
                <div x-show="showMediaModal" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="transition ease-in duration-200"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                     class="fixed inset-0 z-50 flex items-center justify-center p-4"
                     @keydown.escape.window="showMediaModal = false">
                    <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" @click="showMediaModal = false"></div>

                    <div x-show="showMediaModal"
                         x-transition:enter="transition ease-out duration-300 delay-75"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-200"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         class="relative w-full max-w-6xl">
                        <button type="button" @click="showMediaModal = false"
                                class="absolute right-3 top-3 z-10 inline-flex h-9 w-9 items-center justify-center rounded-full bg-black/50 text-white transition hover:bg-black/70">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>

                        <img src="{{ $mediaFullUrl }}" alt="{{ $inspiration->title }}"
                             class="max-h-[90vh] w-full rounded-2xl object-contain" loading="lazy">
                    </div>
                </div>
            </template>
        @endif

        {{-- Comic modal --}}
        @if(! $hasMedia && $isComic)
            <template x-teleport="body">
                <div x-show="showComicModal" x-cloak
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="transition ease-in duration-200"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                     class="fixed inset-0 z-50 flex items-center justify-center p-4"
                     @keydown.escape.window="showComicModal = false">
                    {{-- Backdrop --}}
                    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="showComicModal = false"></div>

                    {{-- Modal content --}}
                    <div x-show="showComicModal"
                         x-transition:enter="transition ease-out duration-300 delay-100"
                         x-transition:enter-start="opacity-0 scale-95 translate-y-4"
                         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-200"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         class="relative w-full max-w-lg rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200/60">

                        {{-- Header --}}
                        <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                            <div class="flex items-center gap-2.5">
                                <div class="rounded-xl {{ $colors['icon_bg'] }} p-2">
                                    <x-dynamic-component :component="$category->icon()" class="size-4 {{ $colors['icon_text'] }}" />
                                </div>
                                <h3 class="text-base font-bold text-slate-900">{{ $inspiration->title }}</h3>
                            </div>
                            <button @click="showComicModal = false" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        {{-- Body --}}
                        <div class="px-6 py-5">
                            <div class="prose prose-slate max-w-none text-sm leading-relaxed prose-p:my-0">
                                {!! $contentHtml !!}
                            </div>

                            @if($inspiration->source)
                                <p class="mt-4 flex items-center gap-1.5 text-xs font-medium {{ $colors['source'] }}">
                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/></svg>
                                    {{ $inspiration->source }}
                                </p>
                            @endif
                        </div>

                        {{-- Footer --}}
                        <div class="flex justify-end border-t border-slate-100 px-6 py-4">
                            <button @click="showComicModal = false"
                                    class="rounded-xl bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-200">
                                {{ __('Tutup') }}
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        @endif
    </div>
@endif
