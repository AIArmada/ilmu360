<?php

use App\Actions\AppFeedback\PostAppFeedbackMessageAction;
use App\Models\AppFeedback;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

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

it('prevents other members from replying to someone else feedback', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $feedback = AppFeedback::factory()->create([
        'reporter_id' => $owner->getKey(),
    ]);

    expect(fn () => app(PostAppFeedbackMessageAction::class)->handle($other, $feedback, 'Cubaan membalas.'))
        ->toThrow(AuthorizationException::class);
});
