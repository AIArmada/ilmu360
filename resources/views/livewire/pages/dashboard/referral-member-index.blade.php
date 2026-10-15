<div class="mx-auto max-w-4xl px-4 py-8">
    <h1 class="text-2xl font-semibold">Ajak kawan</h1>

    @if ($this->notice !== '')
        <p role="status" class="mt-3 rounded bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $this->notice }}</p>
    @endif

    @if ($this->shareLink !== '')
        <div class="mt-4 rounded border border-emerald-200 bg-emerald-50 px-3 py-3 text-sm">
            <p class="font-medium text-emerald-950">Pautan jemputan sekali sahaja</p>
            <a class="mt-1 inline-block break-all text-emerald-800 underline" href="{{ $this->shareLink }}">{{ $this->shareLink }}</a>
        </div>
    @endif

    @if ($this->activeReferralProgram instanceof \AIArmada\Referrals\Models\ReferralProgram)
        <section class="mt-6 rounded border border-slate-200 bg-white p-4">
            <h2 class="text-lg font-medium">{{ $this->activeReferralProgram->name }}</h2>
            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-medium">Syarat kelayakan</dt>
                    <dd class="mt-1 text-slate-600">{{ $this->activeReferralProgram->qualifier_outcome === 'first_accepted_registration_or_staff_verified_checkin' ? 'Pendaftaran majlis pertama yang diterima atau daftar masuk yang disahkan kakitangan' : 'Syarat kelayakan ditetapkan oleh pengurus program.' }}</dd>
                </div>
                <div>
                    <dt class="font-medium">Had jemputan seorang</dt>
                    <dd class="mt-1 text-slate-600">{{ $this->activeReferralProgram->max_invites_per_referrer ?? 'Tiada had dinyatakan' }}</dd>
                </div>
                <div>
                    <dt class="font-medium">Tempoh jemputan</dt>
                    <dd class="mt-1 text-slate-600">{{ $this->inviteExpiryDays > 0 ? $this->inviteExpiryDays . ' hari' : 'Belum ditetapkan oleh pengurus program' }}</dd>
                </div>
            </dl>
            <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <h3 class="font-medium">Ganjaran pengajak</h3>
                    <p class="mt-1 text-slate-600">{{ $this->rewardDescription($this->activeReferralProgram->referrer_reward) }}</p>
                </div>
                <div>
                    <h3 class="font-medium">Ganjaran rakan</h3>
                    <p class="mt-1 text-slate-600">{{ $this->rewardDescription($this->activeReferralProgram->referee_reward) }}</p>
                </div>
            </div>

            <form wire:submit="createInvite" class="mt-5 grid gap-2 sm:grid-cols-[1fr_auto]">
                <label class="sr-only" for="referral-invite-contact">E-mel atau nombor telefon rakan</label>
                <input id="referral-invite-contact" wire:model="inviteeContact" type="text" maxlength="191" autocomplete="off" placeholder="E-mel atau nombor telefon rakan" class="rounded border border-slate-300 px-3 py-2 text-sm" />
                <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white">Cipta jemputan</button>
            </form>
            @error('inviteeContact')
                <p class="mt-2 text-sm text-rose-700">{{ $message }}</p>
            @enderror
        </section>
    @else
        <p class="mt-4 rounded border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-600">Belum ada program jemputan yang aktif.</p>
    @endif

    <section class="mt-8">
        <h2 class="text-lg font-medium">Jemputan saya</h2>
        @forelse ($this->myInvites as $invite)
            <article wire:key="referral-invite-{{ $invite->getKey() }}" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded border border-slate-200 bg-white px-3 py-3 text-sm">
                <div class="min-w-0">
                    <p class="break-all font-medium">{{ $invite->invitee_contact_encrypted ?: 'Contact saved privately' }}</p>
                    <p class="mt-1 text-slate-500">{{ $invite->program?->name }} · {{ $invite->status->value }}</p>
                </div>
                @if ($invite->status === \AIArmada\Referrals\Enums\ReferralInviteStatus::Pending)
                    <button type="button" wire:click="revokeInvite('{{ $invite->getKey() }}')" class="rounded border border-slate-300 px-3 py-1.5 text-xs font-medium">Batalkan jemputan</button>
                @endif
            </article>
        @empty
            <p class="mt-2 text-sm text-slate-500">Belum ada jemputan.</p>
        @endforelse
    </section>
</div>
