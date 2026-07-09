@section('title', $reference->displayTitle() . ' - ' . config('app.name'))
@section('meta_description', \Illuminate\Support\Str::limit(trim(strip_tags((string) $reference->descriptionValue())) ?: __('Lihat rujukan, butiran penerbitan, dan pautan perkongsian untuk :title di :app.', ['title' => $reference->displayTitle(), 'app' => config('app.name')]), 160))
@section('meta_robots', ((string) $reference->status === 'verified') ? 'index, follow' : 'noindex, nofollow')
@section('og_url', route('references.show', $reference))
@section('og_image', $reference->getFirstMediaUrl('front_cover', 'thumb') ?: ($reference->getFirstMediaUrl('front_cover') ?: asset('images/default-mosque-hero.png')))
@section('og_image_alt', __('Rujukan :title', ['title' => $reference->displayTitle()]))

@php
    $referenceTitle = $reference->displayTitle();
    $referenceDescription = trim(strip_tags((string) $reference->descriptionValue()));
    $referenceUrl = route('references.show', $reference);
    $referenceRedirectUrl = route('references.show', $reference, absolute: false);
    $frontCoverUrl = $reference->getFirstMediaUrl('front_cover', 'thumb') ?: ($reference->getFirstMediaUrl('front_cover') ?: asset('images/default-mosque-hero.png'));
    $backCoverUrl = $reference->getFirstMediaUrl('back_cover', 'thumb') ?: $reference->getFirstMediaUrl('back_cover');
    $socialLinks = $reference->socialProfiles
        ->filter(function ($social): bool {
            $resolvedUrl = $social->resolved_url ?? $social->url;

            return filled($social->platform) && filled($resolvedUrl);
        })
        ->values();
    $referenceTypeLabel = \App\Enums\ReferenceType::tryFrom((string) $reference->typeValue())?->getLabel()
        ?? \Illuminate\Support\Str::headline((string) $reference->typeValue());
    $shareText = trim($referenceTitle . ' - ' . config('app.name'));
    $shareLinks = app(\App\Services\ShareTrackingService::class)->redirectLinks(
        $referenceUrl,
        $shareText,
        $referenceTitle,
    );
    $shareData = [
        'title' => $referenceTitle,
        'text' => $referenceDescription !== '' ? \Illuminate\Support\Str::limit($referenceDescription, 180) : __('Lihat rujukan ini di :app', ['app' => config('app.name')]),
        'url' => $referenceUrl,
        'sourceUrl' => $referenceUrl,
        'shareText' => $shareText,
        'fallbackTitle' => $referenceTitle,
        'payloadEndpoint' => route('dawah-share.payload'),
    ];
    $referenceRouteSegment = \App\Enums\ContributionSubjectType::Reference->publicRouteSegment();
    $metadataItems = array_filter([
        $referenceTypeLabel !== '' ? $referenceTypeLabel : null,
        $reference->authorValue(),
        $reference->publisherValue(),
        filled($reference->publication_year) ? (string) $reference->publication_year : null,
    ]);
@endphp

<div class="min-h-screen bg-slate-50/90">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-8">
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="grid gap-0 md:grid-cols-[15rem_minmax(0,1fr)]">
                        <div class="bg-slate-100">
                            <img
                                src="{{ $frontCoverUrl }}"
                                alt="{{ $referenceTitle }}"
                                class="h-full min-h-72 w-full object-cover"
                                loading="lazy"
                            >
                        </div>

                        <div class="space-y-6 p-6 sm:p-8">
                            <div class="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                                <div class="space-y-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if($referenceTypeLabel !== '')
                                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-900">
                                                {{ $referenceTypeLabel }}
                                            </span>
                                        @endif

                                        @if((string) $reference->status !== 'verified')
                                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">
                                                {{ __('Menunggu Kelulusan') }}
                                            </span>
                                        @endif
                                    </div>

                                    <div>
                                        <h1 class="font-heading text-3xl font-bold text-slate-950 sm:text-4xl">{{ $referenceTitle }}</h1>

                                        @if($metadataItems !== [])
                                            <p class="mt-3 text-sm text-slate-500">{{ implode(' · ', $metadataItems) }}</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-3">
                                    <button
                                        type="button"
                                        wire:click="toggleFollow"
                                        wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                    >
                                        @if($this->isFollowing)
                                            {{ __('Mengikuti') }}
                                        @else
                                            {{ __('Ikuti') }}
                                        @endif
                                    </button>

                                    <a
                                        href="#reference-share-panel"
                                        class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                    >
                                        {{ __('Kongsi') }}
                                    </a>
                                </div>
                            </div>

                            @if($referenceDescription !== '')
                                <div class="prose max-w-none text-slate-700 prose-headings:text-slate-950">
                                    <p>{{ $referenceDescription }}</p>
                                </div>
                            @endif

                            @guest
                                <div class="flex flex-wrap gap-3">
                                    <a
                                        href="{{ \App\Support\Auth\IntendedRedirect::registerUrl($referenceRedirectUrl) }}"
                                        class="inline-flex items-center gap-2 rounded-full bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700"
                                    >
                                        {{ __('Daftar') }}
                                    </a>
                                    <a
                                        href="{{ \App\Support\Auth\IntendedRedirect::loginUrl($referenceRedirectUrl) }}"
                                        class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                    >
                                        {{ __('Log Masuk') }}
                                    </a>
                                </div>
                            @endguest
                        </div>
                    </div>
                </section>

                @if($reference->parentReference || $reference->childReferences->isNotEmpty())
                    <section class="scroll-reveal reveal-up revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Keluarga Rujukan') }}</h2>

                        <div class="mt-4 space-y-4">
                            @if($reference->parentReference)
                                <div class="rounded-2xl border border-slate-200 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Rujukan Induk') }}</p>
                                    <a
                                        href="{{ route('references.show', $reference->parentReference) }}"
                                        wire:navigate
                                        class="mt-2 inline-flex text-sm font-semibold text-emerald-700 transition hover:text-emerald-800"
                                    >
                                        {{ $reference->parentReference->displayTitle() }}
                                    </a>
                                </div>
                            @endif

                            @if($reference->childReferences->isNotEmpty())
                                <div class="rounded-2xl border border-slate-200 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Bahagian Berkaitan') }}</p>
                                    <ul class="mt-3 space-y-3">
                                        @foreach($reference->childReferences as $childReference)
                                            <li>
                                                <a
                                                    href="{{ route('references.show', $childReference) }}"
                                                    wire:navigate
                                                    class="inline-flex text-sm font-semibold text-slate-900 transition hover:text-emerald-700"
                                                >
                                                    {{ $childReference->displayTitle() }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif

                @if($socialLinks->isNotEmpty())
                    <section class="scroll-reveal reveal-up revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Media Sosial') }}</h2>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach($socialLinks as $social)
                                <a
                                    href="{{ $social->resolved_url ?? $social->url }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="rounded-2xl border border-slate-200 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                >
                                    {{ \Illuminate\Support\Str::headline((string) $social->platform) }}
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            <aside class="space-y-6">
                @if($backCoverUrl !== '')
                    <section class="scroll-reveal reveal-right revealed rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Kulit Belakang') }}</p>
                        <img
                            src="{{ $backCoverUrl }}"
                            alt="{{ __('Kulit belakang :title', ['title' => $referenceTitle]) }}"
                            class="mt-4 w-full rounded-2xl object-cover"
                            loading="lazy"
                        >
                    </section>
                @endif

                <section class="scroll-reveal reveal-right revealed rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-4">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-slate-400">{{ __('Bantu Semak Rujukan') }}</p>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                {{ __('Jumpa maklumat yang perlu diperbetulkan atau kandungan yang meragukan?') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a
                                href="{{ route('contributions.suggest-update', ['subjectType' => $referenceRouteSegment, 'subjectId' => $reference->slug]) }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700"
                            >
                                {{ __('Cadang Kemaskini') }}
                            </a>
                            <a
                                href="{{ route('reports.create', ['subjectType' => $referenceRouteSegment, 'subjectId' => $reference->slug]) }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                            >
                                {{ __('Lapor') }}
                            </a>
                        </div>
                    </div>
                </section>

                <section id="reference-share-panel" class="scroll-reveal reveal-right revealed">
                    <x-dawah-share-panel
                        heading="{{ __('Kongsi Rujukan') }}"
                        :preview-title="$referenceTitle"
                        :preview-subtitle="$referenceTypeLabel !== '' ? $referenceTypeLabel : null"
                        :share-data="$shareData"
                        :share-links="$shareLinks"
                    />
                </section>

                <x-sidebar-inspiration />
            </aside>
        </div>
    </div>
</div>
