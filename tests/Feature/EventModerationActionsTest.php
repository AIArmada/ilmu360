<?php

use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\Institution;
use App\Models\ModerationReview;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\SpeakerSearchService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();
});

describe('Approve Action (ViewEvent)', function () {
    it('allows moderator to approve a pending event', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $submitter = User::factory()->create();

        $event = Event::factory()->create([
            'status' => 'pending',
            'submitter_id' => $submitter->id,
            'published_at' => null,
        ]);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->callAction('approve', ['note' => 'Looks great!'])
            ->assertNotified();

        $event->refresh();
        expect((string) $event->status)->toBe('approved');
        expect($event->published_at)->not->toBeNull();

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();
        expect($review->decision)->toBe('approved');
        expect($review->moderator_id)->toBe($moderator->id);

        $this->assertDatabaseHas('notification_messages', [
            'user_id' => $submitter->id,
            'trigger' => 'submission_approved',
        ]);
    });

    it('refreshes public search caches when approval verifies related speakers and institutions', function () {
        config()->set('scout.driver', 'collection');

        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $submitter = User::factory()->create();
        $institution = Institution::factory()->create([
            'name' => 'Samad Institution',
            'status' => 'pending',
            'is_active' => true,
        ]);
        $speaker = Speaker::factory()->create([
            'name' => 'Samad Speaker',
            'status' => 'pending',
            'is_active' => true,
        ]);

        $event = Event::factory()->create([
            'status' => 'pending',
            'submitter_id' => $submitter->id,
            'published_at' => null,
            'institution_id' => $institution->id,
        ]);
        $event->speakers()->attach($speaker->id);

        $institutionSearchService = app(InstitutionSearchService::class);
        $speakerSearchService = app(SpeakerSearchService::class);

        expect($institutionSearchService->publicSearchIds('samad'))->toBe([])
            ->and($speakerSearchService->publicSearchIds('samad'))->toBe([]);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->callAction('approve', ['note' => 'Looks great!'])
            ->assertNotified();

        expect($institution->fresh()?->status)->toBe('verified')
            ->and($speaker->fresh()?->status)->toBe('verified')
            ->and($institutionSearchService->publicSearchIds('samad'))->toContain((string) $institution->id)
            ->and($speakerSearchService->publicSearchIds('samad'))->toContain((string) $speaker->id);
    });

    it('hides approve action for non-pending events', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $event = Event::factory()->create(['status' => 'approved']);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->assertActionHidden('approve');
    });
});

describe('Reject Action (ViewEvent)', function () {
    it('allows moderator to reject a pending event with reason', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $submitter = User::factory()->create();

        $event = Event::factory()->create([
            'status' => 'pending',
            'submitter_id' => $submitter->id,
        ]);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->assertActionVisible('reject')
            ->mountAction('reject')
            ->set('mountedActions.0.data.reason_code', 'spam')
            ->set('mountedActions.0.data.note', 'This is spam content.')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $event->refresh();
        expect((string) $event->status)->toBe('rejected');

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();
        expect($review->decision)->toBe('rejected');
        expect($review->reason_code)->toBe('spam');

        $this->assertDatabaseHas('notification_messages', [
            'user_id' => $submitter->id,
            'trigger' => 'submission_rejected',
        ]);
    });

    it('requires reason code and note to reject', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $event = Event::factory()->create(['status' => 'pending']);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->mountAction('reject')
            ->callMountedAction()
            ->assertHasActionErrors(['reason_code', 'note']);
    });
});

describe('Cancel Action (ViewEvent)', function () {
    it('allows moderator to cancel an approved event and notify affected users', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $submitter = User::factory()->create();
        $goingUser = User::factory()->create();

        $event = Event::factory()->create([
            'status' => 'approved',
            'submitter_id' => $submitter->id,
            'is_active' => true,
        ]);

        $event->goingBy()->attach($goingUser->id);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->assertActionVisible('cancel')
            ->callAction('cancel', ['note' => 'Venue closed due to emergency.'])
            ->assertNotified();

        $event->refresh();
        expect((string) $event->status)->toBe('cancelled');
        expect($event->is_active)->toBeTrue();

        $announcement = EventChangeAnnouncement::query()->where('event_id', $event->id)->latest()->first();
        expect($announcement)->toBeInstanceOf(EventChangeAnnouncement::class);
        expect($announcement->type->value)->toBe('cancelled');

        $this->assertDatabaseHas('notification_messages', [
            'user_id' => $submitter->id,
            'trigger' => 'event_cancelled',
        ]);
        $this->assertDatabaseHas('notification_messages', [
            'user_id' => $goingUser->id,
            'trigger' => 'event_cancelled',
        ]);
    });

    it('hides cancel action for non-pending and non-approved events', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $event = Event::factory()->create(['status' => 'rejected']);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->assertActionHidden('cancel');
    });
});

describe('Request Changes Action (ViewEvent)', function () {
    it('allows moderator to request changes on a pending event', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $submitter = User::factory()->create();

        $event = Event::factory()->create([
            'status' => 'pending',
            'submitter_id' => $submitter->id,
        ]);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->assertActionVisible('request_changes')
            ->mountAction('request_changes')
            ->set('mountedActions.0.data.reason_code', 'incomplete_info')
            ->set('mountedActions.0.data.note', 'Please add venue details.')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $event->refresh();
        expect((string) $event->status)->toBe('needs_changes');

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();
        expect($review->decision)->toBe('needs_changes');
        expect($review->reason_code)->toBe('incomplete_info');

        $this->assertDatabaseHas('notification_messages', [
            'user_id' => $submitter->id,
            'trigger' => 'submission_needs_changes',
        ]);
    });
});

describe('Submit for Moderation Action (ViewEvent)', function () {
    it('allows moderator to submit a draft event for moderation', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $event = Event::factory()->create([
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->assertActionVisible('submit_for_moderation')
            ->callAction('submit_for_moderation')
            ->assertNotified();

        $event->refresh();
        expect((string) $event->status)->toBe('pending');
    });

    it('hides submit for moderation action for non-draft events', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $pendingEvent = Event::factory()->create(['status' => 'pending']);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $pendingEvent->id])
            ->assertActionHidden('submit_for_moderation');
    });
});

describe('Reconsider Action (ViewEvent)', function () {
    it('allows moderator to reconsider a rejected event', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $event = Event::factory()->create(['status' => 'rejected']);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->callAction('reconsider', ['note' => 'Re-reviewing this event.'])
            ->assertNotified();

        $event->refresh();
        expect((string) $event->status)->toBe('pending');

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();
        expect($review->decision)->toBe('reconsidered');
    });

    it('hides reconsider action for non-rejected events', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $event = Event::factory()->create(['status' => 'pending']);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->assertActionHidden('reconsider');
    });
});

describe('Remoderate Action (ViewEvent)', function () {
    it('allows moderator to send approved event back for re-moderation', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $event = Event::factory()->create([
            'status' => 'approved',
            'published_at' => now(),
        ]);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->callAction('remoderate', ['note' => 'Content needs re-review.'])
            ->assertNotified();

        $event->refresh();
        expect((string) $event->status)->toBe('pending');

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();
        expect($review->decision)->toBe('remoderated');
    });

    it('hides remoderate action for non-approved events', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $event = Event::factory()->create(['status' => 'pending']);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->assertActionHidden('remoderate');
    });
});

describe('Revert to Draft Action (ViewEvent)', function () {
    it('allows moderator to revert rejected event to draft', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $event = Event::factory()->create(['status' => 'rejected']);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->callAction('revert_to_draft', ['note' => 'Reverting for submitter to rework.'])
            ->assertNotified();

        $event->refresh();
        expect((string) $event->status)->toBe('draft');
        expect($event->published_at)->toBeNull();

        $review = ModerationReview::where('event_id', $event->id)->latest()->first();
        expect($review->decision)->toBe('reverted_to_draft');
    });

    it('allows moderator to revert needs_changes event to draft', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $event = Event::factory()->create(['status' => 'needs_changes']);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->callAction('revert_to_draft')
            ->assertNotified();

        $event->refresh();
        expect((string) $event->status)->toBe('draft');
    });

    it('hides revert to draft for pending and approved events', function () {
        $moderator = User::factory()->create();
        $moderator->assignRole('super_admin');

        $pendingEvent = Event::factory()->create(['status' => 'pending']);
        $approvedEvent = Event::factory()->create(['status' => 'approved']);

        $this->actingAs($moderator);

        Livewire::test(ViewEvent::class, ['record' => $pendingEvent->id])
            ->assertActionHidden('revert_to_draft');

        Livewire::test(ViewEvent::class, ['record' => $approvedEvent->id])
            ->assertActionHidden('revert_to_draft');
    });
});

describe('Non-moderator visibility', function () {
    it('hides all moderation actions for non-moderator users', function () {
        $user = User::factory()->create();
        $user->assignRole('editor');

        $event = Event::factory()->create([
            'status' => 'pending',
            'submitter_id' => $user->id,
        ]);

        $this->actingAs($user);

        Livewire::test(ViewEvent::class, ['record' => $event->id])
            ->assertActionHidden('approve')
            ->assertActionHidden('reject')
            ->assertActionHidden('request_changes');
    });
});
