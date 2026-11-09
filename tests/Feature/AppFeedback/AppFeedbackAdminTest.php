<?php

use App\Features\Feedback\Actions\UpdateAppFeedbackInternalNotesAction;
use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\AppFeedbackResource;
use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Pages\ViewAppFeedback;
use App\Features\Feedback\Models\AppFeedback;
use App\Features\Feedback\Models\AppFeedbackMessage;
use App\Features\Feedback\Notifications\AppFeedbackClosedNotification;
use App\Features\Feedback\Notifications\AppFeedbackRepliedNotification;
use App\Models\User;
use App\Platform\Admin\ExcludedFromAdminApi;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Support\Api\Admin\AdminResourceRegistry;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
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

    expect(AppFeedbackResource::class)->toImplement(ExcludedFromAdminApi::class)
        ->and($resources)->not->toContain(AppFeedbackResource::class);
});

it('registers the app feedback resource once through the feature registry', function () {
    $panelResources = Filament::getPanel('admin')->getResources();

    expect(app(FilamentResourceRegistry::class)->all())->toContain(AppFeedbackResource::class)
        ->and(array_count_values($panelResources)[AppFeedbackResource::class] ?? 0)->toBe(1);
});

it('guards internal note updates through the action', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $feedback = AppFeedback::factory()->create();

    expect(fn () => app(UpdateAppFeedbackInternalNotesAction::class)->handle(
        $moderator,
        $feedback,
        'Nota dalaman.',
    ))->toThrow(AuthorizationException::class);

    expect($feedback->fresh()->internal_notes)->toBeNull();
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
