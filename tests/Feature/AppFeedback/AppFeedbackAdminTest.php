<?php

use App\Features\Feedback\Actions\TransitionAppFeedbackAction;
use App\Features\Feedback\Actions\UpdateAppFeedbackInternalNotesAction;
use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\AppFeedbackResource;
use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Pages\ListAppFeedback;
use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Pages\ViewAppFeedback;
use App\Features\Feedback\Enums\AppFeedbackStatus;
use App\Features\Feedback\Models\AppFeedback;
use App\Features\Feedback\Models\AppFeedbackMessage;
use App\Features\Feedback\Notifications\AppFeedbackClosedNotification;
use App\Features\Feedback\Notifications\AppFeedbackRepliedNotification;
use App\Models\User;
use App\Platform\Admin\ExcludedFromAdminApi;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Admin\AdminResourceRegistry;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery\MockInterface;

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
    ))->toThrow(AuthorizationException::class)
        ->and($feedback->fresh()->internal_notes)->toBeNull();
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

it('defaults the feedback list to the open tab and shows closed records on their tabs', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $new = AppFeedback::factory()->create();
    $triaged = AppFeedback::factory()->triaged()->create();
    $resolved = AppFeedback::factory()->resolved()->create();
    $declined = AppFeedback::factory()->declined()->create();

    $component = Livewire::actingAs($admin)->test(ListAppFeedback::class);

    expect(array_keys($component->instance()->getTabs()))
        ->toBe(['open', 'new', 'triaged', 'resolved', 'declined', 'all'])
        ->and($component->instance()->getDefaultActiveTab())->toBe('open');

    $component
        ->assertSet('activeTab', 'open')
        ->assertCanSeeTableRecords([$new, $triaged])
        ->assertCanNotSeeTableRecords([$resolved, $declined])
        ->set('activeTab', 'resolved')
        ->assertCanSeeTableRecords([$resolved])
        ->assertCanNotSeeTableRecords([$new, $triaged, $declined])
        ->set('activeTab', 'declined')
        ->assertCanSeeTableRecords([$declined])
        ->assertCanNotSeeTableRecords([$new, $triaged, $resolved])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$new, $triaged, $resolved, $declined]);
});

it('counts new and triaged feedback in the navigation badge and clears it when closed', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    AppFeedbackResource::flushNavigationBadgeCache();

    expect(AppFeedbackResource::getNavigationBadge())->toBeNull();

    AppFeedback::factory()->create();
    $triaged = AppFeedback::factory()->triaged()->create();
    AppFeedback::factory()->resolved()->create();

    AppFeedbackResource::flushNavigationBadgeCache();

    expect(AppFeedbackResource::getNavigationBadge())->toBe('2');

    $triaged->forceFill([
        'status' => AppFeedbackStatus::Resolved,
        'resolved_at' => now(),
        'resolution_note' => 'Selesai disemak.',
        'last_state_change_at' => now(),
    ])->save();

    AppFeedbackResource::flushNavigationBadgeCache();

    expect(AppFeedbackResource::getNavigationBadge())->toBe('1');
});

it('shows a danger notification when closing feedback that was already closed with another note', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->triaged()->create();

    $this->mock(TransitionAppFeedbackAction::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')
            ->once()
            ->andThrow(ValidationException::withMessages([
                'resolution_note' => __('app-feedback.validation.resolution_note_locked'),
            ]));
    });

    Livewire::actingAs($admin)
        ->test(ViewAppFeedback::class, ['record' => $feedback->getRouteKey()])
        ->callAction('resolve', ['resolution_note' => 'Nota yang berbeza.'])
        ->assertNotified(__('app-feedback.validation.resolution_note_locked'));

    expect($feedback->fresh()->status)->toBe(AppFeedbackStatus::Triaged)
        ->and($feedback->fresh()->resolution_note)->toBeNull();
});

it('shows the follow-up author name alongside the role on the admin view', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $reporter = User::factory()->create(['name' => 'Nama Penghantar Unik']);
    $feedback = AppFeedback::factory()->create(['reporter_id' => $reporter->getKey()]);

    $message = new AppFeedbackMessage;
    $message->fill([
        'author_role' => 'reporter',
        'visibility' => 'reporter',
        'body' => 'Maklumat tambahan untuk pasukan semakan.',
    ]);
    $message->forceFill([
        'feedback_id' => $feedback->getKey(),
        'author_id' => $reporter->getKey(),
    ]);
    $message->save();

    $this->actingAs($admin);

    Livewire::test(ViewAppFeedback::class, ['record' => $feedback->getRouteKey()])
        ->assertSee('Penghantar (Nama Penghantar Unik)');
});
