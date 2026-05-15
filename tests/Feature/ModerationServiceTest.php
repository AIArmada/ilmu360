<?php

use AIArmada\Signals\Models\SignalEvent;
use App\Models\Event;
use App\Models\Institution;
use App\Models\ModerationReview;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\EventSubmittedNotification;
use App\Services\ModerationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();
    $this->service = new ModerationService;
});

describe('Event Submission', function () {
    it('sets event to pending and notifies moderators', function () {
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

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();

        expect($review)->toBeInstanceOf(ModerationReview::class);
        expect($review->decision)->toBe('approved');
        expect((string) $event->fresh()->status)->toBe('approved');
        expect($event->fresh()->published_at)->not->toBeNull();
        expect(SignalEvent::query()->where('event_name', 'moderation.event.approved')->exists())->toBeTrue();
    });

    it('notifies submitter on approval', function () {
        $submitter = User::factory()->create();

        $event = Event::factory()->create([
            'status' => 'pending',
            'submitter_id' => $submitter->id,
        ]);

        $this->service->approve($event);

        $this->assertDatabaseHas('notification_messages', [
            'user_id' => $submitter->id,
            'trigger' => 'submission_approved',
        ]);
    });

    it('auto-verifies pending related records on approval', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        // Create pending speaker
        $speaker = Speaker::factory()->create([
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

        // Create pending tags
        $disciplineTag = Tag::create([
            'name' => ['ms' => 'Pending Fiqh', 'en' => 'Pending Fiqh'],
            'type' => 'discipline',
            'status' => 'pending',
        ]);

        $issueTag = Tag::create([
            'name' => ['ms' => 'Pending Issue', 'en' => 'Pending Issue'],
            'type' => 'issue',
            'status' => 'pending',
        ]);

        $event = Event::factory()->create([
            'status' => 'pending',
            'organizer_type' => Institution::class,
            'organizer_id' => $organizerInstitution->id,
            'institution_id' => $locationInstitution->id,
            'venue_id' => $venue->id,
        ]);

        $event->speakers()->attach($speaker->id);
        $event->syncTags([$disciplineTag, $issueTag]);

        // Approve event
        $this->service->approve($event, $moderator);

        // Verify all related records are now verified
        expect($speaker->fresh()->status)->toBe('verified')
            ->and($organizerInstitution->fresh()->status)->toBe('verified')
            ->and($locationInstitution->fresh()->status)->toBe('verified')
            ->and($venue->fresh()->status)->toBe('verified')
            ->and($disciplineTag->fresh()->status)->toBe('verified')
            ->and($issueTag->fresh()->status)->toBe('verified');
    });

    it('does not change already verified records on approval', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        // Create already verified speaker
        $speaker = Speaker::factory()->create([
            'status' => 'verified',
        ]);

        $event = Event::factory()->create([
            'status' => 'pending',
        ]);

        $event->speakers()->attach($speaker->id);

        $this->service->approve($event, $moderator);

        // Should remain verified
        expect($speaker->fresh()->status)->toBe('verified');
    });
});

describe('Event Needs Changes', function () {
    it('creates review with reason and notifies', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $submitter = User::factory()->create();

        $event = Event::factory()->create([
            'status' => 'pending',
            'submitter_id' => $submitter->id,
        ]);

        $this->service->requestChanges(
            $event,
            $moderator,
            'incomplete_info',
            'Please add speaker details'
        );

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();

        expect($review->decision)->toBe('needs_changes');
        expect($review->reason_code)->toBe('incomplete_info');
        expect((string) $event->fresh()->status)->toBe('needs_changes');

        $this->assertDatabaseHas('notification_messages', [
            'user_id' => $submitter->id,
            'trigger' => 'submission_needs_changes',
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
            'submitter_id' => $submitter->id,
        ]);

        $this->service->reject(
            $event,
            $moderator,
            'spam',
            'This appears to be spam.'
        );

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();

        expect($review->decision)->toBe('rejected');
        expect((string) $event->fresh()->status)->toBe('rejected');
        expect(SignalEvent::query()->where('event_name', 'moderation.event.rejected')->exists())->toBeTrue();

        $this->assertDatabaseHas('notification_messages', [
            'user_id' => $submitter->id,
            'trigger' => 'submission_rejected',
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
            'submitter_id' => $submitter->id,
            'is_active' => true,
        ]);

        $event->goingBy()->attach($goingUser->id);
        $event->savedBy()->attach($savedUser->id);

        $this->service->cancel($event, $moderator, 'Venue emergency closure.');

        $event->refresh();
        expect((string) $event->status)->toBe('cancelled');
        expect($event->is_active)->toBeTrue();

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();
        expect($review)->toBeInstanceOf(ModerationReview::class);
        expect($review->decision)->toBe('cancelled');

        $this->assertDatabaseHas('notification_messages', [
            'user_id' => $submitter->id,
            'trigger' => 'submission_cancelled',
        ]);
        $this->assertDatabaseHas('notification_messages', [
            'user_id' => $goingUser->id,
            'trigger' => 'event_cancelled',
        ]);
        $this->assertDatabaseHas('notification_messages', [
            'user_id' => $savedUser->id,
            'trigger' => 'event_cancelled',
        ]);
    });
});

describe('Sensitive Change Handling', function () {
    it('keeps approved events approved when sensitive changes are saved without an announcement', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $event = Event::factory()->create([
            'status' => 'approved',
            'published_at' => now(),
        ]);

        $this->service->handleSensitiveChange($event, [
            'venue_id' => 'new-venue-id',
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

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();

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

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();
        expect($review->decision)->toBe('reconsidered');
        expect($review->moderator_id)->toBe($moderator->id);
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

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();
        expect($review->decision)->toBe('reverted_to_draft');
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

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();
        expect($review->decision)->toBe('remoderated');
        expect($review->moderator_id)->toBe($moderator->id);
    });
});
