<?php

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use App\Actions\Auth\ClaimGuestSubmissionsAction;
use App\Models\EventSubmission;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

function createGuestEventSubmissionForClaim(string $email): EventSubmission
{
    $submission = EventSubmission::factory()->create([
        'submitter_type' => null,
        'submitter_id' => null,
    ]);

    $submission->contactMethods()->create([
        'type' => ContactMethodType::Email->value,
        'purpose' => ContactPurpose::General->value,
        'value' => $email,
        'is_public' => false,
        'sort_order' => 1,
    ]);

    return $submission->fresh();
}

it('claims guest submissions matching the verified email', function () {
    $user = User::factory()->create(['email' => 'claimer@example.com']);
    $first = createGuestEventSubmissionForClaim('claimer@example.com');
    $second = createGuestEventSubmissionForClaim('claimer@example.com');

    $claimed = ClaimGuestSubmissionsAction::run($user);

    expect($claimed)->toBe(2);

    foreach ([$first, $second] as $submission) {
        $fresh = $submission->fresh();

        expect($fresh->submitter)->toBeInstanceOf(User::class);
        expect((string) $fresh->submitter_id)->toBe((string) $user->getKey());
        expect((string) $fresh->event->created_by_id)->toBe((string) $user->getKey());
    }
});

it('ignores submissions with a different email', function () {
    $user = User::factory()->create(['email' => 'claimer@example.com']);
    $submission = createGuestEventSubmissionForClaim('someone-else@example.com');

    expect(ClaimGuestSubmissionsAction::run($user))->toBe(0);
    expect($submission->fresh()->submitter_id)->toBeNull();
});

it('ignores already-linked submissions', function () {
    $user = User::factory()->create(['email' => 'claimer@example.com']);
    $linked = EventSubmission::factory()->create();
    $linked->contactMethods()->create([
        'type' => ContactMethodType::Email->value,
        'purpose' => ContactPurpose::General->value,
        'value' => 'claimer@example.com',
        'is_public' => false,
        'sort_order' => 1,
    ]);
    $originalSubmitterId = $linked->submitter_id;

    expect(ClaimGuestSubmissionsAction::run($user))->toBe(0);
    expect((string) $linked->fresh()->submitter_id)->toBe((string) $originalSubmitterId);
});

it('does nothing for unverified users', function () {
    $user = User::factory()->unverified()->create(['email' => 'claimer@example.com']);
    $submission = createGuestEventSubmissionForClaim('claimer@example.com');

    expect(ClaimGuestSubmissionsAction::run($user))->toBe(0);
    expect($submission->fresh()->submitter_id)->toBeNull();
});

it('matches the submitter email case-insensitively', function () {
    $user = User::factory()->create(['email' => 'claimer@example.com']);
    $submission = createGuestEventSubmissionForClaim('CLAIMER@EXAMPLE.COM');

    expect(ClaimGuestSubmissionsAction::run($user))->toBe(1);
    expect((string) $submission->fresh()->submitter_id)->toBe((string) $user->getKey());
});

it('preserves an existing event creator when claiming', function () {
    $creator = User::factory()->create();
    $user = User::factory()->create(['email' => 'claimer@example.com']);
    $submission = createGuestEventSubmissionForClaim('claimer@example.com');
    $submission->event->forceFill([
        'created_by_type' => $creator->getMorphClass(),
        'created_by_id' => $creator->getKey(),
    ])->save();

    expect(ClaimGuestSubmissionsAction::run($user))->toBe(1);

    $fresh = $submission->fresh();

    expect((string) $fresh->submitter_id)->toBe((string) $user->getKey());
    expect((string) $fresh->event->created_by_id)->toBe((string) $creator->getKey());
});

it('claims when the email address is verified', function () {
    $user = User::factory()->unverified()->create(['email' => 'claimer@example.com']);
    $submission = createGuestEventSubmissionForClaim('claimer@example.com');

    // Bind a session so the listener notice has somewhere to flash.
    $this->get(route('home'))->assertOk();

    $user->markEmailAsVerified();

    event(new Verified($user));

    expect((string) $submission->fresh()->submitter_id)->toBe((string) $user->getKey());

    $toast = session('toast');

    expect($toast['type'] ?? null)->toBe('success');
    expect((string) ($toast['message'] ?? ''))->toContain('1');
});

it('claims when the verification link is opened', function () {
    $user = User::factory()->unverified()->create(['email' => 'claimer@example.com']);
    $submission = createGuestEventSubmissionForClaim('claimer@example.com');

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1((string) $user->email),
    ]);

    $response = $this->actingAs($user)->get($url);

    $response->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    expect((string) $submission->fresh()->submitter_id)->toBe((string) $user->getKey());
    $response->assertSessionHas('toast.type', 'success');
});

it('claims post-verification guest submissions on password login', function () {
    $user = User::factory()->create(['email' => 'claimer@example.com']);
    $submission = createGuestEventSubmissionForClaim('claimer@example.com');

    $response = $this->post(route('login'), [
        'email' => 'claimer@example.com',
        'password' => 'password',
    ]);

    $response->assertRedirect();
    $this->assertAuthenticatedAs($user);
    expect((string) $submission->fresh()->submitter_id)->toBe((string) $user->getKey());
    $response->assertSessionHas('toast.type', 'success');
});

it('claims on Google login', function () {
    config()->set('app.url', 'https://ilmu360.test');
    config()->set('services.google.client_id', 'google-client-id');
    config()->set('services.google.client_secret', 'google-client-secret');
    config()->set('services.google.redirect', 'https://ilmu360.test/oauth/google/callback');

    $submission = createGuestEventSubmissionForClaim('oauth-claim@example.com');
    $state = googleOAuthState();

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-claim-123',
        'name' => 'OAuth Claim',
        'email' => 'oauth-claim@example.com',
        'avatar' => 'https://example.com/oauth-claim.jpg',
        'email_verified' => true,
    ]));

    $response = $this->get(route('socialite.callback', ['provider' => 'google', 'state' => $state]));

    $response->assertRedirect(route('home'));
    $this->assertAuthenticated();
    expect((string) $submission->fresh()->submitter_id)->toBe((string) auth()->id());
    $response->assertSessionHas('toast.type', 'success');
});
