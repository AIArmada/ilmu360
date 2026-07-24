<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Moderation\Enums\ModerationActionType;
use AIArmada\Signals\Models\SignalEvent;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\ModerationReview;
use App\Models\Person;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\EventSubmittedNotification;
use App\Services\EventKeyPersonSyncService;
use App\Services\ModerationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->service = new ModerationService;
});

describe('Event Submission', function () {
    it('sets event to pending and notifies moderators', function () {
        Notification::fake();

        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $institution = Institution::factory()->create();

        // Create with initial state string, factory should handle this if cast properly
        // However, factory sets string. Spatie writes string to DB.
        $event = Event::factory()->create([
            'institution_id' => $institution->id,
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->service->submitForModeration($event);

        expect((string) $event->fresh()->status)->toBe('pending');
        expect(SignalEvent::query()->where('event_name', 'moderation.event.submitted')->exists())->toBeTrue();
        Notification::assertSentTo($moderator, EventSubmittedNotification::class);
    });

    it('does not auto-approve events during submission', function () {
        $institution = Institution::factory()->create();

        $event = Event::factory()->create([
            'institution_id' => $institution->id,
            'status' => 'draft',
            'starts_at' => now()->addDays(7),
            'title' => 'Moderation test '.uniqid(),
            'published_at' => null,
        ]);

        $this->service->submitForModeration($event);

        expect((string) $event->fresh()->status)->toBe('pending');
        expect($event->fresh()->published_at)->toBeNull();
    });
});

describe('Event Approval', function () {
    it('creates review record and updates status', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $event = Event::factory()->create([
            'status' => 'pending',
        ]);

        // Service returns void now (transitions handle logic), so we check DB for review
        $this->service->approve($event, $moderator, 'Looks good!');

        $review = ModerationReview::query()->where('actionable_id', $event->id)->whereIn('actionable_type', [Event::class, 'event'])->latest()->first();

        expect($review)->toBeInstanceOf(ModerationReview::class);
        expect($review->type)->toBe(ModerationActionType::Approve);
        expect((string) $event->fresh()->status)->toBe('approved');
        expect($event->fresh()->published_at)->not->toBeNull();
        expect(SignalEvent::query()->where('event_name', 'moderation.event.approved')->exists())->toBeTrue();
    });

    it('notifies submitter on approval', function () {
        $submitter = User::factory()->create();

        $event = Event::factory()->create([
            'status' => 'pending',
        ]);
        EventSubmission::factory()->for($event)->for($submitter, 'submitter')->create();

        $this->service->approve($event);

        // Package inbox stores mapped communications triggers (submission_approved → event_published).
        $this->assertDatabaseHas('notification_inboxes', [
            'recipient_id' => $submitter->id,
            'trigger' => 'event_published',
        ]);
    });

    it('auto-verifies pending related records on approval', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        // Create pending person
        $person = Person::factory()->create([
            'status' => 'pending',
        ]);

        // Create pending institution (organizer)
        $organizerInstitution = Institution::factory()->create([
            'status' => 'pending',
        ]);

        // Create pending institution (location)
        $locationInstitution = Institution::factory()->create([
            'status' => 'pending',
        ]);

        // Create pending venue
        $venue = Venue::factory()->create([
            'status' => 'pending',
        ]);

        $event = Event::factory()->create([
            'status' => 'pending',
            'institution_id' => $locationInstitution->id,
            'default_venue_id' => $venue->id,
        ]);
        OwnerContext::withOwner(null, fn () => $event->setPrimaryOrganizer($organizerInstitution));

        app(EventKeyPersonSyncService::class)->sync($event, [(string) $person->id]);

        // Approve event
        OwnerContext::withOwner(null, fn () => $this->service->approve($event, $moderator));

        // Package taxonomy uses EventTerm/Classification (no Spatie Tag dual-verify on approve).
        expect($person->fresh()->status)->toBe('verified')
            ->and($organizerInstitution->fresh()->status)->toBe('verified')
            ->and($locationInstitution->fresh()->status)->toBe('verified')
            ->and($venue->fresh()->status)->toBe('verified');
    });

    it('does not change already verified records on approval', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        // Create already verified person
        $person = Person::factory()->create([
            'status' => 'verified',
        ]);

        $event = Event::factory()->create([
            'status' => 'pending',
        ]);

        app(EventKeyPersonSyncService::class)->sync($event, [(string) $person->id]);

        $this->service->approve($event, $moderator);

        // Should remain verified
        expect($person->fresh()->status)->toBe('verified');
    });
});

describe('Event Needs Changes', function () {
    it('creates review with reason and notifies', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $submitter = User::factory()->create();

        $event = Event::factory()->create([
            'status' => 'pending',
        ]);
        EventSubmission::factory()->for($event)->for($submitter, 'submitter')->create();

        $this->service->requestChanges(
            $event,
            $moderator,
            'incomplete_info',
            'Please add person details'
        );

        $review = ModerationReview::query()->where('actionable_id', $event->id)->whereIn('actionable_type', [Event::class, 'event'])->latest()->first();

        expect($review->type)->toBe(ModerationActionType::ChangesRequested);
        expect($review->reason)->toBe('incomplete_info');
        expect((string) $event->fresh()->status)->toBe('needs_changes');

        // Package inbox maps submission_needs_changes → event_updated.
        $this->assertDatabaseHas('notification_inboxes', [
            'recipient_id' => $submitter->id,
            'trigger' => 'event_updated',
        ]);
    });
});

describe('Event Rejection', function () {
    it('rejects event and notifies submitter', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $submitter = User::factory()->create();

        $event = Event::factory()->create([
            'status' => 'pending',
        ]);
        EventSubmission::factory()->for($event)->for($submitter, 'submitter')->create();

        $this->service->reject(
            $event,
            $moderator,
            'spam',
            'This appears to be spam.'
        );

        $review = ModerationReview::query()->where('actionable_id', $event->id)->whereIn('actionable_type', [Event::class, 'event'])->latest()->first();

        expect($review->type)->toBe(ModerationActionType::Reject);
        expect((string) $event->fresh()->status)->toBe('rejected');
        expect(SignalEvent::query()->where('event_name', 'moderation.event.rejected')->exists())->toBeTrue();

        // Package inbox maps submission_rejected → event_cancelled.
        $this->assertDatabaseHas('notification_inboxes', [
            'recipient_id' => $submitter->id,
            'trigger' => 'event_cancelled',
        ]);
    });
});

describe('Event Cancellation', function () {
    it('cancels event and notifies affected users', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $submitter = User::factory()->create();
        $goingUser = User::factory()->create();
        $savedUser = User::factory()->create();

        $event = Event::factory()->create([
            'status' => 'approved',
            'published_at' => now(),
        ]);
        EventSubmission::factory()->for($event)->for($submitter, 'submitter')->create();

        $goingUser->respond($event, 'going');
        $savedUser->bookmark($event);

        $this->service->cancel($event, $moderator, 'Venue emergency closure.');

        $event->refresh();
        expect((string) $event->status)->toBe('cancelled');
        expect($event->published_at)->not->toBeNull();

        $review = ModerationReview::query()->where('actionable_id', $event->id)->whereIn('actionable_type', [Event::class, 'event'])->latest()->first();
        expect($review)->toBeInstanceOf(ModerationReview::class);
        expect($review->type)->toBe(ModerationActionType::Cancelled);

        // Package inbox maps submission_cancelled → event_cancelled.
        $this->assertDatabaseHas('notification_inboxes', [
            'recipient_id' => $submitter->id,
            'trigger' => 'event_cancelled',
        ]);
        $this->assertDatabaseHas('notification_inboxes', [
            'recipient_id' => $goingUser->id,
            'trigger' => 'event_cancelled',
        ]);
        $this->assertDatabaseHas('notification_inboxes', [
            'recipient_id' => $savedUser->id,
            'trigger' => 'event_cancelled',
        ]);
    });
});

describe('Sensitive Change Handling', function () {
    it('keeps approved events approved when sensitive changes are saved without an announcement', function () {
        Notification::fake();

        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $event = Event::factory()->create([
            'status' => 'approved',
            'published_at' => now(),
        ]);

        $this->service->handleSensitiveChange($event, [
            'default_venue_id' => 'new-venue-id',
        ]);

        expect((string) $event->fresh()->status)->toBe('approved');

        Notification::assertNotSentTo($moderator, EventSubmittedNotification::class);
    });

    it('does not create remoderation review records for sensitive ordinary saves', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
        ]);

        $this->service->handleSensitiveChange($event, [
            'starts_at' => now()->addDays(1),
        ]);

        $review = ModerationReview::query()->where('actionable_id', $event->id)->whereIn('actionable_type', [Event::class, 'event'])->latest()->first();

        expect($review)->toBeNull();
    });

    it('ignores non-sensitive changes', function () {
        $event = Event::factory()->create([
            'status' => 'approved',
        ]);

        $this->service->handleSensitiveChange($event, [
            'title' => 'New Title',
        ]);

        // Status should remain approved
        expect((string) $event->fresh()->status)->toBe('approved');
    });
});

describe('Event Reconsideration', function () {
    it('moves rejected event back to pending', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $event = Event::factory()->create([
            'status' => 'rejected',
        ]);

        $this->service->reconsider($event, $moderator, 'Reconsidering after review.');

        expect((string) $event->fresh()->status)->toBe('pending');

        $review = ModerationReview::query()->where('actionable_id', $event->id)->whereIn('actionable_type', [Event::class, 'event'])->latest()->first();
        expect($review->type)->toBe(ModerationActionType::Reconsidered);
        expect($review->actioned_by_id)->toBe($moderator->id);
    });
});

describe('Revert to Draft', function () {
    it('reverts rejected event to draft', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $event = Event::factory()->create([
            'status' => 'rejected',
        ]);

        $this->service->revertToDraft($event, $moderator, 'Reverting to draft for submitter.');

        expect((string) $event->fresh()->status)->toBe('draft');
        expect($event->fresh()->published_at)->toBeNull();

        $review = ModerationReview::query()->where('actionable_id', $event->id)->whereIn('actionable_type', [Event::class, 'event'])->latest()->first();
        expect($review->type)->toBe(ModerationActionType::RevertedToDraft);
    });

    it('reverts needs_changes event to draft', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $event = Event::factory()->create([
            'status' => 'needs_changes',
        ]);

        $this->service->revertToDraft($event, $moderator);

        expect((string) $event->fresh()->status)->toBe('draft');
    });
});

describe('Re-moderation', function () {
    it('sends approved event back to pending', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $event = Event::factory()->create([
            'status' => 'approved',
            'published_at' => now(),
        ]);

        $this->service->remoderate($event, $moderator, 'Content needs re-review.');

        expect((string) $event->fresh()->status)->toBe('pending');

        $review = ModerationReview::query()->where('actionable_id', $event->id)->whereIn('actionable_type', [Event::class, 'event'])->latest()->first();
        expect($review->type)->toBe(ModerationActionType::Remoderated);
        expect($review->actioned_by_id)->toBe($moderator->id);
    });
});
