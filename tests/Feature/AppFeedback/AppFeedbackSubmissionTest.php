<?php

use App\Features\Feedback\Actions\SubmitAppFeedbackAction;
use App\Features\Feedback\EntryPoints\Livewire\Pages\AppFeedback\Index;
use App\Features\Feedback\Enums\AppFeedbackCategory;
use App\Features\Feedback\Enums\AppFeedbackStatus;
use App\Features\Feedback\Models\AppFeedback;
use App\Features\Feedback\Notifications\AppFeedbackReceivedNotification;
use App\Features\Feedback\Notifications\AppFeedbackSubmittedNotification;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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

it('lets members edit the related page link', function () {
    Notification::fake();

    $this->actingAs(User::factory()->create());

    Livewire::test(Index::class)
        ->assertFormFieldExists('page_url', fn (TextInput $field): bool => ! $field->isReadOnly())
        ->fillForm([
            'category' => AppFeedbackCategory::Bug->value,
            'title' => 'Pautan halaman boleh diubah',
            'description' => 'Pengguna boleh mengubah pautan halaman berkaitan.',
            'page_url' => 'https://ilmu360.test/majlis/contoh',
        ])
        ->call('submit')
        ->assertHasNoFormErrors();

    expect(AppFeedback::sole()->page_url)->toBe('https://ilmu360.test/majlis/contoh');
});

it('prefills the related page from the dari path', function () {
    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['dari' => 'tambah-majlis'])
        ->test(Index::class)
        ->assertSet('data.page_url', 'https://ilmu360.test/tambah-majlis');
});

it('offers merged and simplified feedback categories', function () {
    app()->setLocale('ms');

    expect(AppFeedbackCategory::options())->toBe([
        'bug' => 'Ralat',
        'feature_request' => 'Cadangan',
        'performance' => 'Prestasi',
        'other' => 'Lain-lain',
    ])
        ->and(AppFeedbackCategory::UiUx->getLabel())->toBe('Cadangan')
        ->and(__('app-feedback.form.description'))->not->toContain('Jangan sertakan');
});

it('stores structured bug details without concatenation', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->fillForm([
            'category' => AppFeedbackCategory::Bug->value,
            'title' => 'Butang simpan gagal',
            'description' => 'Menekan simpan tiada respons di halaman majlis.',
            'details' => ['expected' => 'Rekod tersimpan', 'steps' => "1. Buka majlis\n2. Tekan simpan"],
        ])
        ->call('submit')
        ->assertHasNoFormErrors();

    expect(AppFeedback::sole()->details['expected'])->toBe('Rekod tersimpan');
});

it('switches the optional questions to match the selected category', function (AppFeedbackCategory $category, bool $hasProblem, bool $hasSuggestion) {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(Index::class)
        ->assertFormFieldExists('category', fn (Select $field): bool => $field->isLive())
        ->assertFormFieldVisible('details.steps')
        ->set('data.category', $category->value)
        ->assertFormFieldHidden('details.steps')
        ->assertFormFieldHidden('details.expected')
        ->assertFormFieldHidden('details.actual');

    if ($hasProblem) {
        $component->assertFormFieldVisible('details.problem');
    } else {
        $component->assertFormFieldHidden('details.problem');
    }

    if ($hasSuggestion) {
        $component->assertFormFieldVisible('details.suggestion');
    } else {
        $component->assertFormFieldHidden('details.suggestion');
    }

    $component->set('data.category', AppFeedbackCategory::Bug->value)
        ->assertFormFieldVisible('details.steps')
        ->assertFormFieldVisible('details.expected')
        ->assertFormFieldVisible('details.actual')
        ->assertFormFieldHidden('details.problem')
        ->assertFormFieldHidden('details.suggestion');
})->with([
    'feature request' => [AppFeedbackCategory::FeatureRequest, true, true],
    'experience' => [AppFeedbackCategory::UiUx, true, true],
    'performance' => [AppFeedbackCategory::Performance, true, false],
    'other' => [AppFeedbackCategory::Other, false, false],
]);

it('renders translated feedback guidance', function (string $locale) {
    $this->actingAs(User::factory()->create())
        ->get('/maklum-balas?lang='.$locale)
        ->assertSuccessful()
        ->assertDontSee('app-feedback.form.')
        ->assertSee(__('app-feedback.form.additional_details'))
        ->assertSee(__('app-feedback.form.supporting_context'));

    expect(__('app-feedback.form.details_steps_placeholder'))
        ->toContain("\n")
        ->not->toContain('\\n');
})->with(['ms', 'en']);

it('submits app feedback with the reporter and initial status', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->fillForm([
            'category' => AppFeedbackCategory::Bug->value,
            'title' => 'Butang simpan tidak berfungsi',
            'description' => 'Butang simpan pada halaman majlis tidak memberi respons.',
        ])
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertDispatched('app-feedback-submitted')
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

it('rejects description that repeats the title', function () {
    $user = User::factory()->create();

    $data = [
        'submission_key' => (string) Str::uuid(),
        'category' => AppFeedbackCategory::Bug->value,
        'title' => 'Butang rosak',
        'description' => 'Butang rosak',
    ];

    expect(fn () => app(SubmitAppFeedbackAction::class)->handle($user, $data, request()))
        ->toThrow(ValidationException::class);
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
        ->assertHasFormErrors(['title', 'description'])
        ->assertNotDispatched('app-feedback-submitted');
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
    ];

    expect(fn () => app(SubmitAppFeedbackAction::class)->handle($user, $data, request()))
        ->toThrow(ValidationException::class);
});

it('returns the same record when evidence order differs on retry', function () {
    Notification::fake();
    Storage::fake('app_feedback_private');

    $user = User::factory()->create();

    $makeFiles = function (): array {
        $dir = sys_get_temp_dir().'/app-feedback-test-'.Str::uuid()->toString();

        mkdir($dir, 0755, true);

        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        file_put_contents($dir.'/a.png', $png);
        file_put_contents($dir.'/b.png', $png);

        return [
            new UploadedFile($dir.'/a.png', 'a.png', 'image/png', null, true),
            new UploadedFile($dir.'/b.png', 'b.png', 'image/png', null, true),
        ];
    };

    $key = (string) Str::uuid();

    $base = [
        'submission_key' => $key,
        'category' => AppFeedbackCategory::Bug->value,
        'title' => 'Ujian susunan lampiran',
        'description' => 'Susunan lampiran yang berbeza tidak boleh dianggap kandungan berbeza.',
    ];

    [$a1, $b1] = $makeFiles();
    $one = app(SubmitAppFeedbackAction::class)->handle($user, [...$base, 'evidence' => [$a1, $b1]], request());

    [$a2, $b2] = $makeFiles();
    $two = app(SubmitAppFeedbackAction::class)->handle($user, [...$base, 'evidence' => [$b2, $a2]], request());

    expect($two->getKey())->toBe($one->getKey());
});

it('returns the same record when the same submission is retried', function () {
    Notification::fake();

    $user = User::factory()->create();

    $data = [
        'submission_key' => (string) Str::uuid(),
        'category' => AppFeedbackCategory::Other->value,
        'title' => 'Ujian ulangan',
        'description' => 'Ini ialah ujian penghantaran semula yang sama.',
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

it('rejects evidence files with dangerous extensions', function (string $fileName) {
    Notification::fake();

    $user = User::factory()->create();

    $data = [
        'submission_key' => (string) Str::uuid(),
        'category' => AppFeedbackCategory::Bug->value,
        'title' => 'Ujian fail berbahaya',
        'description' => 'Fail dengan sambungan berbahaya mesti ditolak.',
        'evidence' => [UploadedFile::fake()->image($fileName)->mimeType('image/jpeg')],
    ];

    expect(fn () => app(SubmitAppFeedbackAction::class)->handle($user, $data, request()))
        ->toThrow(ValidationException::class);
})->with(['malware.php', 'archive.zip', 'setup.exe']);

it('serves image evidence inline for preview', function () {
    Notification::fake();
    Storage::fake('app_feedback_private');

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->fillForm([
            'category' => AppFeedbackCategory::Bug->value,
            'title' => 'Ujian pratonton',
            'description' => 'Ini ialah ujian pratonton imej lampiran maklum balas.',
            'evidence' => [UploadedFile::fake()->image('preview.png')],
        ])
        ->call('submit')
        ->assertHasNoFormErrors();

    $feedback = AppFeedback::sole();
    $media = $feedback->getMedia('evidence')->first();

    $this->actingAs($user)
        ->get(route('app-feedback.evidence.download', [
            'feedback' => $feedback,
            'mediaId' => $media->getKey(),
            'preview' => 1,
        ]))
        ->assertSuccessful()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
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
