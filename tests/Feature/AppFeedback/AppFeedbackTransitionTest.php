<?php

use App\Actions\AppFeedback\TransitionAppFeedbackAction;
use App\Enums\AppFeedbackStatus;
use App\Models\AppFeedback;
use App\Models\User;
use App\Notifications\AppFeedbackClosedNotification;
use App\Notifications\AppFeedbackReceivedNotification;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('moves feedback from new to triaged and records the handler', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create();

    $result = app(TransitionAppFeedbackAction::class)->handle(
        $admin,
        $feedback,
        AppFeedbackStatus::Triaged,
    );

    expect($result->status)->toBe(AppFeedbackStatus::Triaged)
        ->and($result->handled_by)->toBe($admin->getKey())
        ->and($result->triaged_at)->not->toBeNull()
        ->and($result->last_state_change_at)->not->toBeNull();
});

it('requires a resolution note before closing feedback', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->triaged()->create();

    expect(fn () => app(TransitionAppFeedbackAction::class)->handle(
        $admin,
        $feedback,
        AppFeedbackStatus::Resolved,
    ))->toThrow(ValidationException::class);
});

it('resolves feedback and notifies the reporter', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->triaged()->create();
    $reporter = $feedback->reporter;

    $result = app(TransitionAppFeedbackAction::class)->handle(
        $admin,
        $feedback,
        AppFeedbackStatus::Resolved,
        'Isu telah dibaiki dalam keluaran terkini.',
    );

    expect($result->status)->toBe(AppFeedbackStatus::Resolved)
        ->and($result->resolved_at)->not->toBeNull()
        ->and($result->resolution_note)->toBe('Isu telah dibaiki dalam keluaran terkini.');

    Notification::assertSentTo($reporter, AppFeedbackClosedNotification::class);
});

it('declines feedback and notifies the reporter', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->triaged()->create();
    $reporter = $feedback->reporter;

    $result = app(TransitionAppFeedbackAction::class)->handle(
        $admin,
        $feedback,
        AppFeedbackStatus::Declined,
        'Cadangan ini tidak selari dengan hala tuju produk buat masa ini.',
    );

    expect($result->status)->toBe(AppFeedbackStatus::Declined)
        ->and($result->declined_at)->not->toBeNull();

    Notification::assertSentTo($reporter, AppFeedbackClosedNotification::class);
});

it('rejects invalid status transitions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create();

    expect(fn () => app(TransitionAppFeedbackAction::class)->handle(
        $admin,
        $feedback,
        AppFeedbackStatus::Resolved,
        'Tidak dibenarkan.',
    ))->toThrow(ValidationException::class);
});

it('treats an exact repeated resolution as a no-op', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->triaged()->create();
    $first = app(TransitionAppFeedbackAction::class)->handle(
        $admin,
        $feedback,
        AppFeedbackStatus::Resolved,
        'Selesai.',
    );

    $resolvedAt = $first->resolved_at;

    $second = app(TransitionAppFeedbackAction::class)->handle(
        $admin,
        $first,
        AppFeedbackStatus::Resolved,
        'Selesai.',
    );

    expect($second->getKey())->toBe($first->getKey())
        ->and($second->resolved_at?->equalTo($resolvedAt))->toBeTrue();

    Notification::assertSentToTimes($feedback->reporter, AppFeedbackClosedNotification::class, 1);
});

it('rejects closing feedback with a different note', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->triaged()->create();
    app(TransitionAppFeedbackAction::class)->handle(
        $admin,
        $feedback,
        AppFeedbackStatus::Resolved,
        'Selesai.',
    );

    expect(fn () => app(TransitionAppFeedbackAction::class)->handle(
        $admin,
        $feedback->fresh(),
        AppFeedbackStatus::Resolved,
        'Nota yang berbeza.',
    ))->toThrow(ValidationException::class);
});

it('denies status transitions to moderators', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $feedback = AppFeedback::factory()->create();

    expect(fn () => app(TransitionAppFeedbackAction::class)->handle(
        $moderator,
        $feedback,
        AppFeedbackStatus::Triaged,
    ))->toThrow(AuthorizationException::class);
});

it('opens older feedback from reporter notification emails', function (string $notificationClass) {
    $this->freezeTime();

    $reporter = User::factory()->create();
    $feedback = AppFeedback::factory()
        ->resolved($reporter, 'Masalah terdahulu telah diselesaikan.')
        ->create([
            'reporter_id' => $reporter->getKey(),
            'title' => 'Maklum balas terdahulu untuk disemak',
            'created_at' => now()->subDay(),
        ]);

    AppFeedback::factory()->count(5)->create([
        'reporter_id' => $reporter->getKey(),
    ]);

    /** @var AppFeedbackClosedNotification|AppFeedbackReceivedNotification $notification */
    $notification = new $notificationClass($feedback);
    $actionUrl = $notification->toMail($reporter)->actionUrl;

    $this->actingAs($reporter)
        ->get(route('app-feedback.index'))
        ->assertSuccessful()
        ->assertDontSee($feedback->title);

    $this->get($actionUrl)
        ->assertSuccessful()
        ->assertSee($feedback->title)
        ->assertSee($feedback->resolution_note)
        ->assertSee('id="maklum-balas-'.$feedback->getKey().'"', false);
})->with([
    'received' => AppFeedbackReceivedNotification::class,
    'closed' => AppFeedbackClosedNotification::class,
]);
