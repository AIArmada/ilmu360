<?php

use App\Actions\AppFeedback\PostAppFeedbackMessageAction;
use App\Models\AppFeedback;
use App\Models\User;
use App\Notifications\AppFeedbackRepliedNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('lets the reporter reply to their own feedback', function () {
    $user = User::factory()->create();

    $feedback = AppFeedback::factory()->create([
        'reporter_id' => $user->getKey(),
    ]);

    $message = app(PostAppFeedbackMessageAction::class)->handle($user, $feedback, 'Maklumat tambahan: berlaku di Chrome.');

    expect($message->visibility)->toBe('reporter')
        ->and($feedback->messages()->count())->toBe(1)
        ->and($message->body)->toBe('Maklumat tambahan: berlaku di Chrome.');
});

it('rejects replies to closed feedback', function () {
    $owner = User::factory()->create();

    $feedback = AppFeedback::factory()->resolved()->create([
        'reporter_id' => $owner->getKey(),
    ]);

    expect(fn () => app(PostAppFeedbackMessageAction::class)->handle($owner, $feedback, 'Maklumat tambahan.'))
        ->toThrow(ValidationException::class);
});

it('prevents other members from replying to someone else feedback', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $feedback = AppFeedback::factory()->create([
        'reporter_id' => $owner->getKey(),
    ]);

    expect(fn () => app(PostAppFeedbackMessageAction::class)->handle($other, $feedback, 'Cubaan membalas.'))
        ->toThrow(AuthorizationException::class);
});

it('notifies the reporter when a maintainer replies', function () {
    Notification::fake();

    $reporter = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create(['reporter_id' => $reporter->getKey()]);

    $message = app(PostAppFeedbackMessageAction::class)->handle($admin, $feedback, 'Kami sedang menyiasat.');

    expect($message->author_role)->toBe('maintainer');

    Notification::assertSentTo($reporter, AppFeedbackRepliedNotification::class);
});

it('does not email the reporter when they reply', function () {
    Notification::fake();

    $reporter = User::factory()->create();
    $feedback = AppFeedback::factory()->create(['reporter_id' => $reporter->getKey()]);

    app(PostAppFeedbackMessageAction::class)->handle($reporter, $feedback, 'Maklumat tambahan.');

    Notification::assertNothingSent();
});

it('does not email a maintainer about their own reply', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create(['reporter_id' => $admin->getKey()]);

    app(PostAppFeedbackMessageAction::class)->handle($admin, $feedback, 'Balasan kepada diri sendiri.');

    Notification::assertNothingSent();
});

it('still saves the message when the reply email fails', function () {
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('mail transport down'));
    Log::shouldReceive('warning')->once();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $feedback = AppFeedback::factory()->create(['reporter_id' => User::factory()->create()->getKey()]);

    $message = app(PostAppFeedbackMessageAction::class)->handle($admin, $feedback, 'Balasan ujian.');

    expect($message->exists)->toBeTrue()
        ->and($feedback->messages()->count())->toBe(1);
});
