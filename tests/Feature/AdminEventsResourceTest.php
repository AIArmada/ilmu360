<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\RegistrationMode;
use App\Enums\TimingMode;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\AiUsageLog;
use App\Models\Event;
use App\Models\EventSettings;
use App\Models\Institution;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;
use Livewire\Livewire;

it('resolves pending transitionable states without moderation context', function () {
    $event = Event::factory()->create([
        'status' => 'pending',
        'event_type' => [EventType::Other->value],
        'timezone' => 'Asia/Kuala_Lumpur',
        'starts_at' => Carbon::parse('2026-05-13 20:00:00', 'Asia/Kuala_Lumpur')->utc(),
        'ends_at' => Carbon::parse('2026-05-13 22:00:00', 'Asia/Kuala_Lumpur')->utc(),
    ]);

    $states = $event->status->transitionableStates();

    expect($states)
        ->toContain('approved')
        ->not->toContain('needs_changes')
        ->not->toContain('rejected');
});

it('resolves moderation transitions when context is provided', function () {
    $this->seed(RoleSeeder::class);

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $event = Event::factory()->create([
        'status' => 'pending',
    ]);

    $states = $event->status->transitionableStates($moderator, 'incomplete_info');

    expect($states)
        ->toContain('approved')
        ->toContain('needs_changes')
        ->toContain('rejected');
});

it('renders admin events list and search with pending events', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create([
        'status' => 'pending',
        'title' => 'Admin Event Resource Search Coverage',
    ]);

    $this->actingAs($administrator)
        ->get('/admin/events')
        ->assertSuccessful()
        ->assertSee($event->title);

    $this->actingAs($administrator)
        ->get('/admin/events?search='.urlencode($event->title))
        ->assertSuccessful()
        ->assertSee($event->title);
});

it('generates an admin event cover from the events table and logs image cost', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create([
        'status' => 'pending',
        'event_type' => [EventType::Other->value],
        'timezone' => 'Asia/Kuala_Lumpur',
        'starts_at' => Carbon::parse('2026-05-13 20:00:00', 'Asia/Kuala_Lumpur')->utc(),
        'ends_at' => Carbon::parse('2026-05-13 22:00:00', 'Asia/Kuala_Lumpur')->utc(),
    ]);

    $imageFixture = fakeGeneratedImageUpload('ai-generated-cover.jpg', 1536, 1024);
    $contents = file_get_contents($imageFixture->getRealPath());
    expect($contents)->toBeString();

    Image::fake([
        base64_encode((string) $contents),
    ])->preventStrayImages();

    Livewire::actingAs($administrator)
        ->test(ListEvents::class)
        ->assertTableActionVisible('generate_cover_image', $event->id)
        ->assertTableBulkActionVisible('generate_cover_images')
        ->callTableAction('generate_cover_image', $event->id, data: [
            'creative_direction' => 'Use a restrained ilmu360 visual direction.',
            'include_existing_media' => false,
        ])
        ->assertHasNoTableActionErrors();

    Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->isLandscape()
        && $prompt->quality === 'low'
        && $prompt->model === 'gpt-image-2'
        && $prompt->contains('Use a restrained ilmu360 visual direction.'));

    $coverMedia = $event->fresh()->getFirstMedia('cover');
    expect($coverMedia)->not->toBeNull()
        ->and($coverMedia?->getCustomProperty('generation.model'))->toBe('gpt-image-2')
        ->and($coverMedia?->getCustomProperty('generation.quality'))->toBe('low');

    $usageLog = AiUsageLog::query()->where('operation', 'image_generation')->sole();

    expect($usageLog->provider)->toBe('openai')
        ->and($usageLog->model)->toBe('gpt-image-2')
        ->and($usageLog->cost_usd)->toBe('0.00500000')
        ->and($usageLog->meta)->toMatchArray([
            'requested_size' => '3:2',
            'requested_quality' => 'low',
            'images_count' => 1,
            'cost_source' => 'pricing_config_fallback',
        ]);
});

it('bulk generates admin event covers for selected events', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $events = Event::factory()
        ->count(2)
        ->sequence(
            ['title' => 'Bulk AI Cover Event One'],
            ['title' => 'Bulk AI Cover Event Two'],
        )
        ->create([
            'status' => 'pending',
            'event_type' => [EventType::Other->value],
            'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => Carbon::parse('2026-05-13 20:00:00', 'Asia/Kuala_Lumpur')->utc(),
            'ends_at' => Carbon::parse('2026-05-13 22:00:00', 'Asia/Kuala_Lumpur')->utc(),
        ]);

    $imageFixture = fakeGeneratedImageUpload('ai-generated-bulk-cover.jpg', 1536, 1024);
    $contents = file_get_contents($imageFixture->getRealPath());
    expect($contents)->toBeString();

    Image::fake([
        base64_encode((string) $contents),
        base64_encode((string) $contents),
    ])->preventStrayImages();

    Livewire::actingAs($administrator)
        ->test(ListEvents::class)
        ->callTableBulkAction('generate_cover_images', $events->pluck('id')->all(), data: [
            'creative_direction' => null,
            'include_existing_media' => false,
        ])
        ->assertHasNoTableBulkActionErrors();

    expect($events->map(fn (Event $event): bool => $event->fresh()->hasMedia('cover'))->all())
        ->toBe([true, true])
        ->and(AiUsageLog::query()->where('operation', 'image_generation')->count())->toBe(2);
});

it('shows typed event fields on the admin edit form', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create([
        'status' => 'pending',
        'event_url' => 'https://example.org/events/admin-typed-fields',
        'live_url' => 'https://youtube.com/watch?v=typed-admin-fields',
        'event_format' => EventFormat::Hybrid,
        'gender' => EventGenderRestriction::MenOnly,
        'age_group' => [EventAgeGroup::Adults],
        'children_allowed' => false,
    ]);

    $this->actingAs($administrator)
        ->get("/admin/events/{$event->id}/edit")
        ->assertSuccessful()
        ->assertSee('Maklumat Majlis')
        ->assertSee('Kategori & Bidang')
        ->assertSee('Penganjur & Lokasi')
        ->assertSee('Penceramah & Media')
        ->assertSee('Semak & Moderasi')
        ->assertSee('Bahasa')
        ->assertSee('Kategori')
        ->assertSee('Bidang Ilmu')
        ->assertSee('Sumber Utama')
        ->assertSee('Tema / Isu')
        ->assertSee('Pendaftaran Diperlukan');
});

it('persists registration-required settings when admins create events', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    Livewire::actingAs($administrator)
        ->test(CreateEvent::class)
        ->fillForm([
            'title' => 'Admin Registration Toggle Create Coverage',
            'event_date' => now()->addWeek()->toDateString(),
            'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
            'timezone' => 'Asia/Kuala_Lumpur',
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'event_type' => [EventType::Other->value],
            'institution_id' => $institution->id,
            'registration_required' => true,
            'registration_mode' => RegistrationMode::Event->value,
            'references' => [],
            'series' => [],
            'speakers' => [],
            'other_key_people' => [],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $event = Event::query()
        ->where('title', 'Admin Registration Toggle Create Coverage')
        ->firstOrFail();

    $settings = $event->fresh()?->settings;

    expect($settings)->not->toBeNull()
        ->and($settings?->registration_required)->toBeTrue()
        ->and($settings?->registration_mode)->toBe(RegistrationMode::Event);
});

it('does not require live url and allows admins to create hybrid events without it', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    Livewire::actingAs($administrator)
        ->test(CreateEvent::class)
        ->assertFormFieldExists('live_url', function (TextInput $input): bool {
            expect($input->isRequired())->toBeFalse();

            return true;
        })
        ->fillForm([
            'title' => 'Admin Hybrid Event Without Live URL',
            'event_date' => now()->addWeek()->toDateString(),
            'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
            'timezone' => 'Asia/Kuala_Lumpur',
            'event_format' => EventFormat::Hybrid->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'event_type' => [EventType::Other->value],
            'institution_id' => $institution->id,
            'references' => [],
            'series' => [],
            'speakers' => [],
            'other_key_people' => [],
        ])
        ->call('create')
        ->assertHasNoErrors();

    expect(Event::query()->where('title', 'Admin Hybrid Event Without Live URL')->value('live_url'))->toBeNull();
});

it('persists registration-required changes for existing admin events without registrations', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()
        ->has(EventSettings::factory()->state([
            'registration_required' => true,
            'registration_mode' => RegistrationMode::Event->value,
        ]), 'settings')
        ->create([
            'status' => 'pending',
            'event_type' => [EventType::Other->value],
            'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => Carbon::parse('2026-05-12 20:00:00', 'Asia/Kuala_Lumpur')->utc(),
            'ends_at' => Carbon::parse('2026-05-12 22:00:00', 'Asia/Kuala_Lumpur')->utc(),
        ]);

    Livewire::actingAs($administrator)
        ->test(EditEvent::class, ['record' => $event->id])
        ->assertFormFieldExists('registration_required')
        ->fillForm([
            'registration_required' => false,
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()->settings)->not->toBeNull()
        ->and($event->fresh()->settings?->registration_required)->toBeFalse();
});

it('uses fixed cover and poster ratios on the admin event form', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create([
        'status' => 'pending',
    ]);

    Livewire::actingAs($administrator)
        ->test(EditEvent::class, ['record' => $event->id])
        ->assertFormFieldExists('cover', function (FileUpload $upload): bool {
            $aspectRatioOptions = array_keys($upload->getImageEditorAspectRatioOptionsForJs());

            expect($upload->getImageAspectRatio())
                ->toBe('16:9')
                ->and($aspectRatioOptions)
                ->toContain('16:9')
                ->toHaveCount(2);

            return true;
        })
        ->assertFormFieldExists('poster', function (FileUpload $upload): bool {
            $aspectRatioOptions = array_keys($upload->getImageEditorAspectRatioOptionsForJs());

            expect($upload->getImageAspectRatio())
                ->toBe('4:5')
                ->and($aspectRatioOptions)
                ->toContain('4:5')
                ->toHaveCount(2);

            return true;
        });
});

it('accepts cover and poster uploads on the admin event edit form', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create([
        'status' => 'pending',
        'event_type' => [EventType::Other->value],
        'timezone' => 'Asia/Kuala_Lumpur',
        'starts_at' => Carbon::parse('2026-05-13 20:00:00', 'Asia/Kuala_Lumpur')->utc(),
        'ends_at' => Carbon::parse('2026-05-13 22:00:00', 'Asia/Kuala_Lumpur')->utc(),
    ]);

    Livewire::actingAs($administrator)
        ->test(EditEvent::class, ['record' => $event->id])
        ->fillForm([
            'event_date' => '2026-05-13',
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'custom_time' => '20:00',
            'end_time' => '22:00',
            'cover' => UploadedFile::fake()->image('cover-wide.jpg', 320, 180),
            'poster' => UploadedFile::fake()->image('poster-portrait.jpg', 320, 400),
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()->hasMedia('cover'))->toBeTrue()
        ->and($event->fresh()->hasMedia('poster'))->toBeTrue()
        ->and($event->fresh()->poster_display_aspect_ratio)->toBe('4:5');
});

it('renders the admin event view page with infolist tabs', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create([
        'status' => 'pending',
    ]);

    $this->actingAs($administrator)
        ->get("/admin/events/{$event->id}")
        ->assertSuccessful()
        ->assertSee('Maklumat Majlis')
        ->assertSee('Kategori & Bidang')
        ->assertSee('Penganjur & Lokasi')
        ->assertSee('Penceramah & Media')
        ->assertSee('Semak & Moderasi');
});

it('shows a duplicate event action on the admin event view page', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create([
        'status' => 'verified',
        'is_active' => true,
        'allow_public_event_submission' => true,
    ]);

    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => 'public',
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'institution_id' => $institution->id,
    ]);

    $component = Livewire::actingAs($administrator)
        ->test(ViewEvent::class, ['record' => $event->id])
        ->assertActionVisible('duplicate_event');

    $duplicateUrl = (fn (): string => $this->duplicateEventUrl())->call($component->instance());

    expect($duplicateUrl)->toBe(route('submit-event.create', ['duplicate' => $event]));

    $this->actingAs($administrator)
        ->get("/admin/events/{$event->id}")
        ->assertSuccessful()
        ->assertSee('Duplicate Event');
});

it('uses the institution-scoped duplicate route for admin viewers who are institution members', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create();
    $institution->members()->syncWithoutDetaching([$administrator->id]);

    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => 'public',
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
    ]);

    $component = Livewire::actingAs($administrator)
        ->test(ViewEvent::class, ['record' => $event->id]);

    $duplicateUrl = (fn (): string => $this->duplicateEventUrl())->call($component->instance());

    expect($duplicateUrl)->toBe(route('dashboard.institutions.submit-event', [
        'institution' => $institution->id,
        'duplicate' => $event,
    ]));

    $this->actingAs($administrator)
        ->get("/admin/events/{$event->id}")
        ->assertSuccessful()
        ->assertSee('Duplicate Event');
});

it('falls back to the generic duplicate route for admin viewers who only belong to an inactive institution', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create([
        'status' => 'verified',
        'is_active' => false,
        'allow_public_event_submission' => false,
    ]);
    $institution->members()->syncWithoutDetaching([$administrator->id]);

    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => 'public',
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'institution_id' => $institution->id,
    ]);

    $component = Livewire::actingAs($administrator)
        ->test(ViewEvent::class, ['record' => $event->id]);

    $duplicateUrl = (fn (): string => $this->duplicateEventUrl())->call($component->instance());

    expect($duplicateUrl)->toBe(route('submit-event.create', ['duplicate' => $event]));
});

it('sanitizes description and uses full-width layout on admin event view page', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create([
        'status' => 'pending',
        'description' => '<p>Ini adalah <strong>forum</strong> sekolah</p>',
    ]);

    $this->actingAs($administrator)
        ->get("/admin/events/{$event->id}")
        ->assertSuccessful()
        ->assertSee('Ini adalah forum sekolah')
        ->assertDontSee('&lt;p&gt;Ini adalah &lt;strong&gt;forum&lt;/strong&gt; sekolah&lt;/p&gt;', false)
        ->assertSee('fi-width-full', false);
});

it('shows date-only start time for prayer-relative events in the admin view', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $prayerRelativeEvent = Event::factory()->create([
        'status' => 'pending',
        'starts_at' => '2026-02-19 03:33:00',
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_reference' => null,
        'prayer_offset' => null,
        'prayer_display_text' => 'Selepas Maghrib',
    ]);

    $absoluteEvent = Event::factory()->create([
        'status' => 'pending',
        'starts_at' => '2026-02-20 09:41:00',
        'timing_mode' => TimingMode::Absolute,
        'prayer_reference' => null,
        'prayer_offset' => null,
        'prayer_display_text' => null,
    ]);

    $prayerRelativeDate = $prayerRelativeEvent->starts_at?->format('M d, Y');
    $prayerRelativeDateTime = $prayerRelativeEvent->starts_at?->format('M d, Y H:i:s');
    $absoluteDateTime = $absoluteEvent->starts_at?->format('M d, Y H:i:s');

    $this->actingAs($administrator)
        ->get("/admin/events/{$prayerRelativeEvent->id}")
        ->assertSuccessful()
        ->assertSee($prayerRelativeDate)
        ->assertDontSee($prayerRelativeDateTime);

    $absoluteResponse = $this->actingAs($administrator)
        ->get("/admin/events/{$absoluteEvent->id}")
        ->assertSuccessful();

    $absoluteResponseContent = $absoluteResponse->getContent();
    $possibleAbsoluteDateTimes = [
        $absoluteDateTime,
        $absoluteEvent->starts_at?->copy()->timezone('Asia/Kuala_Lumpur')->format('M d, Y H:i:s'),
        $absoluteEvent->starts_at?->format('M d, Y g:i:s A'),
        $absoluteEvent->starts_at?->copy()->timezone('Asia/Kuala_Lumpur')->format('M d, Y g:i:s A'),
    ];

    expect(collect($possibleAbsoluteDateTimes)
        ->filter()
        ->contains(fn (string $value): bool => str_contains((string) $absoluteResponseContent, $value)))->toBeTrue();
});
