@section('title', $organization->name . ' - ' . config('app.name'))

<main class="min-h-screen bg-[#fbf8f1] px-6 py-10 lg:px-12">
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="rounded-[1.5rem] border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8">
            <span class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.18em] text-emerald-800">{{ __('Organization') }}</span>
            <h1 class="mt-4 font-heading text-3xl font-bold tracking-tight text-[#0b2a42]">{{ $organization->name }}</h1>
            @if(filled($organization->description))
                <p class="mt-3 text-sm leading-7 text-slate-600">{{ $organization->description }}</p>
            @endif
        </div>

        <section class="rounded-[1.5rem] border border-emerald-100 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('Donations') }}</p>
            <h2 class="mt-2 font-heading text-xl font-bold text-emerald-950">{{ __('Donation channels') }}</h2>

            <div class="mt-4 space-y-3">
                @forelse($channels as $channel)
                    <div wire:key="public-organization-donation-{{ $channel->id }}" class="rounded-2xl border border-slate-200 bg-white p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-[0.12em] text-slate-600">{{ $channel->method_display }}</span>
                            @if($channel->is_default)
                                <span class="rounded-full bg-emerald-700 px-2.5 py-1 text-[11px] font-bold text-white">{{ __('Default') }}</span>
                            @endif
                            @if(filled($channel->label))
                                <span class="text-xs font-semibold text-slate-500">{{ $channel->label }}</span>
                            @endif
                        </div>
                        <p class="mt-2 font-semibold text-slate-900">{{ $channel->recipient }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ $channel->payment_details }}</p>
                        @if($channel->qr_url)
                            <img src="{{ $channel->qr_url }}" alt="{{ $channel->label ?? $channel->recipient }}" class="mt-3 h-24 w-24 rounded-xl border border-slate-200 bg-white object-cover p-1" loading="lazy">
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('No verified donation channels yet.') }}</p>
                @endforelse
            </div>
        </section>
    </div>
</main>
