<?php

use AIArmada\Membership\Actions\RevokeInvitationAction;
use AIArmada\Membership\Channels\EmailInvitationChannel;
use AIArmada\Membership\Channels\InvitationChannelManager;
use AIArmada\Membership\Contracts\InvitationChannel;
use AIArmada\Membership\Contracts\InvitationRecipientVerifier;
use AIArmada\Membership\Data\InvitationActorData;
use AIArmada\Membership\Data\InvitationDeliveryData;
use AIArmada\Membership\Data\InvitationRecipientData;
use App\Features\Institutions\Actions\DeleteInstitutionAction;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Actions\AcceptSubjectMemberInvitation;
use App\Features\Membership\Actions\InviteSubjectMember;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Membership\Models\MemberInvitation;
use App\Features\Membership\Services\MemberRoleCatalog;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('creates a member invitation with the requested subject and role', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();

    $invitation = app(InviteSubjectMember::class)->handle(
        $institution,
        EmailInvitationChannel::CHANNEL,
        'Invitee@example.com',
        'admin',
        $inviter,
        now()->addWeek(),
    );

    expect($invitation)->toBeInstanceOf(MemberInvitation::class)
        ->and($invitation->subject_type)->toBe(MemberSubjectType::Institution)
        ->and($invitation->subject_id)->toBe($institution->getKey())
        ->and($invitation->channel)->toBe(EmailInvitationChannel::CHANNEL)
        ->and($invitation->recipient)->toBe('invitee@example.com')
        ->and($invitation->role)->toBe('admin')
        ->and($invitation->invited_by)->toBe($inviter->getKey())
        ->and($invitation->token)->not->toBeEmpty();
});

it('resolves raw invitation links against hashed stored tokens', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();

    $invitation = app(InviteSubjectMember::class)->handle(
        $institution,
        EmailInvitationChannel::CHANNEL,
        'invitee@example.com',
        'viewer',
        $inviter,
        now()->addWeek(),
    );

    expect($invitation->token)->not->toBeEmpty();
});

it('rejects protected ownership roles for member invitations', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();

    expect(fn () => app(InviteSubjectMember::class)->handle(
        $institution,
        EmailInvitationChannel::CHANNEL,
        'invitee@example.com',
        'owner',
        $inviter,
        now()->addWeek(),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects acceptance for protected ownership invitations even if the row already exists', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);

    $invitation = new MemberInvitation;
    $invitation->fill([
        'subject_type' => MemberSubjectType::Institution,
        'subject_id' => $institution->getKey(),
        'channel' => EmailInvitationChannel::CHANNEL,
        'recipient' => $invitee->email,
        'role' => 'owner',
        'invited_by' => $inviter->getKey(),
    ]);
    $invitation->issue('stale-owner-invitation')->save();

    expect(fn () => app(AcceptSubjectMemberInvitation::class)->handle($invitation, $invitee))
        ->toThrow(ValidationException::class);
});

it('accepts a member invitation and routes membership through the shared scoped role actions', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => 'invitee@example.com',
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    $invitation = app(InviteSubjectMember::class)->handle(
        $institution,
        EmailInvitationChannel::CHANNEL,
        $invitee->email,
        'admin',
        $inviter,
        now()->addWeek(),
    );

    app(AcceptSubjectMemberInvitation::class)->handle($invitation, $invitee);

    $invitation->refresh();

    expect($institution->members()->whereKey($invitee->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($invitee, MemberSubjectType::Institution))->toBe(['admin'])
        ->and($invitation->accepted_at)->not->toBeNull()
        ->and($invitation->accepted_by)->toBe($invitee->getKey());
});

it('rejects expired invitations', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);

    $invitation = app(InviteSubjectMember::class)->handle(
        $institution,
        EmailInvitationChannel::CHANNEL,
        $invitee->email,
        'viewer',
        $inviter,
        now()->subMinute(),
    );

    expect(fn () => app(AcceptSubjectMemberInvitation::class)->handle($invitation, $invitee))
        ->toThrow(ValidationException::class);
});

it('rejects revoked invitations', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);

    $invitation = app(InviteSubjectMember::class)->handle(
        $institution,
        EmailInvitationChannel::CHANNEL,
        $invitee->email,
        'viewer',
        $inviter,
        now()->addWeek(),
    );

    app(RevokeInvitationAction::class)->handle($invitation, $inviter);

    expect(fn () => app(AcceptSubjectMemberInvitation::class)->handle($invitation->fresh(), $invitee))
        ->toThrow(ValidationException::class);
});

it('rejects reused invitations after they have already been accepted', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => 'invitee@example.com',
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    $invitation = app(InviteSubjectMember::class)->handle(
        $institution,
        EmailInvitationChannel::CHANNEL,
        $invitee->email,
        'viewer',
        $inviter,
        now()->addWeek(),
    );

    app(AcceptSubjectMemberInvitation::class)->handle($invitation, $invitee);

    expect(fn () => app(AcceptSubjectMemberInvitation::class)->handle($invitation->fresh(), $invitee))
        ->toThrow(ValidationException::class);
});

it('rejects email invitation acceptance for users without an email address', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => null,
    ]);

    $invitation = app(InviteSubjectMember::class)->handle(
        $institution,
        EmailInvitationChannel::CHANNEL,
        'invitee@example.com',
        'viewer',
        $inviter,
        now()->addWeek(),
    );

    expect(fn () => app(AcceptSubjectMemberInvitation::class)->handle($invitation, $invitee))
        ->toThrow(RuntimeException::class, 'Invitation recipient identity could not be verified.');
});

it('delivers and accepts a custom-channel invitation for an account without email', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();
    $invitee = User::factory()->create(['email' => null]);
    $recipient = '+60123456789';

    $channel = Mockery::mock(InvitationChannel::class);
    $channel->shouldReceive('channel')->andReturn('sms');
    $channel->shouldReceive('normalize')->once()->with($recipient)->andReturn($recipient);
    $channel->shouldReceive('deliver')->once()->withArgs(
        fn (InvitationDeliveryData $delivery): bool => $delivery->channel === 'sms'
            && $delivery->recipient === $recipient
            && $delivery->subjectId === $institution->getKey(),
    );

    $verifier = Mockery::mock(InvitationRecipientVerifier::class);
    $verifier->shouldReceive('channel')->andReturn('sms');
    $verifier->shouldReceive('verify')->once()->withArgs(
        fn (InvitationRecipientData $identity, InvitationActorData $actor): bool => $identity->identifier === $recipient
            && $actor->id === $invitee->getKey(),
    )->andReturnTrue();

    app(InvitationChannelManager::class)->registerChannel($channel)->registerVerifier($verifier);

    $invitation = app(InviteSubjectMember::class)->handle($institution, 'sms', $recipient, 'viewer', $inviter);
    app(AcceptSubjectMemberInvitation::class)->handle($invitation, $invitee);

    expect($invitation->fresh()->isAccepted())->toBeTrue();
});

it('rejects acceptance when the invited subject no longer exists', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);

    $invitation = app(InviteSubjectMember::class)->handle(
        $institution,
        EmailInvitationChannel::CHANNEL,
        $invitee->email,
        'viewer',
        $inviter,
        now()->addWeek(),
    );

    app(DeleteInstitutionAction::class)->handle($institution);

    expect(fn () => app(AcceptSubjectMemberInvitation::class)->handle($invitation->fresh() ?? $invitation, $invitee))
        ->toThrow(ValidationException::class);
});

it('rejects a duplicate pending invitation for the same subject and recipient', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();

    app(InviteSubjectMember::class)->handle(
        $institution,
        EmailInvitationChannel::CHANNEL,
        'invitee@example.com',
        'viewer',
        $inviter,
        now()->addWeek(),
    );

    expect(fn () => app(InviteSubjectMember::class)->handle(
        $institution,
        EmailInvitationChannel::CHANNEL,
        'INVITEE@example.com',
        'admin',
        $inviter,
        now()->addWeek(),
    ))->toThrow(ValidationException::class)
        ->and(MemberInvitation::query()
            ->where('subject_id', $institution->getKey())->count())->toBe(1);
});

it('rejects an unregistered invitation channel', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();

    expect(fn () => app(InviteSubjectMember::class)->handle(
        $institution,
        'sms',
        '+60123456789',
        'viewer',
        $inviter,
        now()->addWeek(),
    ))->toThrow(InvalidArgumentException::class)
        ->and(MemberInvitation::query()->where('subject_id', $institution->getKey())->exists())->toBeFalse();
});
