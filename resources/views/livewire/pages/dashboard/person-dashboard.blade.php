@section('title', $person->formatted_name . ' - ' . config('app.name'))

<main class="min-h-screen bg-[#fbf8f1] px-6 py-10 lg:px-12">
    <div class="mx-auto max-w-7xl space-y-8">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('dashboard.organizations.index') }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">← {{ __('All workspaces') }}</a>
                <div class="mt-5 flex items-start gap-4">
                    <div class="flex size-16 shrink-0 items-center justify-center rounded-3xl bg-amber-600 text-2xl font-bold text-white shadow-lg shadow-amber-900/15">{{ mb_strtoupper(mb_substr((string) $person->formatted_name, 0, 1)) }}</div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2"><h1 class="font-heading text-4xl font-bold tracking-tight text-[#0b2a42]">{{ $person->formatted_name }}</h1><span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-amber-800">{{ __('Speaker') }}</span></div>
                        <p class="mt-2 max-w-2xl text-sm leading-7 text-slate-600">{{ $personBio }}</p>
                    </div>
                </div>
            </div>
            @if($canEditPerson)
                <a href="{{ route('contributions.suggest-update', ['subjectType' => \App\Enums\ContributionSubjectType::Person->publicRouteSegment(), 'subjectId' => $person->slug]) }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700">{{ __('Edit speaker profile') }}</a>
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-3xl border border-[#eadfca] bg-white p-5"><p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Members') }}</p><p class="mt-2 text-3xl font-bold text-[#0b2a42]">{{ $members->count() }}</p></div>
            <div class="rounded-3xl border border-[#eadfca] bg-white p-5"><p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Profile') }}</p><p class="mt-2 text-3xl font-bold capitalize text-[#0b2a42]">{{ $person->status }}</p></div>
            <div class="rounded-3xl border border-[#eadfca] bg-white p-5"><p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Access') }}</p><p class="mt-2 text-3xl font-bold text-[#0b2a42]">{{ $canManageMembers ? __('Manager') : __('Member') }}</p></div>
        </div>

        <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8" aria-labelledby="speaker-events-heading">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('Majlis') }}</p>
                    <h2 id="speaker-events-heading" class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Manage speaker events') }}</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">{{ __('View all events where this speaker is listed, including drafts and submissions waiting for review.') }}</p>
                </div>

                @if($canCreateEvents)
                    <a
                        href="{{ route('dashboard.events.create-advanced', ['person' => $person->id]) }}"
                        wire:navigate
                        data-signal-event="navigation.person_event_create_started"
                        data-signal-category="navigation"
                        data-signal-component="person_dashboard"
                        data-signal-control="create_event"
                        data-signal-entity-type="person"
                        data-signal-entity-id="{{ $person->id }}"
                        class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700"
                    >
                        + {{ __('Tambah Majlis') }}
                    </a>
                @endif
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl bg-[#fbf8f1] p-4">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-400">{{ __('All events') }}</p>
                    <p class="mt-2 text-2xl font-bold text-[#0b2a42]">{{ $eventStats['total'] }}</p>
                </div>
                <div class="rounded-2xl bg-[#fbf8f1] p-4">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-400">{{ __('Upcoming') }}</p>
                    <p class="mt-2 text-2xl font-bold text-[#0b2a42]">{{ $eventStats['upcoming'] }}</p>
                </div>
                <div class="rounded-2xl bg-amber-50 p-4">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">{{ __('Needs attention') }}</p>
                    <p class="mt-2 text-2xl font-bold text-amber-950">{{ $eventStats['needs_attention'] }}</p>
                </div>
            </div>

            <div class="mt-6 grid gap-3 md:grid-cols-[minmax(0,1fr)_190px_120px]">
                <label class="sr-only" for="speaker-event-search">{{ __('Search events') }}</label>
                <input id="speaker-event-search" type="search" wire:model.live.debounce.400ms="eventSearch" placeholder="{{ __('Search by event title or institution') }}" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                <label class="sr-only" for="speaker-event-status">{{ __('Status') }}</label>
                <select id="speaker-event-status" wire:model.live="eventStatus" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                    @foreach($this->eventStatusOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <label class="sr-only" for="speaker-event-per-page">{{ __('Per page') }}</label>
                <select id="speaker-event-per-page" wire:model.live="eventPerPage" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                    @foreach([8, 15, 25] as $perPage)
                        <option value="{{ $perPage }}">{{ $perPage }} / {{ __('page') }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mt-6 divide-y divide-slate-100">
                @forelse($events as $event)
                    @php
                        $eventEditUrl = $eventEditUrls[(string) $event->getKey()] ?? null;
                        $institutionLabel = $event->institution?->name ?: __('No institution');
                    @endphp
                    <article wire:key="person-event-{{ $event->getKey() }}" class="flex flex-col gap-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                @if($eventEditUrl || $event->isPubliclyReachable())
                                    <a href="{{ $eventEditUrl ?: route('events.show', $event) }}" wire:navigate class="font-semibold text-slate-900 hover:text-emerald-700">{{ $event->title }}</a>
                                @else
                                    <span class="font-semibold text-slate-900">{{ $event->title }}</span>
                                @endif
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-[0.12em] text-slate-600">{{ $this->eventStatusLabel($event->status) }}</span>
                            </div>
                            <p class="mt-2 text-sm text-slate-500">{{ $this->formatEventSchedule($event) }} · {{ $institutionLabel }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            @if(($event->dashboard_registrations_count ?? 0) > 0)
                                <span class="text-xs font-semibold text-slate-500">{{ $event->dashboard_registrations_count }} {{ __('registrations') }}</span>
                            @endif
                            @if($eventEditUrl)
                                <a href="{{ $eventEditUrl }}" class="inline-flex items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">{{ __('Edit') }}</a>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-300 px-5 py-10 text-center">
                        <p class="font-semibold text-slate-800">{{ __('No events match the current filters.') }}</p>
                        <p class="mt-2 text-sm text-slate-500">{{ __('Try adjusting the search or create the first event for this speaker.') }}</p>
                    </div>
                @endforelse
            </div>

            @if($events->hasPages())
                <div class="mt-6 border-t border-slate-100 pt-5">
                    {{ $events->links() }}
                </div>
            @endif
        </section>

        <div class="grid gap-8 xl:grid-cols-[minmax(0,1.25fr)_minmax(340px,0.75fr)]">
            <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8">
                <div><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('People') }}</p><h2 class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Members and roles') }}</h2><p class="mt-2 text-sm text-slate-500">{{ __('Give each person the access they need.') }}</p></div>

                @if($canManageMembers)
                    <form wire:submit="invite" class="mt-6 grid gap-3 rounded-2xl bg-[#fbf8f1] p-4 md:grid-cols-[minmax(0,1fr)_150px_auto] md:items-end">
                        <div><label for="person-invite-email" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Invite by email') }}</label><input id="person-invite-email" type="email" wire:model="inviteEmail" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100" placeholder="name@example.com">@error('inviteEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror</div>
                        <div><label for="person-invite-role" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Role') }}</label><select id="person-invite-role" wire:model="inviteRole" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">@foreach($roleOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                        <button type="submit" class="h-11 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800">{{ __('Send invite') }}</button>
                    </form>
                @endif

                <div class="mt-6 divide-y divide-slate-100">
                    @forelse($members as $member)
                        @php($memberRole = \AIArmada\Membership\Enums\MemberRole::fromSpatieRoleName((string) data_get($member->pivot, 'role')))
                        <div wire:key="person-member-{{ $member->id }}" class="flex flex-col gap-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3"><div class="flex size-10 items-center justify-center rounded-full bg-amber-50 font-bold text-amber-800">{{ mb_strtoupper(mb_substr((string) $member->name, 0, 1)) }}</div><div><p class="font-semibold text-slate-900">{{ $member->name }}</p><p class="text-sm text-slate-500">{{ $member->email }}</p></div></div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if($editingMemberId === $member->id)
                                    <select wire:model="editingRole" class="h-9 rounded-lg border border-slate-200 px-2 text-sm">@foreach($roleOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select><button type="button" wire:click="saveMemberRole" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">{{ __('Save') }}</button><button type="button" wire:click="cancelEditingMember" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600">{{ __('Cancel') }}</button>
                                @else
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold uppercase tracking-[0.14em] text-slate-600">{{ $memberRole?->label() ?? data_get($member->pivot, 'role') }}</span>
                                    @if($canManageMembers && $memberRole !== \AIArmada\Membership\Enums\MemberRole::Owner)
                                        <button type="button" wire:click="startEditingMember('{{ $member->id }}')" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">{{ __('Edit role') }}</button><button type="button" wire:click="removeMember('{{ $member->id }}')" wire:confirm="{{ __('Remove this member?') }}" class="rounded-lg border border-red-100 px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50">{{ __('Remove') }}</button>
                                    @endif
                                    @if($canTransferOwnership && $memberRole !== \AIArmada\Membership\Enums\MemberRole::Owner)
                                        <button type="button" wire:click="transferOwnership('{{ $member->id }}')" wire:confirm="{{ __('Transfer ownership to this member?') }}" data-signal-event="membership.person_ownership_transfer_started" data-signal-category="membership" data-signal-component="person_workspace" data-signal-control="transfer_ownership" data-signal-entity-type="person" data-signal-entity-id="{{ $person->id }}" class="rounded-lg border border-amber-200 px-3 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-50">{{ __('Make owner') }}</button>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="py-8 text-sm text-slate-500">{{ __('No members yet.') }}</p>
                    @endforelse
                </div>
            </section>

            <div class="space-y-8">
                <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8"><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('Invitations') }}</p><h2 class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Pending invitations') }}</h2><div class="mt-5 space-y-3">@forelse($invitations as $invitation)<div wire:key="person-invitation-{{ $invitation->id }}" class="flex items-center justify-between gap-3 rounded-2xl bg-[#fbf8f1] p-3"><div><p class="text-sm font-semibold text-slate-900">{{ $invitation->email }}</p><p class="mt-1 text-xs text-slate-500">{{ $invitation->subject_type?->label() ?? __('Speaker') }} · {{ \AIArmada\Membership\Enums\MemberRole::fromSpatieRoleName($invitation->role)?->label() ?? $invitation->role }}</p></div>@if($canManageMembers && $invitation->isValid())<button type="button" wire:click="revokeInvitation('{{ $invitation->id }}')" class="text-xs font-semibold text-red-600 hover:text-red-800">{{ __('Revoke') }}</button>@endif</div>@empty<p class="text-sm text-slate-500">{{ __('No invitations yet.') }}</p>@endforelse</div></section>

                <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8"><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('Profile') }}</p><h2 class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Speaker profile') }}</h2><p class="mt-2 text-sm leading-6 text-slate-500">{{ __('Keep the public profile accurate so visitors can find the right information.') }}</p>@if($canEditPerson)<a href="{{ route('contributions.suggest-update', ['subjectType' => \App\Enums\ContributionSubjectType::Person->publicRouteSegment(), 'subjectId' => $person->slug]) }}" wire:navigate class="mt-5 inline-flex text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ __('Edit speaker profile →') }}</a>@endif</section>
            </div>
        </div>
    </div>
</main>
