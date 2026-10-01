<?php

use AIArmada\Events\States\RegistrationStatus\Confirmed;
use AIArmada\Events\States\RegistrationStatus\Pending;
use AIArmada\Membership\Enums\InvitationStatus;
use App\Enums\DonationChannelStatus;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\Person;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps report lifecycle fields behind transitionStatus', function (): void {
    $person = Person::factory()->create(['status' => 'verified']);
    $forgedAt = now()->subDay();

    $report = Report::makeOpen();
    $report->fill([
        'entity_type' => 'person',
        'entity_id' => $person->getKey(),
        'category' => 'wrong_info',
        'description' => 'Lifecycle test report',
    ])->save();

    expect(fn () => $report->fill([
        'status' => Report::STATUS_RESOLVED,
        'resolved_at' => $forgedAt,
        'last_state_change_at' => $forgedAt,
    ]))->toThrow(MassAssignmentException::class);

    $report->refresh();

    expect($report->status)->toBe(Report::STATUS_OPEN)
        ->and($report->reported_at)->not->toBeNull()
        ->and($report->resolved_at)->toBeNull()
        ->and($report->last_state_change_at)->not->toBeNull()
        ->and($report->getCasts()['resolved_at'])->toBe('immutable_datetime')
        ->and($report->getCasts()['last_state_change_at'])->toBe('immutable_datetime');

    $initialLastStateChange = $report->last_state_change_at;
    $report->transitionStatus(Report::STATUS_RESOLVED);
    $report->refresh();

    expect($report->status)->toBe(Report::STATUS_RESOLVED)
        ->and($report->resolved_at)->not->toBeNull()
        ->and($report->last_state_change_at?->greaterThanOrEqualTo($initialLastStateChange))->toBeTrue();
});

it('keeps donation channel lifecycle fields behind transition methods', function (): void {
    $institution = Institution::factory()->create();
    $forgedAt = now()->subDay();

    expect(fn () => DonationChannel::create([
        'donatable_type' => $institution->getMorphClass(),
        'donatable_id' => $institution->getKey(),
        'recipient' => 'Lifecycle Test Account',
        'method' => 'bank_account',
        'bank_name' => 'Maybank',
        'account_number' => '1234567890',
        'status' => DonationChannelStatus::Verified,
        'verified_at' => $forgedAt,
        'last_state_change_at' => $forgedAt,
    ]))->toThrow(MassAssignmentException::class);

    $channel = DonationChannel::factory()->create([
        'donatable_type' => $institution->getMorphClass(),
        'donatable_id' => $institution->getKey(),
        'status' => DonationChannelStatus::Pending,
        'verified_at' => null,
        'rejected_at' => null,
        'inactive_at' => null,
        'last_state_change_at' => null,
    ]);

    $channel->verify();
    $channel->refresh();

    expect($channel->status)->toBe(DonationChannelStatus::Verified)
        ->and($channel->verified_at)->not->toBeNull()
        ->and($channel->rejected_at)->toBeNull()
        ->and($channel->inactive_at)->toBeNull()
        ->and($channel->last_state_change_at)->not->toBeNull()
        ->and($channel->getCasts()['status'])->toBe(DonationChannelStatus::class)
        ->and($channel->getCasts()['verified_at'])->toBe('immutable_datetime')
        ->and($channel->getCasts()['rejected_at'])->toBe('immutable_datetime')
        ->and($channel->getCasts()['inactive_at'])->toBe('immutable_datetime')
        ->and($channel->getCasts()['last_state_change_at'])->toBe('immutable_datetime');

    $verifiedLastStateChange = $channel->last_state_change_at;
    $channel->reject()->refresh();

    expect($channel->status)->toBe(DonationChannelStatus::Rejected)
        ->and($channel->rejected_at)->not->toBeNull()
        ->and($channel->last_state_change_at?->greaterThanOrEqualTo($verifiedLastStateChange))->toBeTrue();

    $rejectedLastStateChange = $channel->last_state_change_at;
    $channel->deactivate()->refresh();

    expect($channel->status)->toBe(DonationChannelStatus::Inactive)
        ->and($channel->inactive_at)->not->toBeNull()
        ->and($channel->last_state_change_at?->greaterThanOrEqualTo($rejectedLastStateChange))->toBeTrue();
});

it('keeps registration lifecycle fields behind transitionStatus', function (): void {
    $event = Event::factory()->create();
    $forgedAt = now()->subDay();

    $registration = Registration::factory()->create([
        'event_id' => $event->getKey(),
        'status' => 'pending',
    ]);

    $initialLastStateChange = $registration->last_state_change_at;
    expect(fn () => $registration->fill([
        'status' => 'cancelled',
        'cancelled_at' => $forgedAt,
        'last_state_change_at' => $forgedAt,
    ]))->toThrow(MassAssignmentException::class);

    $registration->refresh();

    expect($registration->status)->toBeInstanceOf(Pending::class)
        ->and($registration->cancelled_at)->toBeNull()
        ->and($registration->last_state_change_at)->toEqual($initialLastStateChange)
        ->and($registration->getCasts()['cancelled_at'])->toBe('immutable_datetime')
        ->and($registration->getCasts()['last_state_change_at'])->toBe('immutable_datetime');

    $registration->transitionStatus(Confirmed::class);
    $registration->refresh();

    expect($registration->status)->toBeInstanceOf(Confirmed::class)
        ->and($registration->approved_at)->not->toBeNull()
        ->and($registration->last_state_change_at?->greaterThanOrEqualTo($initialLastStateChange))->toBeTrue();
});

it('hashes invitation tokens and keeps invitation lifecycle fields behind transitions', function (): void {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();
    $invitee = User::factory()->create();
    $rawToken = 'lifecycle-test-invitation-token';

    $invitation = new MemberInvitation;
    $invitation->fill([
        'subject_type' => 'institution',
        'subject_id' => $institution->getKey(),
        'email' => $invitee->email,
        'role' => 'viewer',
        'invited_by' => $inviter->getKey(),
    ]);
    $invitation->issue($rawToken)->save();

    $initialLastStateChange = $invitation->last_state_change_at;
    expect(fn () => $invitation->fill([
        'status' => InvitationStatus::Accepted,
        'token' => 'forged-token',
        'accepted_at' => now()->subDay(),
        'last_state_change_at' => now()->subDay(),
    ]))->toThrow(MassAssignmentException::class);

    $invitation->refresh();

    expect($invitation->status)->toBe(InvitationStatus::Pending)
        ->and($invitation->getRawOriginal('token'))->toBe(MemberInvitation::tokenForStorage($rawToken))
        ->and($invitation->matchesToken($rawToken))->toBeTrue()
        ->and($invitation->matchesToken(MemberInvitation::tokenForStorage($rawToken)))->toBeFalse()
        ->and($invitation->accepted_at)->toBeNull()
        ->and($invitation->last_state_change_at)->toEqual($initialLastStateChange)
        ->and($invitation->getCasts()['accepted_at'])->toBe('immutable_datetime')
        ->and($invitation->getCasts()['last_state_change_at'])->toBe('immutable_datetime');

    $invitation->transitionStatus(InvitationStatus::Accepted, $invitee);
    $invitation->refresh();

    expect($invitation->status)->toBe(InvitationStatus::Accepted)
        ->and($invitation->accepted_at)->not->toBeNull()
        ->and($invitation->accepted_by)->toBe($invitee->getKey())
        ->and($invitation->last_state_change_at?->greaterThanOrEqualTo($initialLastStateChange))->toBeTrue();
});
