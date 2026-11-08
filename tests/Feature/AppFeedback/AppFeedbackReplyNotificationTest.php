<?php

use App\Models\AppFeedback;
use App\Models\AppFeedbackMessage;
use App\Models\User;
use App\Notifications\AppFeedbackRepliedNotification;

it('builds the reply email with the message and thread link', function () {
    $reporter = User::factory()->create(['name' => 'Aminah']);
    $feedback = AppFeedback::factory()->create([
        'reporter_id' => $reporter->getKey(),
        'title' => 'Butang simpan gagal',
    ]);

    $message = AppFeedbackMessage::factory()->create([
        'feedback_id' => $feedback->getKey(),
        'author_role' => 'maintainer',
        'visibility' => 'reporter',
        'body' => 'Kami sedang menyiasat isu ini.',
    ]);

    $mail = (new AppFeedbackRepliedNotification($feedback, $message))->toMail($reporter);

    expect($mail->subject)->toContain('Butang simpan gagal')
        ->and(implode("\n", $mail->introLines))->toContain('Kami sedang menyiasat isu ini.')
        ->and($mail->actionUrl)->toBe(
            route('app-feedback.index', ['lihat' => $feedback->getKey()]).'#maklum-balas-'.$feedback->getKey()
        );
});

it('shows the reply thread on older feedback opened from a reply email', function () {
    $reporter = User::factory()->create();
    $feedback = AppFeedback::factory()->create([
        'reporter_id' => $reporter->getKey(),
        'title' => 'Maklum balas lama',
    ]);

    AppFeedbackMessage::factory()->create([
        'feedback_id' => $feedback->getKey(),
        'author_role' => 'maintainer',
        'visibility' => 'reporter',
        'body' => 'Balasan pasukan untuk maklum balas lama.',
    ]);

    AppFeedback::factory()->count(5)->create(['reporter_id' => $reporter->getKey()]);

    $this->actingAs($reporter)
        ->get(route('app-feedback.index', ['lihat' => $feedback->getKey()]))
        ->assertSuccessful()
        ->assertSee('Balasan pasukan untuk maklum balas lama.')
        ->assertSee('id="maklum-balas-'.$feedback->getKey().'"', false);
});
