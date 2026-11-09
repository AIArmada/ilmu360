<?php

use AIArmada\Signals\Contracts\SignalEventIngestor;
use AIArmada\Signals\Models\SignalEvent;
use App\Features\Feedback\Actions\SubmitAppFeedbackAction;
use App\Features\Feedback\Enums\AppFeedbackCategory;
use App\Features\Feedback\Models\AppFeedback;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery\MockInterface;

it('records a safe signal when app feedback is submitted', function () {
    Notification::fake();

    $user = User::factory()->create();

    $data = [
        'submission_key' => (string) Str::uuid(),
        'category' => AppFeedbackCategory::Bug->value,
        'title' => 'Tajuk isu yang tidak boleh masuk signal',
        'description' => 'Butiran isu yang tidak boleh masuk signal telemetri.',
        'page_url' => 'https://ilmu360.test/majlis/contoh',
    ];

    app(SubmitAppFeedbackAction::class)->handle($user, $data, request());

    $event = SignalEvent::query()
        ->where('event_name', 'app_feedback.submitted')
        ->latest('occurred_at')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event?->event_category)->toBe('feedback')
        ->and(data_get($event?->properties, 'category'))->toBe(AppFeedbackCategory::Bug->value)
        ->and(data_get($event?->properties, 'status'))->toBe('new')
        ->and(data_get($event?->properties, 'title'))->toBeNull()
        ->and(data_get($event?->properties, 'description'))->toBeNull()
        ->and(data_get($event?->properties, 'page_url'))->toBeNull()
        ->and(data_get($event?->properties, 'referrer_url'))->toBeNull();
});

it('does not emit a signal when a submission is replayed', function () {
    Notification::fake();

    $user = User::factory()->create();

    $data = [
        'submission_key' => (string) Str::uuid(),
        'category' => AppFeedbackCategory::Other->value,
        'title' => 'Ujian isyarat ulangan',
        'description' => 'Ini ialah ujian isyarat untuk penghantaran semula.',
    ];

    app(SubmitAppFeedbackAction::class)->handle($user, $data, request());
    app(SubmitAppFeedbackAction::class)->handle($user, $data, request());

    expect(SignalEvent::query()->where('event_name', 'app_feedback.submitted')->count())->toBe(1);
});

it('keeps the feedback when signal ingestion fails', function () {
    Notification::fake();

    $this->mock(SignalEventIngestor::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('Signals ingestion failed.'));
    });

    $user = User::factory()->create();

    $data = [
        'submission_key' => (string) Str::uuid(),
        'category' => AppFeedbackCategory::Bug->value,
        'title' => 'Ujian kegagalan isyarat',
        'description' => 'Maklum balas mesti kekal walaupun isyarat gagal.',
    ];

    $feedback = app(SubmitAppFeedbackAction::class)->handle($user, $data, request());

    expect($feedback->exists)->toBeTrue()
        ->and(AppFeedback::query()->count())->toBe(1);
});
