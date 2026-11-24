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
            <a href="{{ route('dashboard.organizations.events.create', $organization) }}" wire:navigate data-signal-event="navigation.organization_event_create_started" data-signal-category="navigation" data-signal-component="organization_workspace" data-signal-control="create_event" data-signal-entity-type="organization" data-signal-entity-id="{{ $organization->id }}" class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700">+ {{ __('Create event') }}</a>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-3xl border border-[#eadfca] bg-white p-5"><p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Members') }}</p><p class="mt-2 text-3xl font-bold text-[#0b2a42]">{{ $members->count() }}</p></div>
            <div class="rounded-3xl border border-[#eadfca] bg-white p-5"><p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Events') }}</p><p class="mt-2 text-3xl font-bold text-[#0b2a42]">{{ count($events) }}</p></div>
            <div class="rounded-3xl border border-[#eadfca] bg-white p-5"><p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Status') }}</p><p class="mt-2 text-3xl font-bold capitalize text-[#0b2a42]">{{ $organization->status->value }}</p></div>
        </div>

        <div class="grid gap-8 xl:grid-cols-[minmax(0,1.25fr)_minmax(340px,0.75fr)]">
            <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('People') }}</p><h2 class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Members and roles') }}</h2><p class="mt-2 text-sm text-slate-500">{{ __('Give each person the access they need.') }}</p></div>
                </div>

                @if($canManageMembers)
                    <form wire:submit="invite" class="mt-6 grid gap-3 rounded-2xl bg-[#fbf8f1] p-4 md:grid-cols-[minmax(0,1fr)_150px_auto] md:items-end">
                        <div><label for="invite-email" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Invite by email') }}</label><input id="invite-email" type="email" wire:model="inviteRecipient" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100" placeholder="name@example.com">@error('inviteRecipient') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror</div>
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
                <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('Events') }}</p>
                    <h2 class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Your organization events') }}</h2>
                    <div class="mt-5 space-y-3">
                        @forelse($events as $event)
                            @php($eventEditUrl = $eventEditUrls[(string) $event->id] ?? null)
                            @php($eventScheduleUrl = $eventScheduleUrls[(string) $event->id] ?? null)
                            @php($eventOfflineAdmissionsUrl = $eventOfflineAdmissionsUrls[(string) $event->id] ?? null)
                            <div wire:key="organization-event-{{ $event->id }}" class="rounded-2xl border border-slate-100 p-4 {{ isset($publicEventIds[(string) $event->id]) ? 'hover:border-emerald-200 hover:bg-emerald-50/40' : 'bg-slate-50' }}">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        @if(isset($publicEventIds[(string) $event->id]))
                                            <a href="{{ $event->publicUrl }}" class="font-semibold text-slate-900 hover:text-emerald-700">{{ $event->title }}</a>
                                        @else
                                            <p class="font-semibold text-slate-900">{{ $event->title }}</p>
                                        @endif
                                        <p class="mt-1 text-xs uppercase tracking-[0.14em] text-slate-400">{{ (string) $event->status }} · {{ $event->visibility }}</p>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        @if($eventScheduleUrl)
                                            <a href="{{ $eventScheduleUrl }}" wire:navigate data-signal-event="navigation.event_schedule_opened" data-signal-category="navigation" data-signal-component="organization_workspace" data-signal-control="schedule" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="shrink-0 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-800 hover:bg-indigo-100">{{ __('Schedule') }}</a>
                                        @endif
                                        @if($eventOfflineAdmissionsUrl)
                                            <a href="{{ $eventOfflineAdmissionsUrl }}" wire:navigate data-signal-event="navigation.event_offline_admissions_opened" data-signal-category="navigation" data-signal-component="organization_workspace" data-signal-control="offline_admissions" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="shrink-0 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-100">{{ __('Admissions') }}</a>
                                        @endif
                                        @if($eventEditUrl)
                                            <a href="{{ $eventEditUrl }}" class="shrink-0 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">{{ __('Edit') }}</a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">{{ __('Create your first event for this organization.') }}</p>
                        @endforelse
                    </div>
                    <a href="{{ route('dashboard.organizations.events.create', $organization) }}" wire:navigate data-signal-event="navigation.organization_event_create_started" data-signal-category="navigation" data-signal-component="organization_workspace" data-signal-control="create_event" data-signal-entity-type="organization" data-signal-entity-id="{{ $organization->id }}" class="mt-5 inline-flex text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ __('Create an event →') }}</a>
                </section>

                <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8"><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('Invitations') }}</p><h2 class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Pending invitations') }}</h2><div class="mt-5 space-y-3">@forelse($invitations as $invitation)<div wire:key="organization-invitation-{{ $invitation->id }}" class="flex items-center justify-between gap-3 rounded-2xl bg-[#fbf8f1] p-3"><div><p class="text-sm font-semibold text-slate-900">{{ $invitation->recipient }}</p><p class="mt-1 text-xs text-slate-500">{{ __('Organization') }} · {{ \AIArmada\Membership\Enums\MemberRole::fromSpatieRoleName($invitation->role)?->label() ?? $invitation->role }}</p></div>@if($canManageMembers && $invitation->isValid)<button type="button" wire:click="revokeInvitation('{{ $invitation->id }}')" class="text-xs font-semibold text-red-600 hover:text-red-800">{{ __('Revoke') }}</button>@endif</div>@empty<p class="text-sm text-slate-500">{{ __('No invitations yet.') }}</p>@endforelse</div></section>

                @if($canManageOrganization)
                    <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8"><p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">{{ __('Controls') }}</p><h2 class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Organization settings') }}</h2><div class="mt-5 flex flex-wrap gap-2">@if($organization->visibility->value === 'private')<button type="button" wire:click="makePublic" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">{{ __('Make public') }}</button>@else<button type="button" wire:click="makePrivate" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700">{{ __('Make private') }}</button>@endif @if($organization->status->value === 'active')<button type="button" wire:click="suspend" wire:confirm="{{ __('Suspend this organization?') }}" class="rounded-xl border border-amber-200 px-3 py-2 text-xs font-semibold text-amber-700">{{ __('Suspend') }}</button><button type="button" wire:click="archive" wire:confirm="{{ __('Archive this organization?') }}" class="rounded-xl border border-red-200 px-3 py-2 text-xs font-semibold text-red-700">{{ __('Archive') }}</button>@else<button type="button" wire:click="restore" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">{{ __('Restore') }}</button>@endif</div></section>
                @endif
            </div>
        </div>

        <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('Donations') }}</p>
                    <h2 class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Donation channels') }}</h2>
                    <p class="mt-2 max-w-3xl text-sm text-slate-500">{{ __('Your organization location decides which donation methods are available. Verified channels appear on the public organization profile.') }}</p>
                </div>
                @if($canManageDonations && ! $channelFormOpen && $donationMethodOptions !== [])
                    <button type="button" wire:click="startChannel" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">{{ __('Add channel') }}</button>
                @endif
                @if($donationPublicUrl)
                    <a href="{{ $donationPublicUrl }}" class="rounded-xl border border-emerald-200 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50">{{ __('View public profile') }}</a>
                @endif
            </div>

            <div class="mt-6 grid gap-8 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Location') }}</p>
                    @if($addressState)
                        <div class="mt-3 space-y-1 text-sm leading-7 text-slate-700">
                            @if($addressState['line1'])<p>{{ $addressState['line1'] }}</p>@endif
                            @if($addressState['line2'])<p>{{ $addressState['line2'] }}</p>@endif
                            @if($addressState['postcode'] || $addressState['city'])<p>{{ trim(($addressState['postcode'] ?? '').' '.($addressState['city'] ?? '')) }}</p>@endif
                            @if($addressState['state'])<p>{{ $addressState['state'] }}</p>@endif
                            @if($addressState['country'])<p>{{ $addressState['country'] }}</p>@endif
                        </div>
                    @else
                        <p class="mt-3 text-sm text-slate-500">{{ __('No location yet. Add the organization country so donation methods can be offered.') }}</p>
                    @endif

                    @if($canManageDonations)
                        <form wire:submit="saveAddress" class="mt-4 grid gap-3 rounded-2xl bg-[#fbf8f1] p-4">
                            <div>
                                <label for="organization-country" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Country') }}</label>
                                <select id="organization-country" wire:model.live="addressCountryId" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                    <option value="">{{ __('Select a country') }}</option>
                                    @foreach($countryOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('addressCountryId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            @if($addressStateOptions !== [])
                                <div>
                                    <label for="organization-state" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('State / region') }}</label>
                                    <select id="organization-state" wire:model="addressStateId" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                        <option value="">{{ __('Not specified') }}</option>
                                        @foreach($addressStateOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('addressStateId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            @endif
                            <div>
                                <label for="organization-line1" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Address line') }}</label>
                                <input id="organization-line1" type="text" wire:model="addressLine1" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                @error('addressLine1') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="organization-line2" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Address line 2') }}</label>
                                    <input id="organization-line2" type="text" wire:model="addressLine2" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                </div>
                                <div>
                                    <label for="organization-postcode" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Postcode') }}</label>
                                    <input id="organization-postcode" type="text" wire:model="addressPostcode" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" class="rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">{{ __('Save location') }}</button>
                                @if($addressState)
                                    <button type="button" wire:click="clearAddress" wire:confirm="{{ __('Clear the organization location?') }}" class="rounded-xl border border-red-100 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">{{ __('Clear') }}</button>
                                @endif
                            </div>
                            @error('donation_channels') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        </form>
                    @endif
                </div>

                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Channels') }}</p>
                    <div class="mt-3 space-y-3">
                        @forelse($donationChannels as $channel)
                            <div wire:key="organization-donation-{{ $channel->id }}" class="rounded-2xl border {{ $channel->is_default ? 'border-emerald-200 bg-emerald-50/40' : 'border-slate-100' }} p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="font-semibold text-slate-900">{{ $channel->label ?: $channel->recipient }}</p>
                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-[0.12em] text-slate-600">{{ $channel->method_display }}</span>
                                            <span class="rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-[0.12em] {{ $channel->status->value === 'verified' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $channel->status->value }}</span>
                                            @if($channel->is_default)<span class="rounded-full bg-emerald-600 px-2.5 py-1 text-[11px] font-bold uppercase tracking-[0.12em] text-white">{{ __('Default') }}</span>@endif
                                        </div>
                                        <p class="mt-2 text-sm text-slate-600">{{ $channel->payment_details }}</p>
                                        <p class="mt-1 text-xs text-slate-400">{{ $channel->recipient }}</p>
                                    </div>
                                    @if($canManageDonations)
                                        <div class="flex shrink-0 gap-2">
                                            <button type="button" wire:click="editChannel('{{ $channel->id }}')" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">{{ __('Edit') }}</button>
                                            <button type="button" wire:click="removeChannel('{{ $channel->id }}')" wire:confirm="{{ __('Remove this donation channel?') }}" class="rounded-lg border border-red-100 px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50">{{ __('Remove') }}</button>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">{{ __('No donation channels yet.') }}</p>
                        @endforelse
                    </div>

                    @if($channelFormOpen)
                        <form wire:submit="saveChannel" class="mt-4 grid gap-3 rounded-2xl bg-[#fbf8f1] p-4 sm:grid-cols-2">
                            <div>
                                <label for="channel-label" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Label') }}</label>
                                <input id="channel-label" type="text" wire:model="channelForm.label" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('e.g. Tabung Masjid') }}">
                            </div>
                            <div>
                                <label for="channel-recipient" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Recipient') }}</label>
                                <input id="channel-recipient" type="text" wire:model="channelForm.recipient" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                @error('channelForm.recipient') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="channel-method" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Method') }}</label>
                                <select id="channel-method" wire:model.live="channelForm.method" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                    @foreach($donationMethodOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if(($channelForm['method'] ?? null) === 'bank_account')
                                <div>
                                    <label for="channel-bank" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Bank') }}</label>
                                    <select id="channel-bank" wire:model="channelForm.bank_code" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                        <option value="">{{ __('Select a bank') }}</option>
                                        @foreach($donationBankOptions as $code => $name)
                                            <option value="{{ $code }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                    @error('channelForm.bank_code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="channel-account" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Account Number') }}</label>
                                    <input id="channel-account" type="text" wire:model="channelForm.account_number" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                    @error('channelForm.account_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="channel-iban" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">IBAN</label>
                                    <input id="channel-iban" type="text" wire:model="channelForm.iban" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                    @error('channelForm.iban') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="channel-swift" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('SWIFT / BIC') }}</label>
                                    <input id="channel-swift" type="text" wire:model="channelForm.swift_bic" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                </div>
                                @if($donationRoutingSupported)
                                    <div>
                                        <label for="channel-routing" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Routing / sort code') }}</label>
                                        <input id="channel-routing" type="text" maxlength="20" wire:model="channelForm.routing_code" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm uppercase outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('e.g. 014 or 081') }}">
                                        @error('channelForm.routing_code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                                <div>
                                    <label for="channel-currency" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Currency') }}</label>
                                    <input id="channel-currency" type="text" maxlength="3" wire:model="channelForm.currency" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm uppercase outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                </div>
                            @endif
                            @if(($channelForm['method'] ?? null) === 'duitnow')
                                <div>
                                    <label for="channel-duitnow-type" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('DuitNow type') }}</label>
                                    <input id="channel-duitnow-type" type="text" wire:model="channelForm.duitnow_type" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                    @error('channelForm.duitnow_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="channel-duitnow-value" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('DuitNow value') }}</label>
                                    <input id="channel-duitnow-value" type="text" wire:model="channelForm.duitnow_value" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                    @error('channelForm.duitnow_value') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            @endif
                            @if(($channelForm['method'] ?? null) === 'ewallet')
                                <div>
                                    <label for="channel-ewallet-provider" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('E-wallet provider') }}</label>
                                    <input id="channel-ewallet-provider" type="text" wire:model="channelForm.ewallet_provider" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                    @error('channelForm.ewallet_provider') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="channel-ewallet-handle" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('E-wallet handle') }}</label>
                                    <input id="channel-ewallet-handle" type="text" wire:model="channelForm.ewallet_handle" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                    @error('channelForm.ewallet_handle') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            @endif
                            <div>
                                <label for="channel-qr" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('QR image') }}</label>
                                <input id="channel-qr" type="file" wire:model="channelQr" accept="image/jpeg,image/png,image/webp" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                @error('channelQr') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                <div wire:loading wire:target="channelQr" class="mt-1 text-xs text-slate-500">{{ __('Uploading…') }}</div>
                            </div>
                            <label class="flex items-center gap-2 self-end text-sm font-semibold text-slate-700">
                                <input type="checkbox" wire:model="channelForm.is_default" class="size-4 rounded border-slate-300 text-emerald-600">
                                {{ __('Default channel') }}
                            </label>
                            <div class="flex flex-wrap gap-2 sm:col-span-2">
                                <button type="submit" class="rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">{{ __('Save channel') }}</button>
                                <button type="button" wire:click="cancelChannel" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-white">{{ __('Cancel') }}</button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </section>
    </div>
</main>
