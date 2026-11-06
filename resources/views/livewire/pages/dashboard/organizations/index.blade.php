@section('title', __('Workspaces') . ' - ' . config('app.name'))

<main class="min-h-screen bg-[#fbf8f1] px-6 py-10 lg:px-12">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div><span class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.18em] text-emerald-800">{{ __('Workspaces') }}</span><h1 class="mt-4 font-heading text-4xl font-bold tracking-tight text-[#0b2a42]">{{ __('Your workspaces') }}</h1><p class="mt-2 max-w-2xl text-sm leading-7 text-slate-600">{{ __('Manage the organizations, institutions, and speakers you are a member of.') }}</p></div>
            <a href="{{ route('dashboard.organizations.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700">+ {{ __('Create organization') }}</a>
        </div>

        <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @forelse($organizations as $organization)
                <a wire:key="organization-card-{{ $organization->id }}" href="{{ route('dashboard.organizations.show', $organization) }}" wire:navigate class="group rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-emerald-200 hover:shadow-xl hover:shadow-emerald-950/5"><div class="flex items-start justify-between gap-4"><div class="flex size-14 items-center justify-center rounded-2xl bg-emerald-700 text-xl font-bold text-white">{{ mb_strtoupper(mb_substr($organization->name, 0, 1)) }}</div><span class="rounded-full bg-slate-100 px-3 py-1 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-600">{{ $organization->visibility->value }}</span></div><h2 class="mt-6 font-heading text-2xl font-bold text-[#0b2a42] group-hover:text-emerald-800">{{ $organization->name }}</h2><p class="mt-2 line-clamp-3 text-sm leading-6 text-slate-500">{{ $organization->description ?: __('No description yet.') }}</p><div class="mt-6 flex items-center justify-between border-t border-slate-100 pt-4 text-xs font-semibold text-slate-500"><span>{{ trans_choice(':count member|:count members', (int) ($organization->members_count ?? 0), ['count' => $organization->members_count ?? 0]) }}</span><span class="text-emerald-800">{{ __('Open workspace →') }}</span></div></a>
            @empty
                <div class="rounded-3xl border border-dashed border-emerald-200 bg-white p-10 text-center md:col-span-2 xl:col-span-3"><div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-2xl text-emerald-700">+</div><h2 class="mt-5 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Start your first organization') }}</h2><p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">{{ __('Bring your team together and start creating events.') }}</p><a href="{{ route('dashboard.organizations.create') }}" wire:navigate class="mt-6 inline-flex rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-700">{{ __('Create organization') }}</a></div>
            @endforelse
        </div>

        @if($institutions->isNotEmpty())
            <section class="mt-14">
                <div class="flex items-end justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">{{ __('Institutions') }}</p><h2 class="mt-2 font-heading text-3xl font-bold tracking-tight text-[#0b2a42]">{{ __('Institution management') }}</h2><p class="mt-2 text-sm leading-7 text-slate-600">{{ __('Open the institution dashboard to manage events, members, and submissions.') }}</p></div></div>
                <div class="mt-6 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    @foreach($institutions as $institution)
                        <a wire:key="institution-card-{{ $institution->id }}" href="{{ route('dashboard.institutions', ['institution' => $institution->getKey()]) }}" wire:navigate class="group rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-emerald-200 hover:shadow-xl hover:shadow-emerald-950/5"><div class="flex items-start justify-between gap-4"><div class="flex size-14 items-center justify-center rounded-2xl bg-[#0b2a42] text-xl font-bold text-white">{{ mb_strtoupper(mb_substr((string) $institution->name, 0, 1)) }}</div><span class="rounded-full bg-emerald-50 px-3 py-1 text-[10px] font-bold uppercase tracking-[0.14em] text-emerald-800">{{ __('Institution') }}</span></div><h3 class="mt-6 font-heading text-2xl font-bold text-[#0b2a42] group-hover:text-emerald-800">{{ $institution->name }}</h3><p class="mt-2 line-clamp-3 text-sm leading-6 text-slate-500">{{ $institution->description ?: __('Institution workspace') }}</p><div class="mt-6 flex items-center justify-between border-t border-slate-100 pt-4 text-xs font-semibold text-slate-500"><span>{{ trans_choice(':count member|:count members', (int) ($institution->members_count ?? 0), ['count' => $institution->members_count ?? 0]) }}</span><span class="text-emerald-800">{{ __('Manage →') }}</span></div></a>
                    @endforeach
                </div>
            </section>
        @endif

        @if($persons->isNotEmpty())
            <section class="mt-14">
                <div><p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">{{ __('Speakers') }}</p><h2 class="mt-2 font-heading text-3xl font-bold tracking-tight text-[#0b2a42]">{{ __('Speaker management') }}</h2><p class="mt-2 text-sm leading-7 text-slate-600">{{ __('Keep the speaker profile accurate and manage its trusted members.') }}</p></div>
                <div class="mt-6 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    @foreach($persons as $person)
                        @php($personBio = is_array($person->bio) ? (data_get($person->bio, app()->getLocale()) ?: data_get($person->bio, 'en') ?: '') : $person->bio)
                        <a wire:key="person-card-{{ $person->id }}" href="{{ route('dashboard.persons', $person) }}" wire:navigate class="group rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-emerald-200 hover:shadow-xl hover:shadow-emerald-950/5"><div class="flex items-start justify-between gap-4"><div class="flex size-14 items-center justify-center rounded-2xl bg-amber-600 text-xl font-bold text-white">{{ mb_strtoupper(mb_substr((string) $person->formatted_name, 0, 1)) }}</div><span class="rounded-full bg-amber-50 px-3 py-1 text-[10px] font-bold uppercase tracking-[0.14em] text-amber-800">{{ __('Speaker') }}</span></div><h3 class="mt-6 font-heading text-2xl font-bold text-[#0b2a42] group-hover:text-emerald-800">{{ $person->formatted_name }}</h3><p class="mt-2 line-clamp-3 text-sm leading-6 text-slate-500">{{ $personBio ?: __('Speaker profile workspace') }}</p><div class="mt-6 flex items-center justify-between border-t border-slate-100 pt-4 text-xs font-semibold text-slate-500"><span>{{ trans_choice(':count member|:count members', (int) ($person->members_count ?? 0), ['count' => $person->members_count ?? 0]) }}</span><span class="text-emerald-800">{{ __('Manage →') }}</span></div></a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</main>
