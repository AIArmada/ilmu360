<?php

use AIArmada\Events\Actions\CreateEventOccurrenceAction;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Actions\Events\SyncEventClassificationsAction;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Services\EventKeyPersonSyncService;
use App\Support\Submission\SubmitEventPrefill;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Database\Seeders\AIArmada\EventTaxonomySeeder;
use Database\Seeders\AIArmada\EventTopicSeeder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(EventRoleSeeder::class);
});

function adaptiveSubmitEventTopicId(string $code): string
{
    $taxonomyId = EventTaxonomy::query()->where('code', 'domain')->value('id');

    return (string) EventTerm::query()
        ->where('event_taxonomy_id', $taxonomyId)
        ->where('code', $code)
        ->value('id');
}

/**
 * @return array{event: Event, public_speaker: Person, private_speaker: Person}
 */
function adaptivePublicDuplicatePeopleFixtures(): array
{
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
    ]);
    $publicSpeaker = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    $privateSpeaker = Person::factory()->create(['status' => 'verified', 'allow_public_event_submission' => true]);
    app(EventKeyPersonSyncService::class)->sync($event, [$publicSpeaker->getKey(), $privateSpeaker->getKey()], [
        ['role_code' => EventKeyPersonRole::Moderator->value, 'display_name' => 'Visible Moderator', 'visibility' => 'public'],
        ['role_code' => EventKeyPersonRole::PersonInCharge->value, 'display_name' => 'Private Contact', 'visibility' => 'private', 'notes' => 'Private organizer notes'],
    ]);
    $event->keyPeople()->where('involveable_id', $privateSpeaker->getKey())->update(['visibility' => 'private']);

    return ['event' => $event->fresh(), 'public_speaker' => $publicSpeaker, 'private_speaker' => $privateSpeaker];
}

it('does not expose private people when publicly duplicating another event', function (?User $actor): void {
    $fixtures = adaptivePublicDuplicatePeopleFixtures();
    $component = Livewire::withQueryParams(['duplicate' => $fixtures['event']->getKey()]);

    if ($actor instanceof User) {
        $component = $component->actingAs($actor);
    }

    $component = $component->test(Create::class);

    $component->assertSet('data.persons', [$fixtures['public_speaker']->getKey()]);
    expect($component->get('data.other_key_people'))
        ->toHaveCount(1)
        ->and(array_values($component->get('data.other_key_people'))[0]['display_name'])->toBe('Visible Moderator');
    expect($component->html())->not->toContain('Private Contact', 'Private organizer notes');
})->with([
    'guest' => [null],
    'signed-in nonowner' => [fn (): User => User::factory()->create()],
]);

it('retains private people for a duplicate owner and authorized session defaults', function (): void {
    $fixtures = adaptivePublicDuplicatePeopleFixtures();
    $owner = User::factory()->create();
    EventSubmission::factory()->for($fixtures['event'])->for($owner, 'submitter')->create();

    $component = Livewire::actingAs($owner)
        ->withQueryParams(['duplicate' => $fixtures['event']->getKey()])
        ->test(Create::class);
    $containerDefaults = SubmitEventPrefill::containerDefaults($fixtures['event'], $owner, (string) ensureTestMalaysiaCountry()->getKey());

    $component->assertSet('data.persons', [$fixtures['public_speaker']->getKey(), $fixtures['private_speaker']->getKey()]);
    expect($component->get('data.other_key_people'))->toHaveCount(2);
    expect($containerDefaults['persons'])->toBe([$fixtures['public_speaker']->getKey(), $fixtures['private_speaker']->getKey()]);
    expect($containerDefaults['other_key_people'])->toHaveCount(2);
});

it('defaults the driver selections and starts with sensible downstream values', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $component = Livewire::test(Create::class);

    $component->assertFormFieldExists('event_category_ids', function (Select $field): bool {
        expect($field->isRequired())->toBeTrue();

        return true;
    });

    $component->assertFormFieldExists('domain_tags', function (Select $field): bool {
        expect($field->isRequired())->toBeTrue();

        return true;
    });

    expect($component->instance()->data)
        ->toMatchArray([
            'event_category_ids' => eventCategoryId('kuliah_ceramah'),
            'domain_tags' => adaptiveSubmitEventTopicId('agama-kerohanian'),
            'event_format' => EventFormat::Physical->value,
            'visibility' => 'public',
            'gender' => 'all',
            'children_allowed' => true,
            'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
            'custom_time' => null,
        ]);

    Livewire::test(Create::class)
        ->call('submit')
        ->assertHasErrors(['data.title', 'data.event_date']);
});

it('uses the agama kerohanian topic for religion-specific audience questions', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $component = Livewire::test(Create::class)
        ->set('data.event_category_ids', [eventCategoryId('kelas_kursus')])
        ->set('data.domain_tags', adaptiveSubmitEventTopicId('agama-kerohanian'));

    $component
        ->assertSee('Terbuka untuk Muslim Sahaja')
        ->assertFormFieldVisible('is_muslim_only')
        ->assertSet('data.is_muslim_only', false)
        ->assertSet('data.prayer_time', EventPrayerTime::SelepasMaghrib->value)
        ->assertSet('data.custom_time', null);
});

it('keeps waktu available for every event and does not classify by category alone', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $component = Livewire::test(Create::class)
        ->set('data.event_category_ids', [eventCategoryId('aktiviti_keagamaan')])
        ->set('data.is_muslim_only', true)
        ->set('data.domain_tags', adaptiveSubmitEventTopicId('pendidikan'))
        ->set('data.prayer_time', EventPrayerTime::LainWaktu->value);

    $component
        ->assertFormFieldVisible('is_muslim_only')
        ->assertFormFieldVisible('prayer_time')
        ->assertFormFieldVisible('custom_time')
        ->assertSet('data.is_muslim_only', true)
        ->assertSet('data.prayer_time', EventPrayerTime::LainWaktu->value)
        ->set('data.custom_time', '20:00')
        ->assertSet('data.custom_time', '20:00');
});

it('shows discipline and issue fields for every domain but source and references only for the religious topic', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $component = Livewire::test(Create::class);

    $component
        ->assertSee('Topik lebih khusus')
        ->assertFormFieldVisible('discipline_tags')
        ->assertFormFieldVisible('issue_tags')
        ->assertFormFieldVisible('source_tags')
        ->assertFormFieldVisible('references')
        ->set('data.event_category_ids', [eventCategoryId('aktiviti_keagamaan')])
        ->set('data.domain_tags', adaptiveSubmitEventTopicId('pendidikan'))
        ->assertSee('Topik lebih khusus')
        ->assertFormFieldVisible('discipline_tags')
        ->assertFormFieldVisible('issue_tags')
        ->assertFormFieldHidden('source_tags')
        ->assertFormFieldHidden('references')
        ->set('data.domain_tags', adaptiveSubmitEventTopicId('agama-kerohanian'))
        ->assertSee('Topik lebih khusus')
        ->assertFormFieldVisible('discipline_tags')
        ->assertFormFieldVisible('issue_tags')
        ->assertFormFieldVisible('source_tags')
        ->assertFormFieldVisible('references');
});

it('hydrates single-select taxonomy defaults when duplicating an event', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $sourceEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public->value,
        'published_at' => now(),
        'starts_at' => now()->addDays(3),
    ]);
    $categoryId = eventCategoryId('kuliah_ceramah');
    $topicId = adaptiveSubmitEventTopicId('agama-kerohanian');

    app(SyncEventClassificationsAction::class)->handle($sourceEvent, [
        'event_category_ids' => [$categoryId],
        'domain_tags' => [$topicId],
    ]);

    $component = Livewire::withQueryParams(['duplicate' => $sourceEvent->getKey()])
        ->test(Create::class);

    expect($component->get('data.event_category_ids'))->toBe($categoryId)
        ->and($component->get('data.domain_tags'))->toBe($topicId);
});

it('keeps a private visibility when duplicating an event', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $user = User::factory()->create();
    $sourceEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Private->value,
        'published_at' => now(),
        'starts_at' => now()->addDays(3),
    ]);
    EventSubmission::factory()->for($sourceEvent)->for($user, 'submitter')->create();

    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);
    $topicId = adaptiveSubmitEventTopicId('agama-kerohanian');

    $component = Livewire::withQueryParams(['duplicate' => $sourceEvent->getKey()])
        ->actingAs($user)
        ->test(Create::class);

    setSubmitEventFormState($component, [
        'title' => 'Private duplicate should stay private',
        'domain_tags' => [$topicId],
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_date' => now()->addDays(5)->toDateString(),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'end_time' => '22:00',
        'description' => 'Private duplicate test',
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [languageId('ms')],
        'primary_organizer_id' => $institution->id,
        'persons' => [$person->id],
        'submitter_name' => $user->name,
        'submitter_email' => $user->email,
    ])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    expect(Event::query()->where('title', 'Private duplicate should stay private')->firstOrFail()->visibility)
        ->toBe(EventVisibility::Private);
});

it('preserves a user-entered custom time when the context becomes religious', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $component = Livewire::test(Create::class)
        ->set('data.event_category_ids', [eventCategoryId('kelas_kursus')])
        ->set('data.domain_tags', adaptiveSubmitEventTopicId('pendidikan'))
        ->set('data.prayer_time', EventPrayerTime::LainWaktu->value)
        ->set('data.custom_time', '20:00')
        ->set('data.event_category_ids', [eventCategoryId('aktiviti_keagamaan')])
        ->set('data.domain_tags', adaptiveSubmitEventTopicId('agama-kerohanian'));

    $component
        ->assertSet('data.prayer_time', EventPrayerTime::LainWaktu->value)
        ->assertSet('data.custom_time', '20:00');
});

it('renders a completion progress indicator that counts valid defaults', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $component = Livewire::test(Create::class);

    expect($component->instance()->formProgress())->toBeGreaterThan(0)
        ->toBeLessThan(100);

    $component->assertSee('data-submit-event-progress="', false);
});

it('tracks independent progress fields in the browser without live bindings', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $html = Livewire::test(Create::class)->html();

    expect($html)
        ->toContain('wire:ignore')
        ->toContain("window.addEventListener('submit-event-progress-updated'")
        ->toContain('const topicIds = this.selectedIds(currentState.domain_tags);')
        ->toContain('$wire.watch')
        ->not->toContain("window.addEventListener('change'")
        ->not->toContain("window.addEventListener('input'")
        ->not->toContain('wire:model.live="data.event_format"')
        ->not->toContain('wire:model.live="data.visibility"')
        ->not->toContain('wire:model.live="data.gender"')
        ->not->toContain('wire:model.live="data.languages"')
        ->not->toContain('wire:model.live="data.persons"')
        ->not->toContain('religious_category_ids')
        ->not->toContain('religious_topic_ids');
});

it('updates the progress indicator when required fields become complete', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $component = Livewire::test(Create::class);
    $initialProgress = $component->instance()->formProgress();

    $component
        ->set('data.title', 'Progress test')
        ->set('data.event_date', now()->addDays(7)->format('Y-m-d'));

    $updatedProgress = $component->instance()->formProgress();

    expect($initialProgress)->toBeGreaterThan(0)
        ->and($initialProgress)->toBeLessThan(100)
        ->and($updatedProgress)->toBeGreaterThan($initialProgress)
        ->and($updatedProgress)->toBeLessThan(100);

    $component->assertSee("data-submit-event-progress=\"{$updatedProgress}\"", false);
});

it('adds custom time to the required progress fields only when selected', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $component = Livewire::test(Create::class)
        ->set('data.event_category_ids', eventCategoryId('aktiviti_keagamaan'))
        ->set('data.domain_tags', adaptiveSubmitEventTopicId('agama-kerohanian'))
        ->set('data.prayer_time', EventPrayerTime::SelepasMaghrib->value)
        ->set('data.custom_time', null);

    $prayerDefaultProgress = $component->instance()->formProgress();

    $component->set('data.prayer_time', EventPrayerTime::LainWaktu->value);
    $customTimeRequiredProgress = $component->instance()->formProgress();

    expect($customTimeRequiredProgress)->toBeLessThan($prayerDefaultProgress);

    $component->set('data.custom_time', '18:00');

    expect($component->instance()->formProgress())->toBeGreaterThan($customTimeRequiredProgress);
});

it('removes physical location requirements when an event becomes online', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $fakePersonId = str()->uuid()->toString();
    $component = Livewire::test(Create::class);

    setSubmitEventFormState($component, [
        'event_category_ids' => [eventCategoryId('kelas_kursus')],
        'domain_tags' => [adaptiveSubmitEventTopicId('pendidikan')],
        'title' => 'Online progress test',
        'event_date' => now()->addDays(7)->format('Y-m-d'),
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [languageId('ms')],
        'custom_time' => '20:00',
        'primary_organizer_kind' => 'person',
        'primary_organizer_id' => $fakePersonId,
        'primary_organizer_person_id' => $fakePersonId,
        'persons' => [$fakePersonId],
        'location_type' => null,
        'submitter_name' => 'Test Submitter',
        'submitter_email' => 'submitter@example.com',
    ]);

    $physicalProgress = $component->instance()->formProgress();

    $component->set('data.event_format', EventFormat::Online->value);

    expect($physicalProgress)->toBeLessThan(100)
        ->and($component->instance()->formProgress())->toBe(100);
});

it('counts a category-specific speaker requirement after the category is selected', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $component = Livewire::test(Create::class)
        ->set('data.event_category_ids', [eventCategoryId('kelas_kursus')])
        ->set('data.domain_tags', [adaptiveSubmitEventTopicId('pendidikan')]);

    $withoutSpeakerProgress = $component->instance()->formProgress();

    $component->set('data.persons', [str()->uuid()->toString()]);

    expect($component->instance()->formProgress())->toBeGreaterThan($withoutSpeakerProgress);
});

it('normalizes quick-added titles on the server before calculating progress', function (): void {
    $component = Livewire::test(Create::class)
        ->set('data.title', '__quick_add__Chrome progress test');

    $component->assertSet('data.title', 'Chrome progress test');
});

it('escapes quick-added and saved titles in suggestion labels', function (): void {
    $title = '<img src=x onerror=alert(1)> Event';
    Event::factory()->create([
        'title' => $title,
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
    ]);

    Livewire::test(Create::class)
        ->assertFormFieldExists('title', function (Select $field) use ($title): bool {
            $savedResults = $field->getSearchResults('Event');
            $quickResults = $field->getSearchResults('<svg onload=alert(2)>');

            expect($savedResults[$title])->toContain('&lt;img')->not->toContain('<img');
            expect(implode('', $quickResults))->toContain('&lt;svg')->not->toContain('<svg');

            return true;
        });
});

it('escapes new taxonomy labels in quick-add results and selected values', function (string $fieldName): void {
    $label = '<img src=x onerror=alert(3)>';

    Livewire::test(Create::class)
        ->set('data.'.$fieldName, [$label])
        ->assertFormFieldExists($fieldName, function (Select $field) use ($label): bool {
            expect(implode('', $field->getSearchResults($label)))
                ->toContain('&lt;img')->not->toContain('<img');
            expect($field->getOptionLabels()[$label])
                ->toContain('&lt;img')->not->toContain('<img');

            return true;
        });
})->with(['discipline_tags', 'issue_tags']);

it('only suggests published public approved event titles', function (): void {
    $public = Event::factory()->create([
        'title' => 'Visible Suggestion',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
    ]);
    $hidden = Event::factory()->count(3)->sequence(
        ['title' => 'Private Suggestion', 'visibility' => EventVisibility::Private, 'published_at' => now()],
        ['title' => 'Unlisted Suggestion', 'visibility' => EventVisibility::Unlisted, 'published_at' => now()],
        ['title' => 'Unpublished Suggestion', 'visibility' => EventVisibility::Public, 'published_at' => null],
    )->create(['status' => 'approved']);
    $hidden->firstWhere('title', 'Unpublished Suggestion')->forceFill(['published_at' => null])->save();

    Livewire::test(Create::class)
        ->assertFormFieldExists('title', function (Select $field) use ($public, $hidden): bool {
            $results = $field->getSearchResults('Suggestion');

            expect($results)->toHaveKey($public->title);

            foreach ($hidden as $event) {
                expect($results)->not->toHaveKey($event->title);
            }

            return true;
        });
});

it('does not resolve labels for speaker profiles the submitter cannot use', function (): void {
    $privatePerson = Person::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => false,
    ]);

    Livewire::test(Create::class)
        ->set('data.persons', [$privatePerson->getKey()])
        ->assertFormFieldExists('persons', function (Select $field): bool {
            expect($field->getOptionLabels())->toBe([]);

            return true;
        });
});

it('prefills canonical reference ids when a published event title is selected', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();
    $reference = Reference::factory()->create();
    $event = Event::factory()->create([
        'title' => 'Reference Prefill Event',
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
    ]);
    app(SyncEventClassificationsAction::class)->handle($event, [
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'domain_tags' => [adaptiveSubmitEventTopicId('agama-kerohanian')],
    ]);
    $event->references()->attach($reference->getKey());

    Livewire::test(Create::class)
        ->set('data.title', $event->title)
        ->assertSet('data.references', [$reference->getKey()]);
});

it('updates the editable session schedule when another occurrence is selected', function (): void {
    $owner = User::factory()->create();
    $event = Event::factory()->create([
        'created_by_type' => $owner->getMorphClass(),
        'created_by_id' => $owner->getKey(),
        'status' => 'draft',
        'starts_at' => now()->addDays(4),
    ]);
    $startsAt = now()->addDays(8)->startOfDay()->setTime(12, 30)->utc();
    $occurrence = app(CreateEventOccurrenceAction::class)->handle($event, [
        'title' => 'Second Day',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHours(2),
        'timezone' => 'UTC',
    ]);

    Livewire::actingAs($owner)
        ->withQueryParams(['event' => $event->getKey()])
        ->test(Create::class)
        ->set('data.event_occurrence_id', $occurrence->getKey())
        ->assertSet('data.event_date', $startsAt->copy()->timezone('Asia/Kuala_Lumpur')->toDateString())
        ->assertSet('data.prayer_time', EventPrayerTime::LainWaktu->value)
        ->assertSet('data.custom_time', $startsAt->copy()->timezone('Asia/Kuala_Lumpur')->format('H:i'))
        ->assertSet('data.end_time', $startsAt->copy()->addHours(2)->timezone('Asia/Kuala_Lumpur')->format('H:i'));
});

it('rejects malformed submission context query parameters with a not-found response', function (string $key, mixed $value): void {
    $this->get(route('submit-event.create', [$key => $value]))->assertNotFound();
})->with([
    'array event' => ['event', ['bad']],
    'invalid event' => ['event', 'not-a-uuid'],
    'array duplicate' => ['duplicate', ['bad']],
    'invalid duplicate' => ['duplicate', 'not-a-uuid'],
    'array institution' => ['institution', ['bad']],
    'invalid institution' => ['institution', 'not-a-uuid'],
]);

it('groups the regrouped wizard into labeled steps and sections', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    Livewire::test(Create::class)
        ->assertSee('Majlis & Topik')
        ->assertSee('Tarikh, Masa & Kehadiran')
        ->assertSee('Format, Penganjur & Lokasi')
        ->assertSee('Tarikh & Masa')
        ->assertSee('Format & Pautan')
        ->assertSee('Kehadiran');
});

it('renders the Majlis & Topik step without section containers', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $wizard = Livewire::test(Create::class)->instance()->getForm('form')->getComponents()[0];

    expect($wizard)->toBeInstanceOf(Wizard::class);

    $firstStep = $wizard->getChildComponents()[0];

    expect($firstStep)->toBeInstanceOf(Step::class);

    $sections = collect($firstStep->getChildComponents())
        ->filter(fn (object $component): bool => $component instanceof Section);

    expect($sections)->toBeEmpty();
});

it('persists the muslim-only choice for non-religious topics', function (): void {
    fakePrayerTimesApi();
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);

    setSubmitEventFormState(
        Livewire::test(Create::class),
        [
            'title' => 'Muslim Only Non Religious Event',
            'domain_tags' => [adaptiveSubmitEventTopicId('pendidikan')],
            'event_category_ids' => [eventCategoryId('kelas_kursus')],
            'event_date' => now()->addDays(5)->toDateString(),
            'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
            'description' => 'Location sensitive community class.',
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'languages' => [languageId('ms')],
            'primary_organizer_id' => $institution->id,
            'persons' => [$person->id],
            'is_muslim_only' => true,
            'submitter_name' => 'Test User',
            'submitter_email' => 'test@example.com',
        ],
    )
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    $event = Event::where('title', 'Muslim Only Non Religious Event')->firstOrFail();

    expect((bool) $event->is_muslim_only)->toBeTrue();
});
