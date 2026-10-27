<?php

declare(strict_types=1);

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Referrals\Actions\CreateReferralInvite;
use AIArmada\Referrals\Actions\CreateReferralProgram;
use AIArmada\Referrals\Enums\ReferralProgramStatus;
use AIArmada\Referrals\Models\ReferralInvite;
use App\Actions\Fortify\CreateNewUser;
use App\Contracts\SignupAttributionSourceResolver;
use App\Enums\SignupAttributionSourceType;
use App\Enums\SignupAttributionStatus;
use App\Models\SignupAttribution;
use App\Models\User;
use App\Support\Auth\OAuthTransactionStore;
use App\Support\Auth\SignupAttributionSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('app.url', 'https://ilmu360.test');
    config()->set('services.google.client_id', 'google-client-id');
    config()->set('services.google.client_secret', 'google-client-secret');
    config()->set('services.google.redirect', 'https://ilmu360.test/oauth/google/callback');
});

/** @return array{User, ReferralInvite, string} */
function signupAuthInvite(string $contact): array
{
    $referrer = User::factory()->create();

    return OwnerContext::withOwner(null, function () use ($referrer, $contact): array {
        $program = CreateReferralProgram::run([
            'name' => 'Signup attribution '.Str::random(8),
            'qualifier_outcome' => 'first_accepted_registration_or_staff_verified_checkin',
            'invite_expiry_days' => 30,
            'status' => ReferralProgramStatus::Active,
            'referrer_reward' => ['driver' => 'reward_points', 'points' => 5, 'family_key' => 'signup-auth-tests'],
            'referee_reward' => ['driver' => 'reward_points', 'points' => 5, 'family_key' => 'signup-auth-tests'],
        ]);
        $result = CreateReferralInvite::run($program, $referrer, $contact, (string) $referrer->email);

        return [$referrer, $result->invite, (string) $result->token];
    });
}

function signupAuthGoogleToken(SocialiteUser $socialUser): void
{
    $provider = Mockery::mock();
    $provider->shouldReceive('stateless')->once()->andReturnSelf();
    $provider->shouldReceive('userFromToken')->once()->with('signup-google-token')->andReturn($socialUser);

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
}

it('captures the invitation held through web password signup', function (): void {
    [$referrer, $invite, $token] = signupAuthInvite('web-signup@example.test');
    $landing = route('referrals.invite.landing', ['token' => $token]);
    $this->get($landing)->assertOk()->assertSessionHas('referrals.invite_token', $token);

    $this->post(route('register.store'), [
        'name' => 'Web Signup',
        'email' => 'web-signup@example.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertRedirect($landing);

    $user = User::query()->where('email', 'web-signup@example.test')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    $attribution = $user->signupAttribution()->firstOrFail();

    expect($attribution->source_type)->toBe(SignupAttributionSourceType::ReferralInvite);
    expect($attribution->status)->toBe(SignupAttributionStatus::Pending);
    expect((string) $attribution->referrer_user_id)->toBe((string) $referrer->getKey());
    expect((string) $attribution->referral_invite_id)->toBe((string) $invite->getKey());
    expect($attribution->signed_up_at->equalTo($user->created_at))->toBeTrue();
});

it('captures an invitation supplied to api password signup without a browser session', function (): void {
    [$referrer, $invite, $token] = signupAuthInvite('api-signup@example.test');

    $this->postJson(route('api.auth.register'), [
        'name' => 'API Signup',
        'email' => 'api-signup@example.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'device_name' => 'Signup Test Phone',
        'referral_invite_token' => $token,
    ])->assertCreated()->assertJsonPath('data.user.email', 'api-signup@example.test');

    $user = User::query()->where('email', 'api-signup@example.test')->firstOrFail();
    $attribution = $user->signupAttribution()->firstOrFail();

    expect($attribution->source_type)->toBe(SignupAttributionSourceType::ReferralInvite);
    expect($attribution->status)->toBe(SignupAttributionStatus::Pending);
    expect((string) $attribution->referrer_user_id)->toBe((string) $referrer->getKey());
    expect((string) $attribution->referral_invite_id)->toBe((string) $invite->getKey());
    expect($attribution->signed_up_at->equalTo($user->created_at))->toBeTrue();
});

it('confirms the captured invitation when google creates a verified web account', function (): void {
    [$referrer, $invite, $token] = signupAuthInvite('google-web-signup@example.test');
    $state = googleOAuthState(['referral_invite_token' => $token]);
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'signup-google-web',
        'name' => 'Google Web Signup',
        'email' => 'google-web-signup@example.test',
        'email_verified' => true,
    ]));

    $this->get(route('socialite.callback', ['provider' => 'google', 'state' => $state]))
        ->assertRedirect(route('home'));

    $user = User::query()->where('email', 'google-web-signup@example.test')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    $attribution = $user->signupAttribution()->firstOrFail();

    expect($attribution->source_type)->toBe(SignupAttributionSourceType::ReferralInvite);
    expect($attribution->status)->toBe(SignupAttributionStatus::Confirmed);
    expect((string) $attribution->referrer_user_id)->toBe((string) $referrer->getKey());
    expect((string) $attribution->referral_invite_id)->toBe((string) $invite->getKey());
    expect($attribution->confirmed_at)->not->toBeNull();
});

it('does not create signup credit when google links an existing web account', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'existing-google-web@example.test']);
    [, , $token] = signupAuthInvite((string) $user->email);
    $state = googleOAuthState(['referral_invite_token' => $token]);
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'signup-existing-google-web',
        'name' => 'Existing Google Web',
        'email' => 'existing-google-web@example.test',
        'email_verified' => true,
    ]));

    $this->get(route('socialite.callback', ['provider' => 'google', 'state' => $state]))
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseMissing('signup_attributions', ['user_id' => $user->getKey()]);
    expect(User::query()->where('email', $user->email)->count())->toBe(1);
});

it('confirms the invitation when google token exchange creates a verified api account', function (): void {
    [$referrer, $invite, $token] = signupAuthInvite('google-api-signup@example.test');
    signupAuthGoogleToken(SocialiteUser::fake([
        'id' => 'signup-google-api',
        'name' => 'Google API Signup',
        'email' => 'google-api-signup@example.test',
        'email_verified' => true,
    ]));

    $this->postJson(route('api.auth.social.google'), [
        'access_token' => 'signup-google-token',
        'device_name' => 'Signup Test Phone',
        'referral_invite_token' => $token,
    ])->assertOk()->assertJsonPath('data.user.email', 'google-api-signup@example.test');

    $user = User::query()->where('email', 'google-api-signup@example.test')->firstOrFail();
    $attribution = $user->signupAttribution()->firstOrFail();

    expect($attribution->source_type)->toBe(SignupAttributionSourceType::ReferralInvite);
    expect($attribution->status)->toBe(SignupAttributionStatus::Confirmed);
    expect((string) $attribution->referrer_user_id)->toBe((string) $referrer->getKey());
    expect((string) $attribution->referral_invite_id)->toBe((string) $invite->getKey());
});

it('does not create signup credit when google token exchange links an existing account', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'existing-google-api@example.test']);
    [, , $token] = signupAuthInvite((string) $user->email);
    signupAuthGoogleToken(SocialiteUser::fake([
        'id' => 'signup-existing-google-api',
        'name' => 'Existing Google API',
        'email' => 'existing-google-api@example.test',
        'email_verified' => true,
    ]));

    $this->postJson(route('api.auth.social.google'), [
        'access_token' => 'signup-google-token',
        'device_name' => 'Signup Test Phone',
        'referral_invite_token' => $token,
    ])->assertOk()->assertJsonPath('data.user.id', (string) $user->getKey());

    $this->assertDatabaseMissing('signup_attributions', ['user_id' => $user->getKey()]);
    expect(User::query()->where('email', $user->email)->count())->toBe(1);
});

it('retains the origin invitation through an oauth bridge despite a different callback source', function (): void {
    config()->set('services.google.redirect', 'https://dev.ilmu360.com/oauth/google/callback');
    [$originReferrer, $originInvite, $originToken] = signupAuthInvite('google-bridge-signup@example.test');
    [, , $laterToken] = signupAuthInvite('google-bridge-signup@example.test');
    $state = googleOAuthState(['referral_invite_token' => $originToken]);
    session()->flush();
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'signup-google-bridge',
        'name' => 'Google Bridge Signup',
        'email' => 'google-bridge-signup@example.test',
        'email_verified' => true,
    ]));

    $response = $this->get((string) config('services.google.redirect').'?'.http_build_query([
        'state' => $state,
        'referral_invite_token' => $laterToken,
    ]));

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))
        ->toStartWith('https://ilmu360.test/oauth/google/complete?token=');
    $this->assertGuest();
    $user = User::query()->where('email', 'google-bridge-signup@example.test')->firstOrFail();
    $attribution = $user->signupAttribution()->firstOrFail();

    expect($attribution->source_type)->toBe(SignupAttributionSourceType::ReferralInvite);
    expect($attribution->status)->toBe(SignupAttributionStatus::Confirmed);
    expect((string) $attribution->referrer_user_id)->toBe((string) $originReferrer->getKey());
    expect((string) $attribution->referral_invite_id)->toBe((string) $originInvite->getKey());
    expect(SignupAttribution::query()->where('user_id', $user->getKey())->count())->toBe(1);
});

it('keeps a direct oauth origin direct when an invitation appears only at callback', function (): void {
    config()->set('services.google.redirect', 'https://dev.ilmu360.com/oauth/google/callback');
    [, , $laterToken] = signupAuthInvite('google-bridge-direct@example.test');
    $state = googleOAuthState();
    session()->flush();
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'signup-google-bridge-direct',
        'name' => 'Google Bridge Direct',
        'email' => 'google-bridge-direct@example.test',
        'email_verified' => true,
    ]));

    $response = $this->get((string) config('services.google.redirect').'?'.http_build_query([
        'state' => $state,
        'referral_invite_token' => $laterToken,
    ]));

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))
        ->toStartWith('https://ilmu360.test/oauth/google/complete?token=');
    $user = User::query()->where('email', 'google-bridge-direct@example.test')->firstOrFail();
    $attribution = $user->signupAttribution()->firstOrFail();

    expect($attribution->source_type)->toBe(SignupAttributionSourceType::Direct);
    expect($attribution->status)->toBe(SignupAttributionStatus::Confirmed);
    expect($attribution->referrer_user_id)->toBeNull();
    expect($attribution->referral_invite_id)->toBeNull();
    expect(SignupAttribution::query()->where('user_id', $user->getKey())->count())->toBe(1);
});

it('rejects oauth state with missing or malformed signup context instead of recapturing callback evidence', function (mixed $context): void {
    $issued = OAuthTransactionStore::issueState('google', null);
    $key = 'oauth-state:'.$issued['state'];
    $payload = Cache::get($key);
    $payload['signup_attribution_context'] = $context;
    Cache::put($key, $payload, 600);

    expect(OAuthTransactionStore::consumeState($issued['state'], 'google'))->toBeNull();
})->with([
    'missing context' => [null],
    'missing required keys' => [[]],
    'invalid invite reference' => [['referral_invite_id' => 123, 'affiliate_source' => null]],
    'invalid affiliate snapshot' => [['referral_invite_id' => null, 'affiliate_source' => ['type' => 'unknown']]],
]);

it('rolls back the new account when its signup attribution cannot be stored', function (): void {
    app()->instance(SignupAttributionSourceResolver::class, new class implements SignupAttributionSourceResolver
    {
        public function resolve(?Request $request): ?SignupAttributionSource
        {
            throw new RuntimeException('Signup attribution storage failed.');
        }
    });

    expect(fn () => app(CreateNewUser::class)->create([
        'name' => 'Atomic Signup',
        'email' => 'atomic-signup@example.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]))->toThrow(RuntimeException::class, 'Signup attribution storage failed.');

    $this->assertDatabaseMissing('users', ['email' => 'atomic-signup@example.test']);
    $this->assertDatabaseCount('signup_attributions', 0);
});
