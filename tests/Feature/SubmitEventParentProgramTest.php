<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventStructure;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Filament\Ahli\Resources\Events\EventResource as AhliEventResource;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    fakePrayerTimesApi();

    $this->domainTag = Tag::factory()->domain()->create();
    $this->disciplineTag = Tag::factory()->discipline()->create();
});

/**
 * @return array<string, mixed>
 */
function childEventSubmissionPayload(Tag $domainTag, Tag $disciplineTag, Speaker $speaker, array $overrides = []): array
{
    return array_merge([
        'title' => 'Attached Child Event',
        'description' => 'Child event submitted through the standard form.',
        'event_date' => now()->addDays(5)->toDateString(),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'event_type' => [EventType::KuliahCeramah->value],
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [101],
        'domain_tags' => [$domainTag->id],
        'discipline_tags' => [$disciplineTag->id],
        'speakers' => [$speaker->id],
        'submitter_name' => 'Attached Submitter',
        'submitter_email' => 'attached@example.test',
    ], $overrides);
}

it('prefills parent program organizer context on the submit-event page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Induk']);

    $institution->members()->syncWithoutDetaching([$user->id]);

    $parentProgram = Event::factory()->for($institution)->create([
        'title' => 'Induk Ramadan',
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'institution_id' => $institution->id,
        'event_structure' => EventStructure::ParentProgram->value,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'draft',
    ]);

    $component = Livewire::withQueryParams(['parent' => $parentProgram->id])
        ->actingAs($user)
        ->test('pages.submit-event.create');

    expect($component->get('data.organizer_type'))->toBe('institution')
        ->and($component->get('data.organizer_institution_id'))->toBe($institution->id)
        ->and($component->get('data.location_institution_id'))->toBe($institution->id)
        ->and($component->get('data.visibility'))->toBe(EventVisibility::Public->value);

    $this->actingAs($user)
        ->get(route('submit-event.create', ['parent' => $parentProgram->id]))
        ->assertOk()
        ->assertSee('Back to Parent Program')
        ->assertSee($parentProgram->title)
        ->assertSee(AhliEventResource::getUrl('view', ['record' => $parentProgram], panel: 'ahli'), false);
});

it('attaches submitted child events to the selected parent program', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Induk']);
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'is_active' => true,
        'allow_public_event_submission' => true,
    ]);

    $institution->members()->syncWithoutDetaching([$user->id]);

    $parentProgram = Event::factory()->for($institution)->create([
        'title' => 'Induk Ramadan',
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'institution_id' => $institution->id,
        'event_structure' => EventStructure::ParentProgram->value,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'draft',
    ]);

    $parentProgram->settings()->create([
        'registration_required' => true,
        'registration_mode' => 'event',
    ]);

    setSubmitEventFormState(
        Livewire::withQueryParams(['parent' => $parentProgram->id])
            ->actingAs($user)
            ->test('pages.submit-event.create'),
        childEventSubmissionPayload($this->domainTag, $this->disciplineTag, $speaker)
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $childEvent = Event::query()->where('title', 'Attached Child Event')->first();

    expect($childEvent)->not->toBeNull()
        ->and($childEvent?->parent_event_id)->toBe($parentProgram->id)
        ->and($childEvent?->isChildEvent())->toBeTrue()
        ->and($childEvent?->settings?->registration_required)->toBeTrue()
        ->and($childEvent?->settings?->registration_mode?->value)->toBe('event');

    $this->withSession([
        'parent_event_id' => $parentProgram->id,
        'parent_event_title' => $parentProgram->title,
    ])
        ->actingAs($user)
        ->get(route('submit-event.success'))
        ->assertOk()
        ->assertSee('Back to Parent Program')
        ->assertSee(AhliEventResource::getUrl('view', ['record' => $parentProgram], panel: 'ahli'), false);
});
