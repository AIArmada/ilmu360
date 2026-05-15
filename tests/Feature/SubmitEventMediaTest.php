<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    fakePrayerTimesApi();
});

/**
 * @return array{event_date: string, domain_tag_ids: array<int, string>, discipline_tag_ids: array<int, string>, speaker_ids: array<int, string>, institution_id: string}
 */
function submitEventMediaFixtures(): array
{
    return [
        'event_date' => now()->addDay()->toDateString(),
        'domain_tag_ids' => Tag::factory()->domain()->count(2)->create()->pluck('id')->all(),
        'discipline_tag_ids' => Tag::factory()->discipline()->count(1)->create()->pluck('id')->all(),
        'speaker_ids' => Speaker::factory()->count(2)->create()->pluck('id')->all(),
        'institution_id' => Institution::factory()->create(['status' => 'verified'])->id,
    ];
}

/**
 * @param  array{event_date: string, domain_tag_ids: array<int, string>, discipline_tag_ids: array<int, string>, speaker_ids: array<int, string>, institution_id: string}  $fixtures
 * @return array<string, mixed>
 */
function submitEventMediaFormData(array $fixtures, array $overrides = []): array
{
    return array_merge([
        'title' => 'Test Event Media Upload',
        'description' => 'Event description',
        'event_date' => $fixtures['event_date'],
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'event_type' => [EventType::KuliahCeramah->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [101],
        'children_allowed' => true,
        'domain_tags' => $fixtures['domain_tag_ids'],
        'discipline_tags' => $fixtures['discipline_tag_ids'],
        'speakers' => $fixtures['speaker_ids'],
        'organizer_type' => 'institution',
        'organizer_institution_id' => $fixtures['institution_id'],
        'submitter_name' => 'Guest User',
        'submitter_email' => 'guest@example.com',
        'visibility' => EventVisibility::Public->value,
    ], $overrides);
}

function submitEventMediaUpload(string $targetName, int $width = 1600, int $height = 900): UploadedFile
{
    return UploadedFile::fake()->image($targetName, $width, $height);
}

it('stores cover, poster, and gallery uploads when submitting an event', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $fixtures = submitEventMediaFixtures();

    $component = setSubmitEventFormState(
        Livewire::test('pages.submit-event.create'),
        submitEventMediaFormData($fixtures),
    );

    $component
        ->fillForm([
            'cover' => submitEventMediaUpload('cover.png'),
            'poster' => submitEventMediaUpload('poster.png', 1200, 1500),
            'gallery' => [
                submitEventMediaUpload('gallery-1.png'),
                submitEventMediaUpload('gallery-2.png'),
            ],
        ])
        ->call('submit')
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Test Event Media Upload')->firstOrFail();
    $expectedSuffix = Carbon::parse($fixtures['event_date'], 'Asia/Kuala_Lumpur')->format('j-n-y');
    $speakerSlugSegments = collect($fixtures['speaker_ids'])
        ->map(fn (string $speakerId): string => Speaker::query()->findOrFail($speakerId)->slug)
        ->all();

    expect($event->getMedia('cover'))->toHaveCount(1);
    expect($event->getMedia('poster'))->toHaveCount(1);
    expect($event->getMedia('gallery'))->toHaveCount(2);
    expect($event->tags)->toHaveCount(3)
        ->and($event->slug)->toBe(sprintf(
            'test-event-media-upload-%s-%s',
            implode('-', $speakerSlugSegments),
            $expectedSuffix,
        ))
        ->and($event->poster_display_aspect_ratio)->toBe('4:5');
});

it('uses fixed cover and poster ratios on the public submit-event form', function () {
    Livewire::test('pages.submit-event.create')
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

it('does not require guest details for authenticated users', function () {
    $user = User::factory()->create();

    $fixtures = submitEventMediaFixtures();

    setSubmitEventFormState(
        Livewire::actingAs($user)->test('pages.submit-event.create'),
        submitEventMediaFormData($fixtures, [
            'title' => 'Logged In Event',
            'submitter_name' => null,
            'submitter_email' => null,
        ]),
    )
        ->call('submit')
        ->assertRedirect(route('submit-event.success'));

    expect(Event::where('title', 'Logged In Event')->exists())->toBeTrue();
});
