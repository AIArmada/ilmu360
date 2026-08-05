@section('title', $organization->name . ' - ' . config('app.name'))

<main class="min-h-screen bg-[#fbf8f1] px-6 py-10 lg:px-12">
    <div class="mx-auto max-w-7xl space-y-8">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('dashboard.organizations.index') }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">← {{ __('All organizations') }}</a>
                <div class="mt-5 flex items-start gap-4">
                    <div class="flex size-16 shrink-0 items-center justify-center rounded-3xl bg-emerald-700 text-2xl font-bold text-white shadow-lg shadow-emerald-900/15">{{ mb_strtoupper(mb_substr($organization->name, 0, 1)) }}</div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="font-heading text-4xl font-bold tracking-tight text-[#0b2a42]">{{ $organization->name }}</h1>
                            <span class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] {{ $organization->visibility->value === 'public' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $organization->visibility->value }}</span>
                        </div>
                        <p class="mt-2 max-w-2xl text-sm leading-7 text-slate-600">{{ $organization->description ?: __('A shared workspace for your organization.') }}</p>
                    </div>
                </div>
            </div>
            <a href="{{ route('dashboard.organizations.events.create', $organization) }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700">+ {{ __('Create event') }}</a>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-3xl border border-[#eadfca] bg-white p-5"><p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Members') }}</p><p class="mt-2 text-3xl font-bold text-[#0b2a42]">{{ $members->count() }}</p></div>
            <div class="rounded-3xl border border-[#eadfca] bg-white p-5"><p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Events') }}</p><p class="mt-2 text-3xl font-bold text-[#0b2a42]">{{ $events->count() }}</p></div>
            <div class="rounded-3xl border border-[#eadfca] bg-white p-5"><p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Status') }}</p><p class="mt-2 text-3xl font-bold capitalize text-[#0b2a42]">{{ $organization->status->value }}</p></div>
        </div>

        <div class="grid gap-8 xl:grid-cols-[minmax(0,1.25fr)_minmax(340px,0.75fr)]">
            <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('People') }}</p><h2 class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Members and roles') }}</h2><p class="mt-2 text-sm text-slate-500">{{ __('Give each person the access they need.') }}</p></div>
                </div>

                @if($canManageMembers)
                    <form wire:submit="invite" class="mt-6 grid gap-3 rounded-2xl bg-[#fbf8f1] p-4 md:grid-cols-[minmax(0,1fr)_150px_auto] md:items-end">
                        <div><label for="invite-email" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Invite by email') }}</label><input id="invite-email" type="email" wire:model="inviteEmail" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100" placeholder="name@example.com">@error('inviteEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror</div>
                        <div><label for="invite-role" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Role') }}</label><select id="invite-role" wire:model="inviteRole" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">@foreach($roleOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                        <button type="submit" class="h-11 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800">{{ __('Send invite') }}</button>
                    </form>
                @endif

                <div class="mt-6 divide-y divide-slate-100">
                    @forelse($members as $member)
                        @php($memberRole = \AIArmada\Membership\Enums\MemberRole::fromSpatieRoleName((string) data_get($member->pivot, 'role')))
                        <div wire:key="organization-member-{{ $member->id }}" class="flex flex-col gap-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3"><div class="flex size-10 items-center justify-center rounded-full bg-emerald-50 font-bold text-emerald-800">{{ mb_strtoupper(mb_substr((string) $member->name, 0, 1)) }}</div><div><p class="font-semibold text-slate-900">{{ $member->name }}</p><p class="text-sm text-slate-500">{{ $member->email }}</p></div></div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if($editingMemberId === $member->id)
                                    <select wire:model="editingRole" class="h-9 rounded-lg border border-slate-200 px-2 text-sm">@foreach($roleOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select><button type="button" wire:click="saveMemberRole" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">{{ __('Save') }}</button><button type="button" wire:click="cancelEditingMember" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600">{{ __('Cancel') }}</button>
                                @else
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold uppercase tracking-[0.14em] text-slate-600">{{ $memberRole?->label() ?? data_get($member->pivot, 'role') }}</span>
                                    @if($canManageMembers && $memberRole !== \AIArmada\Membership\Enums\MemberRole::Owner)
                                        <button type="button" wire:click="startEditingMember('{{ $member->id }}')" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">{{ __('Edit role') }}</button><button type="button" wire:click="removeMember('{{ $member->id }}')" wire:confirm="{{ __('Remove this member?') }}" class="rounded-lg border border-red-100 px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50">{{ __('Remove') }}</button>
                                    @endif
                                    @if($canManageMembers && $memberRole !== \AIArmada\Membership\Enums\MemberRole::Owner)
                                        <button type="button" wire:click="transferOwnership('{{ $member->id }}')" wire:confirm="{{ __('Transfer ownership to this member?') }}" class="rounded-lg border border-amber-200 px-3 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-50">{{ __('Make owner') }}</button>
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
                <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8"><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('Events') }}</p><h2 class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Your organization events') }}</h2><div class="mt-5 space-y-3">@forelse($events as $event)@if(isset($publicEventIds[(string) $event->getKey()]))<a wire:key="organization-event-{{ $event->id }}" href="{{ route('events.show', $event) }}" class="block rounded-2xl border border-slate-100 p-4 hover:border-emerald-200 hover:bg-emerald-50/40"><p class="font-semibold text-slate-900">{{ $event->title }}</p><p class="mt-1 text-xs uppercase tracking-[0.14em] text-slate-400">{{ (string) $event->status }} · {{ $event->visibility }}</p></a>@else<div wire:key="organization-event-{{ $event->id }}" class="rounded-2xl border border-slate-100 bg-slate-50 p-4"><p class="font-semibold text-slate-900">{{ $event->title }}</p><p class="mt-1 text-xs uppercase tracking-[0.14em] text-slate-400">{{ (string) $event->status }} · {{ $event->visibility }} · {{ __('Draft workspace event') }}</p></div>@endif @empty<p class="text-sm text-slate-500">{{ __('Create your first event for this organization.') }}</p>@endforelse</div><a href="{{ route('dashboard.organizations.events.create', $organization) }}" wire:navigate class="mt-5 inline-flex text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ __('Create an event →') }}</a></section>

                <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8"><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('Invitations') }}</p><h2 class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Pending invitations') }}</h2><div class="mt-5 space-y-3">@forelse($invitations as $invitation)<div wire:key="organization-invitation-{{ $invitation->id }}" class="flex items-center justify-between gap-3 rounded-2xl bg-[#fbf8f1] p-3"><div><p class="text-sm font-semibold text-slate-900">{{ $invitation->email }}</p><p class="mt-1 text-xs text-slate-500">{{ $invitation->subject_type?->label() ?? __('Organization') }} · {{ \AIArmada\Membership\Enums\MemberRole::fromSpatieRoleName($invitation->role)?->label() ?? $invitation->role }}</p></div>@if($canManageMembers && $invitation->isValid())<button type="button" wire:click="revokeInvitation('{{ $invitation->id }}')" class="text-xs font-semibold text-red-600 hover:text-red-800">{{ __('Revoke') }}</button>@endif</div>@empty<p class="text-sm text-slate-500">{{ __('No invitations yet.') }}</p>@endforelse</div></section>

                @if($canManageOrganization)
                    <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8"><p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">{{ __('Controls') }}</p><h2 class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Organization settings') }}</h2><div class="mt-5 flex flex-wrap gap-2">@if($organization->visibility->value === 'private')<button type="button" wire:click="makePublic" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">{{ __('Make public') }}</button>@else<button type="button" wire:click="makePrivate" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700">{{ __('Make private') }}</button>@endif @if($organization->status->value === 'active')<button type="button" wire:click="suspend" wire:confirm="{{ __('Suspend this organization?') }}" class="rounded-xl border border-amber-200 px-3 py-2 text-xs font-semibold text-amber-700">{{ __('Suspend') }}</button><button type="button" wire:click="archive" wire:confirm="{{ __('Archive this organization?') }}" class="rounded-xl border border-red-200 px-3 py-2 text-xs font-semibold text-red-700">{{ __('Archive') }}</button>@else<button type="button" wire:click="restore" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">{{ __('Restore') }}</button>@endif</div></section>
                @endif
            </div>
        </div>
    </div>
</main>
