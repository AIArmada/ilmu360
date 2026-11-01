<?php

use App\Actions\AppFeedback\SubmitAppFeedbackAction;
use App\Enums\AppFeedbackCategory;
use App\Enums\AppFeedbackStatus;
use App\Livewire\Pages\AppFeedback\Index;
use App\Models\AppFeedback;
use App\Models\User;
use App\Notifications\AppFeedbackReceivedNotification;
use App\Notifications\AppFeedbackSubmittedNotification;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Uid\Ulid;

it('requires authentication to view the app feedback page', function () {
    $this->get('/maklum-balas')->assertRedirect();
});

it('renders the app feedback page for members', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/maklum-balas')
        ->assertSuccessful()
        ->assertSee(__('app-feedback.title'));
});

it('submits app feedback with the reporter and initial status', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->fillForm([
            'category' => AppFeedbackCategory::Bug->value,
            'title' => 'Butang simpan tidak berfungsi',
            'description' => 'Butang simpan pada halaman majlis tidak memberi respons.',
            'include_diagnostics' => false,
        ])
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertSet('successMessage', __('app-feedback.flash.submitted'));

    $feedback = AppFeedback::sole();

    expect($feedback->reporter_id)->toBe($user->getKey())
        ->and($feedback->category)->toBe(AppFeedbackCategory::Bug)
        ->and($feedback->status)->toBe(AppFeedbackStatus::New)
        ->and($feedback->last_state_change_at)->not->toBeNull();

    Notification::assertSentTo($user, AppFeedbackReceivedNotification::class);
});

it('notifies global maintainers about a new submission', function () {
    Notification::fake();

    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->fillForm([
            'category' => AppFeedbackCategory::FeatureRequest->value,
            'title' => 'Tambah penapis mengikut negeri',
            'description' => 'Saya ingin menapis senarai majlis mengikut negeri.',
        ])
        ->call('submit')
        ->assertHasNoFormErrors();

    Notification::assertSentTo($admin, AppFeedbackSubmittedNotification::class);
});

it('validates required feedback fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->fillForm([
            'category' => AppFeedbackCategory::Bug->value,
            'title' => '',
            'description' => '',
        ])
        ->call('submit')
        ->assertHasFormErrors(['title', 'description']);
});

it('rejects whitespace-only feedback content', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->fillForm([
            'category' => AppFeedbackCategory::Bug->value,
            'title' => '     ',
            'description' => '               ',
        ])
        ->call('submit')
        ->assertHasFormErrors(['title', 'description']);

    expect(AppFeedback::query()->count())->toBe(0);
});

it('rejects submissions from blocked users', function () {
    $this->seed(PermissionSeeder::class);

    $user = User::factory()->create();
    $user->givePermissionTo('feedback.blocked');

    expect($user->canSubmitAppFeedback())->toBeFalse();

    $data = [
        'submission_key' => (string) Str::uuid(),
        'category' => AppFeedbackCategory::Bug->value,
        'title' => 'Ujian sekatan',
        'description' => 'Pengguna yang disekat tidak boleh menghantar maklum balas.',
        'include_diagnostics' => false,
    ];

    expect(fn () => app(SubmitAppFeedbackAction::class)->handle($user, $data, request()))
        ->toThrow(AuthorizationException::class);
});

it('shows a friendly error when a blocked user submits the form', function () {
    $this->seed(PermissionSeeder::class);

    $user = User::factory()->create();
    $user->givePermissionTo('feedback.blocked');

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->fillForm([
            'category' => AppFeedbackCategory::Bug->value,
            'title' => 'Ujian sekatan borang',
            'description' => 'Pengguna yang disekat tidak boleh menghantar maklum balas.',
        ])
        ->call('submit')
        ->assertHasFormErrors(['category']);

    expect(AppFeedback::query()->count())->toBe(0);
});

it('limits each user to three new submissions per hour', function () {
    Notification::fake();

    $user = User::factory()->create();

    AppFeedback::factory()->count(3)->create([
        'reporter_id' => $user->getKey(),
        'created_at' => now()->subMinutes(10),
    ]);

    $data = [
        'submission_key' => (string) Str::uuid(),
        'category' => AppFeedbackCategory::Bug->value,
        'title' => 'Ujian had',
        'description' => 'Ini ialah ujian had penghantaran maklum balas.',
        'include_diagnostics' => false,
    ];

    expect(fn () => app(SubmitAppFeedbackAction::class)->handle($user, $data, request()))
        ->toThrow(ValidationException::class);
});

it('returns the same record when the same submission is retried', function () {
    Notification::fake();

    $user = User::factory()->create();

    $data = [
        'submission_key' => (string) Str::uuid(),
        'category' => AppFeedbackCategory::Other->value,
        'title' => 'Ujian ulangan',
        'description' => 'Ini ialah ujian penghantaran semula yang sama.',
        'include_diagnostics' => false,
    ];

    $first = app(SubmitAppFeedbackAction::class)->handle($user, $data, request());
    $second = app(SubmitAppFeedbackAction::class)->handle($user, $data, request());

    expect($second->getKey())->toBe($first->getKey())
        ->and(AppFeedback::query()->count())->toBe(1);
});

it('rejects a reused submission key with different content', function () {
    Notification::fake();

    $user = User::factory()->create();

    $data = [
        'submission_key' => (string) Str::uuid(),
        'category' => AppFeedbackCategory::Bug->value,
        'title' => 'Ujian kunci',
        'description' => 'Ini ialah kandungan pertama untuk ujian kunci.',
        'include_diagnostics' => false,
    ];

    app(SubmitAppFeedbackAction::class)->handle($user, $data, request());

    $data['description'] = 'Ini ialah kandungan kedua yang berbeza untuk ujian kunci.';

    expect(fn () => app(SubmitAppFeedbackAction::class)->handle($user, $data, request()))
        ->toThrow(ValidationException::class);
});

it('stores evidence on the private app feedback disk', function () {
    Notification::fake();
    Storage::fake('app_feedback_private');

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->fillForm([
            'category' => AppFeedbackCategory::Bug->value,
            'title' => 'Ujian lampiran',
            'description' => 'Ini ialah ujian lampiran fail maklum balas.',
            'evidence' => [UploadedFile::fake()->image('screenshot.png')],
        ])
        ->call('submit')
        ->assertHasNoFormErrors();

    $feedback = AppFeedback::sole();
    $media = $feedback->getMedia('evidence')->first();

    expect($media)->not->toBeNull()
        ->and($media->disk)->toBe('app_feedback_private');

    Storage::disk('app_feedback_private')->assertExists($media->getPathRelativeToRoot());

    $this->actingAs($user)
        ->get(route('app-feedback.evidence.download', [
            'feedback' => $feedback,
            'mediaId' => $media->getKey(),
        ]))
        ->assertDownload($media->file_name);
});

it('preserves distinct evidence files uploaded in the same timestamp bucket', function () {
    Notification::fake();
    Storage::fake('app_feedback_private');

    $user = User::factory()->create();
    $first = UploadedFile::fake()->image('first.png', 10, 10);
    $second = UploadedFile::fake()->image('second.png', 20, 20);
    $firstContents = file_get_contents($first->getRealPath());
    $secondContents = file_get_contents($second->getRealPath());

    Str::createUlidsUsingSequence([
        new Ulid('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        new Ulid('01ARZ3NDEKTSV4RRFFQ69G5FAW'),
    ]);

    try {
        $feedback = app(SubmitAppFeedbackAction::class)->handle($user, [
            'submission_key' => (string) Str::uuid(),
            'category' => AppFeedbackCategory::Bug->value,
            'title' => 'Multiple screenshots',
            'description' => 'Both screenshots must retain their original contents.',
            'evidence' => [$first, $second],
        ], request());
    } finally {
        Str::createUlidsNormally();
    }

    $media = $feedback->getMedia('evidence');

    expect($media)->toHaveCount(2);
    expect($media[0]->getPathRelativeToRoot())->not->toBe($media[1]->getPathRelativeToRoot());
    expect(Storage::disk('app_feedback_private')->get($media[0]->getPathRelativeToRoot()))->toBe($firstContents);
    expect(Storage::disk('app_feedback_private')->get($media[1]->getPathRelativeToRoot()))->toBe($secondContents);

    Notification::assertSentTo($user, AppFeedbackReceivedNotification::class);
});

it('does not expose evidence to other users or moderators', function () {
    Notification::fake();
    Storage::fake('app_feedback_private');

    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);

    $owner = User::factory()->create();
    $other = User::factory()->create();
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $this->actingAs($owner);

    Livewire::test(Index::class)
        ->fillForm([
            'category' => AppFeedbackCategory::Bug->value,
            'title' => 'Ujian privasi lampiran',
            'description' => 'Ini ialah ujian privasi lampiran maklum balas.',
            'evidence' => [UploadedFile::fake()->image('private.png')],
        ])
        ->call('submit')
        ->assertHasNoFormErrors();

    $feedback = AppFeedback::sole();
    $media = $feedback->getMedia('evidence')->first();

    $url = route('app-feedback.evidence.download', [
        'feedback' => $feedback,
        'mediaId' => $media->getKey(),
    ]);

    $this->actingAs($other)->get($url)->assertNotFound();
    $this->actingAs($moderator)->get($url)->assertNotFound();

    $malformedUrl = route('app-feedback.evidence.download', [
        'feedback' => $feedback,
        'mediaId' => 'not-a-number',
    ]);

    $this->actingAs($owner)->get($malformedUrl)->assertNotFound();
});

it('shows only the authenticated member feedback history', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    AppFeedback::factory()->create([
        'reporter_id' => $user->getKey(),
        'title' => 'Maklum balas saya yang unik',
    ]);

    AppFeedback::factory()->create([
        'reporter_id' => $other->getKey(),
        'title' => 'Maklum balas orang lain',
    ]);

    $this->actingAs($user)
        ->get('/maklum-balas')
        ->assertSuccessful()
        ->assertSee('Maklum balas saya yang unik')
        ->assertDontSee('Maklum balas orang lain');
});

it('returns not found for another member selected feedback', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $feedback = AppFeedback::factory()->create([
        'reporter_id' => $other->getKey(),
    ]);

    $this->actingAs($user)
        ->get('/maklum-balas?lihat='.$feedback->getKey())
        ->assertNotFound();
});

it('returns not found for a malformed selected feedback id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/maklum-balas?lihat=not-a-uuid')
        ->assertNotFound();
});
