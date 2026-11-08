<?php

use App\Filament\Resources\AppFeedback\AppFeedbackResource;
use App\Filament\Resources\AppFeedback\Pages\ViewAppFeedback;
use App\Models\AppFeedback;
use App\Models\AppFeedbackMessage;
use App\Models\User;
use App\Notifications\AppFeedbackClosedNotification;
use App\Notifications\AppFeedbackRepliedNotification;
use App\Support\Api\Admin\AdminResourceRegistry;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('allows global administrators to view the app feedback resource', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create();

    $this->actingAs($admin)
        ->get(AppFeedbackResource::getUrl('index'))
        ->assertSuccessful();

    $this->actingAs($admin)
        ->get(AppFeedbackResource::getUrl('view', ['record' => $feedback]))
        ->assertSuccessful();
});

it('denies moderators access to app feedback', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $feedback = AppFeedback::factory()->create();

    expect($moderator->can('updateInternalNotes', $feedback))->toBeFalse()
        ->and($moderator->can('transition', $feedback))->toBeFalse();

    $this->actingAs($moderator)
        ->get(AppFeedbackResource::getUrl('index'))
        ->assertForbidden();

    $this->actingAs($moderator)
        ->get(AppFeedbackResource::getUrl('view', ['record' => $feedback]))
        ->assertForbidden();
});

it('does not expose create, edit, or delete actions', function () {
    expect(AppFeedbackResource::canCreate())->toBeFalse();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $feedback = AppFeedback::factory()->create();

    expect(AppFeedbackResource::canEdit($feedback))->toBeFalse()
        ->and(AppFeedbackResource::canDelete($feedback))->toBeFalse();
});

it('shows reporter follow-up messages on the admin view', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create();
    $message = new AppFeedbackMessage;
    $message->fill([
        'author_role' => 'reporter',
        'visibility' => 'reporter',
        'body' => 'Maklumat tambahan untuk pasukan semakan.',
    ]);
    $message->forceFill([
        'feedback_id' => $feedback->getKey(),
        'author_id' => $feedback->reporter_id,
    ]);
    $message->save();

    $this->actingAs($admin);

    Livewire::test(ViewAppFeedback::class, ['record' => $feedback->getRouteKey()])
        ->assertSee('Maklumat tambahan untuk pasukan semakan.');
});

it('updates only internal notes from the admin action', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create([
        'title' => 'Tajuk asal',
        'status' => 'new',
        'internal_notes' => null,
    ]);

    $this->actingAs($admin);

    Livewire::test(ViewAppFeedback::class, ['record' => $feedback->getRouteKey()])
        ->callAction('internal_notes', ['internal_notes' => 'Catatan untuk pasukan.'])
        ->assertHasNoActionErrors();

    $feedback->refresh();

    expect($feedback->internal_notes)->toBe('Catatan untuk pasukan.')
        ->and($feedback->title)->toBe('Tajuk asal')
        ->and($feedback->status->value)->toBe('new');
});

it('triages feedback from the admin view action', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create();

    $this->actingAs($admin);

    Livewire::test(ViewAppFeedback::class, ['record' => $feedback->getRouteKey()])
        ->callAction('triage')
        ->assertHasNoActionErrors();

    $feedback->refresh();

    expect($feedback->status->value)->toBe('triaged')
        ->and($feedback->handled_by)->toBe($admin->getKey())
        ->and($feedback->triaged_at)->not->toBeNull();
});

it('resolves feedback from the admin view action', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->triaged()->create();

    $this->actingAs($admin);

    Livewire::test(ViewAppFeedback::class, ['record' => $feedback->getRouteKey()])
        ->callAction('resolve', ['resolution_note' => 'Isu telah diselesaikan.'])
        ->assertHasNoActionErrors();

    $feedback->refresh();

    expect($feedback->status->value)->toBe('resolved')
        ->and($feedback->resolution_note)->toBe('Isu telah diselesaikan.');

    Notification::assertSentTo($feedback->reporter, AppFeedbackClosedNotification::class);
});

it('declines feedback from the admin view action', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->triaged()->create();

    $this->actingAs($admin);

    Livewire::test(ViewAppFeedback::class, ['record' => $feedback->getRouteKey()])
        ->callAction('decline', ['resolution_note' => 'Cadangan tidak diterima.'])
        ->assertHasNoActionErrors();

    $feedback->refresh();

    expect($feedback->status->value)->toBe('declined')
        ->and($feedback->resolution_note)->toBe('Cadangan tidak diterima.');
});

it('keeps app feedback out of the generic admin api resource registry', function () {
    $resources = app(AdminResourceRegistry::class)->resources();

    expect($resources)->not->toContain(AppFeedbackResource::class);
});

it('replies to feedback from the admin view action', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $reporter = User::factory()->create();
    $feedback = AppFeedback::factory()->create(['reporter_id' => $reporter->getKey()]);

    $this->actingAs($admin);

    Livewire::test(ViewAppFeedback::class, ['record' => $feedback->getRouteKey()])
        ->callAction('reply', ['body' => 'Kami sedang menyiasat isu ini.'])
        ->assertHasNoActionErrors();

    $message = $feedback->messages()->sole();

    expect($message->author_role)->toBe('maintainer')
        ->and($message->body)->toBe('Kami sedang menyiasat isu ini.');

    Notification::assertSentTo($reporter, AppFeedbackRepliedNotification::class);
});
