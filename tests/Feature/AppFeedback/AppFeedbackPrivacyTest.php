<?php

use App\Actions\AppFeedback\SubmitAppFeedbackAction;
use App\Enums\AppFeedbackCategory;
use App\Models\AppFeedback;
use App\Models\User;
use App\Support\AppFeedback\AppFeedbackInput;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

it('sanitizes same-origin page urls and strips query strings and fragments', function () {
    config()->set('app.url', 'https://ilmu360.test');

    expect(AppFeedbackInput::sanitizePageUrl('https://ilmu360.test/majlis/contoh?utm_source=test#bahagian'))
        ->toBe('https://ilmu360.test/majlis/contoh')
        ->and(AppFeedbackInput::sanitizePageUrl('/institusi/contoh?tab=about'))
        ->toBe('https://ilmu360.test/institusi/contoh');
});

it('rejects external, credential-bearing, and private page urls', function () {
    config()->set('app.url', 'https://ilmu360.test');

    expect(AppFeedbackInput::sanitizePageUrl('https://evil.example/majlis'))->toBeNull()
        ->and(AppFeedbackInput::sanitizePageUrl('https://user:pass@ilmu360.test/majlis'))->toBeNull()
        ->and(AppFeedbackInput::sanitizePageUrl('/dashboard'))->toBeNull()
        ->and(AppFeedbackInput::sanitizePageUrl('/sumbangan'))->toBeNull()
        ->and(AppFeedbackInput::sanitizePageUrl('/majlis/contoh/pas/abc'))->toBeNull()
        ->and(AppFeedbackInput::sanitizePageUrl('/majlis/contoh/pendaftaran/abc'))->toBeNull()
        ->and(AppFeedbackInput::sanitizePageUrl('/maklum-balas'))->toBeNull();
});

it('stores diagnostics only when the reporter opts in', function () {
    Notification::fake();

    $user = User::factory()->create();

    $data = [
        'submission_key' => (string) Str::uuid(),
        'category' => AppFeedbackCategory::Bug->value,
        'title' => 'Ujian diagnostik',
        'description' => 'Ini ialah ujian diagnostik maklum balas.',
        'include_diagnostics' => true,
    ];

    $feedback = app(SubmitAppFeedbackAction::class)->handle($user, $data, request());

    expect($feedback->diagnostics)->toBeArray()
        ->and($feedback->diagnostics)->toHaveKey('user_agent')
        ->and($feedback->diagnostics)->not->toHaveKey('ip_address');

    $withoutDiagnostics = app(SubmitAppFeedbackAction::class)->handle($user, [
        ...$data,
        'submission_key' => (string) Str::uuid(),
        'include_diagnostics' => false,
    ], request());

    expect($withoutDiagnostics->diagnostics)->toBeNull();
});

it('does not expose internal notes or diagnostics on the member page', function () {
    $user = User::factory()->create();

    $feedback = AppFeedback::factory()->create([
        'reporter_id' => $user->getKey(),
        'title' => 'Maklum balas awam',
        'description' => 'Penerangan yang boleh dilihat oleh penghantar.',
        'internal_notes' => 'Nota dalaman yang sangat sensitif.',
        'diagnostics' => ['user_agent' => 'Sensitive browser agent'],
    ]);

    $this->actingAs($user)
        ->get('/maklum-balas')
        ->assertSuccessful()
        ->assertSee('Maklum balas awam')
        ->assertDontSee('Nota dalaman yang sangat sensitif.')
        ->assertDontSee('Sensitive browser agent')
        ->assertDontSee('/storage/');
});
