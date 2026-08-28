<?php

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Actions\Events\SyncEventClassificationsAction;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\AIArmada\EventTaxonomySeeder;
use Database\Seeders\AIArmada\EventTopicSeeder;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

function adaptiveSubmitEventTopicId(string $code): string
{
    $taxonomyId = EventTaxonomy::query()->where('code', 'domain')->value('id');

    return (string) EventTerm::query()
        ->where('event_taxonomy_id', $taxonomyId)
        ->where('code', $code)
        ->value('id');
}

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
        ->assertFormFieldHidden('is_muslim_only')
        ->assertFormFieldVisible('prayer_time')
        ->assertFormFieldVisible('custom_time')
        ->assertSet('data.is_muslim_only', false)
        ->assertSet('data.prayer_time', EventPrayerTime::LainWaktu->value)
        ->set('data.custom_time', '20:00')
        ->assertSet('data.custom_time', '20:00');
});

it('shows the topic and reference step only for the religious topic', function (): void {
    app(EventTaxonomySeeder::class)->run();
    app(EventTopicSeeder::class)->run();

    $stepKey = 'topik-rujukan::data::wizard-step';
    $component = Livewire::test(Create::class);

    $component
        ->assertSchemaComponentVisible($stepKey)
        ->set('data.event_category_ids', [eventCategoryId('aktiviti_keagamaan')])
        ->set('data.domain_tags', adaptiveSubmitEventTopicId('pendidikan'))
        ->assertSchemaComponentHidden($stepKey)
        ->set('data.domain_tags', adaptiveSubmitEventTopicId('agama-kerohanian'))
        ->assertSchemaComponentVisible($stepKey);
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
