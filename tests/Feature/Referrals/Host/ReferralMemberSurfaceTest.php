<?php

declare(strict_types=1);

use AIArmada\Referrals\Actions\CreateReferralProgram;
use AIArmada\Referrals\Enums\ReferralInviteStatus;
use AIArmada\Referrals\Enums\ReferralProgramStatus;
use AIArmada\Referrals\Models\ReferralInvite;
use AIArmada\Referrals\Models\ReferralProgram;
use AIArmada\Signals\Models\SignalEvent;
use App\Livewire\Pages\Dashboard\ReferralMemberIndex;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function memberReferralProgram(?int $maxInvites = null, ?int $inviteExpiryDays = 30): ReferralProgram
{
    return CreateReferralProgram::run([
        'name' => 'Member referrals '.Str::random(6),
        'qualifier_outcome' => 'first_accepted_registration_or_staff_verified_checkin',
        'invite_expiry_days' => $inviteExpiryDays,
        'status' => ReferralProgramStatus::Active,
        'max_invites_per_referrer' => $maxInvites,
        'referrer_reward' => ['driver' => 'reward_points', 'points' => 17, 'family_key' => 'member-surface'],
        'referee_reward' => ['driver' => 'voucher', 'amount_minor' => 1000, 'currency' => 'MYR', 'voucher' => ['type' => 'fixed', 'value' => 1000]],
    ]);
}

it('shows a truthful empty state before staff activate a referral programme', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test(ReferralMemberIndex::class)
        ->assertOk()
        ->assertSee('Ajak kawan')
        ->assertSee('Belum ada program jemputan yang aktif.')
        ->assertDontSee('Create invite');
});

it('creates, lists and revokes a pending invite while recording a curated creation signal', function (): void {
    memberReferralProgram();
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(ReferralMemberIndex::class)
        ->assertOk()
        ->assertSee('Ajak kawan')
        ->assertSee('Pendaftaran majlis pertama yang diterima atau daftar masuk yang disahkan kakitangan')
        ->set('inviteeContact', 'friend@example.test')
        ->call('createInvite')
        ->assertOk()
        ->assertSee('Jemputan berjaya disediakan.')
        ->assertSee('/jemputan/', false)
        ->assertSee('17 mata')
        ->assertSee('RM10.00')
        ->assertDontSee('reward_points')
        ->assertDontSee('family_key');

    $invite = ReferralInvite::query()
        ->where('referrer_type', $user->getMorphClass())
        ->where('referrer_id', (string) $user->getKey())
        ->firstOrFail();
    $signal = SignalEvent::query()->where('event_name', 'referrals.invite_created')->first();
    $sharePath = parse_url((string) $component->get('shareLink'), PHP_URL_PATH);
    $shareToken = is_string($sharePath) ? Str::afterLast($sharePath, '/') : '';

    expect($invite->status)->toBe(ReferralInviteStatus::Pending)
        ->and($sharePath)->toStartWith('/jemputan/')
        ->and($shareToken)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($signal)->not->toBeNull()
        ->and(data_get($signal?->properties, 'invite_id'))->toBe((string) $invite->getKey());

    $component->call('revokeInvite', (string) $invite->getKey())
        ->assertOk()
        ->assertSee('Jemputan dibatalkan.');

    expect($invite->refresh()->status)->toBe(ReferralInviteStatus::Revoked);
});

it('uses the configured default expiry when the referral programme leaves expiry unset', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    memberReferralProgram(inviteExpiryDays: null);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ReferralMemberIndex::class)
        ->assertSee('30 hari')
        ->set('inviteeContact', 'friend@example.test')
        ->call('createInvite')
        ->assertOk()
        ->assertSee('Jemputan berjaya disediakan.');

    $invite = ReferralInvite::query()
        ->where('referrer_type', $user->getMorphClass())
        ->where('referrer_id', (string) $user->getKey())
        ->firstOrFail();

    expect($invite->expired_at?->getTimestamp())
        ->toBe(CarbonImmutable::parse('2026-11-14 12:00:00', 'UTC')->getTimestamp());
    $this->travelBack();
});

it('surfaces self-invite, per-referrer cap and already-claimed contact outcomes', function (): void {
    memberReferralProgram(maxInvites: 1);
    $firstReferrer = User::factory()->create();

    Livewire::actingAs($firstReferrer)
        ->test(ReferralMemberIndex::class)
        ->set('inviteeContact', (string) $firstReferrer->email)
        ->call('createInvite')
        ->assertOk()
        ->assertSee('You cannot invite your own verified contact.');

    $page = Livewire::actingAs($firstReferrer)
        ->test(ReferralMemberIndex::class)
        ->set('inviteeContact', 'first-friend@example.test')
        ->call('createInvite')
        ->assertOk()
        ->set('inviteeContact', 'second-friend@example.test')
        ->call('createInvite')
        ->assertOk()
        ->assertSee('You have reached this programme’s invite limit.');

    $secondReferrer = User::factory()->create();
    Livewire::actingAs($secondReferrer)
        ->test(ReferralMemberIndex::class)
        ->set('inviteeContact', 'first-friend@example.test')
        ->call('createInvite')
        ->assertOk()
        ->assertSee('This contact has already been claimed in this programme.');

    expect(ReferralInvite::query()->count())->toBe(1);
});
