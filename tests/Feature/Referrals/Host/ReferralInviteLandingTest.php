<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Referrals\Actions\AcceptReferralInvite;
use AIArmada\Referrals\Actions\CreateReferralInvite;
use AIArmada\Referrals\Actions\CreateReferralProgram;
use AIArmada\Referrals\Actions\RevokeReferralInvite;
use AIArmada\Referrals\Enums\ReferralInviteStatus;
use AIArmada\Referrals\Enums\ReferralProgramStatus;
use AIArmada\Referrals\Models\ReferralInvite;
use AIArmada\Signals\Models\SignalEvent;
use App\Models\User;
use App\Services\ShareTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{ReferralInvite, string} */
function createLandingInvite(User $referrer, ?string $contact = null): array
{
    $program = CreateReferralProgram::run([
        'name' => 'Invite landing test '.Str::random(5),
        'qualifier_outcome' => 'first_accepted_registration_or_staff_verified_checkin',
        'invite_expiry_days' => 30,
        'status' => ReferralProgramStatus::Active,
        'referrer_reward' => ['driver' => 'reward_points', 'points' => 17, 'family_key' => 'landing-test'],
        'referee_reward' => ['driver' => 'voucher', 'amount_minor' => 1000, 'currency' => 'MYR', 'voucher' => ['type' => 'fixed', 'value' => 1000]],
    ]);
    $creation = CreateReferralInvite::run($program, $referrer, $contact ?? 'invitee-'.Str::random(8).'@example.test', (string) $referrer->email);

    return [$creation->invite, (string) $creation->token];
}

it('stashes a guest invite token for sign-in and keeps share capture active on the landing route', function (): void {
    $referrer = User::factory()->create();
    [$invite, $token] = createLandingInvite($referrer);
    app(ShareTrackingService::class)->createOrReuseLink($referrer, route('home'), 'Referral landing', 'web');
    $affiliate = Affiliate::query()
        ->where('owner_type', $referrer->getMorphClass())
        ->where('owner_id', $referrer->getKey())
        ->firstOrFail();
    $shareSlug = AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->value('custom_slug');

    $landingUrl = route('referrals.invite.landing', ['token' => $token]);
    $response = $this->get($landingUrl.'?share='.$shareSlug);

    $response->assertOk()
        ->assertSee('Log masuk atau daftar masuk dahulu untuk menerima jemputan ini.')
        ->assertSee(route('login'))
        ->assertSessionHas('referrals.invite_token', $token)
        ->assertSessionHas('url.intended', $landingUrl);

    expect($invite->refresh()->status)->toBe(ReferralInviteStatus::Pending)
        ->and($response->getCookie((string) config('dawah-share.cookie.name', 'mi_dawah_share')))->not->toBeNull();
});

it('accepts a landing invite with the authenticated user email before phone and records a signal', function (): void {
    $referrer = User::factory()->create();
    $invitee = User::factory()->create();
    [$invite, $token] = createLandingInvite($referrer, (string) $invitee->email);

    $this->actingAs($invitee)
        ->get(route('referrals.invite.landing', ['token' => $token]))
        ->assertOk()
        ->assertSee('Jemputan telah diterima.');

    expect($invite->refresh()->status)->toBe(ReferralInviteStatus::Accepted)
        ->and((string) $invite->accepted_by_id)->toBe((string) $invitee->getKey());

    $signal = SignalEvent::query()->where('event_name', 'referrals.invite_accepted')->first();
    expect($signal)->not->toBeNull()
        ->and(data_get($signal?->properties, 'invite_id'))->toBe((string) $invite->getKey());
});

it('tries verified phone after a mismatching verified email', function (): void {
    $referrer = User::factory()->create();
    $invitee = User::factory()->create();
    [$invite, $token] = createLandingInvite($referrer, (string) $invitee->phone);

    $this->actingAs($invitee)
        ->get(route('referrals.invite.landing', ['token' => $token]))
        ->assertOk()
        ->assertSee('Jemputan telah diterima.');

    expect($invite->refresh()->status)->toBe(ReferralInviteStatus::Accepted)
        ->and((string) $invite->accepted_by_id)->toBe((string) $invitee->getKey());
});

it('reports mismatch, expired, revoked, self-invite, already-claimed and missing verified contact truthfully', function (): void {
    $referrer = User::factory()->create();
    $mismatchUser = User::factory()->create();
    [$mismatchInvite, $mismatchToken] = createLandingInvite($referrer, 'different@example.test');
    $this->actingAs($mismatchUser)
        ->get(route('referrals.invite.landing', ['token' => $mismatchToken]))
        ->assertOk()
        ->assertSee('E-mel atau nombor telefon yang disahkan tidak sepadan');
    expect($mismatchInvite->refresh()->status)->toBe(ReferralInviteStatus::Pending);

    $expiredReferrer = User::factory()->create();
    [$expiredInvite, $expiredToken] = createLandingInvite($expiredReferrer, 'expired@example.test');
    $this->travel(31)->days();
    $this->actingAs(User::factory()->create())
        ->get(route('referrals.invite.landing', ['token' => $expiredToken]))
        ->assertOk()
        ->assertSee('Jemputan ini telah tamat tempoh.');
    $this->travelBack();
    expect($expiredInvite->refresh()->status)->toBe(ReferralInviteStatus::Pending);

    $revokedReferrer = User::factory()->create();
    [$revokedInvite, $revokedToken] = createLandingInvite($revokedReferrer, 'revoked@example.test');
    RevokeReferralInvite::run($revokedInvite, 'test');
    $this->actingAs(User::factory()->create())
        ->get(route('referrals.invite.landing', ['token' => $revokedToken]))
        ->assertOk()
        ->assertSee('Jemputan ini telah dibatalkan.');

    $selfReferrer = User::factory()->create();
    [$selfInvite, $selfToken] = createLandingInvite($selfReferrer, 'other-contact@example.test');
    $this->actingAs($selfReferrer)
        ->get(route('referrals.invite.landing', ['token' => $selfToken]))
        ->assertOk()
        ->assertSee('Anda tidak boleh menerima jemputan anda sendiri.');
    expect($selfInvite->refresh()->status)->toBe(ReferralInviteStatus::Pending);

    $acceptedReferrer = User::factory()->create();
    [$acceptedInvite, $acceptedToken] = createLandingInvite($acceptedReferrer, 'already-accepted@example.test');
    $acceptedUser = User::factory()->create(['email' => 'already-accepted@example.test']);
    AcceptReferralInvite::run($acceptedToken, $acceptedUser, (string) $acceptedUser->email);
    $this->actingAs($acceptedUser)
        ->get(route('referrals.invite.landing', ['token' => $acceptedToken]))
        ->assertOk()
        ->assertSee('Jemputan ini telah diterima atau dituntut.');
    expect($acceptedInvite->refresh()->status)->toBe(ReferralInviteStatus::Accepted);

    $unverified = User::factory()->unverified()->emailOnly()->create();
    [$unverifiedInvite, $unverifiedToken] = createLandingInvite($referrer, 'unverified@example.test');
    $this->actingAs($unverified)
        ->get(route('referrals.invite.landing', ['token' => $unverifiedToken]))
        ->assertOk()
        ->assertSee('Sahkan e-mel atau nombor telefon anda sebelum menerima jemputan.');
    expect($unverifiedInvite->refresh()->status)->toBe(ReferralInviteStatus::Pending);
});
