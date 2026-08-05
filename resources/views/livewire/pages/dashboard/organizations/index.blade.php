@section('title', __('Organizations') . ' - ' . config('app.name'))

<main class="min-h-screen bg-[#fbf8f1] px-6 py-10 lg:px-12">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div><span class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.18em] text-emerald-800">{{ __('Workspaces') }}</span><h1 class="mt-4 font-heading text-4xl font-bold tracking-tight text-[#0b2a42]">{{ __('Your organizations') }}</h1><p class="mt-2 max-w-2xl text-sm leading-7 text-slate-600">{{ __('Create teams, invite members, and publish events from one shared workspace.') }}</p></div>
            <a href="{{ route('dashboard.organizations.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700">+ {{ __('Create organization') }}</a>
        </div>

        <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @forelse($organizations as $organization)
                <a wire:key="organization-card-{{ $organization->id }}" href="{{ route('dashboard.organizations.show', $organization) }}" wire:navigate class="group rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-emerald-200 hover:shadow-xl hover:shadow-emerald-950/5"><div class="flex items-start justify-between gap-4"><div class="flex size-14 items-center justify-center rounded-2xl bg-emerald-700 text-xl font-bold text-white">{{ mb_strtoupper(mb_substr($organization->name, 0, 1)) }}</div><span class="rounded-full bg-slate-100 px-3 py-1 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-600">{{ $organization->visibility->value }}</span></div><h2 class="mt-6 font-heading text-2xl font-bold text-[#0b2a42] group-hover:text-emerald-800">{{ $organization->name }}</h2><p class="mt-2 line-clamp-3 text-sm leading-6 text-slate-500">{{ $organization->description ?: __('No description yet.') }}</p><div class="mt-6 flex items-center justify-between border-t border-slate-100 pt-4 text-xs font-semibold text-slate-500"><span>{{ trans_choice(':count member|:count members', (int) ($organization->members_count ?? 0), ['count' => $organization->members_count ?? 0]) }}</span><span class="text-emerald-800">{{ __('Open workspace →') }}</span></div></a>
            @empty
                <div class="rounded-3xl border border-dashed border-emerald-200 bg-white p-10 text-center md:col-span-2 xl:col-span-3"><div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-2xl text-emerald-700">+</div><h2 class="mt-5 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Start your first organization') }}</h2><p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">{{ __('Bring your team together and start creating events.') }}</p><a href="{{ route('dashboard.organizations.create') }}" wire:navigate class="mt-6 inline-flex rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-700">{{ __('Create organization') }}</a></div>
            @endforelse
        </div>
    </div>
</main>
