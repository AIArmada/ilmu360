<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Services\Ai\EventMediaExtractionService;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    $this->seed(EventRoleSeeder::class);
});

it('extracts media data with AI and moves the wizard to review step', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
    $institution = Institution::factory()->create(['status' => 'verified']);
    $person = Person::factory()->create(['status' => 'verified']);
    $domainTag = submitEventTerm('domain');
    $sourceTag = submitEventTerm('source');
    $disciplineTag = submitEventTerm('discipline');
    $issueTag = submitEventTerm('issue');

    $this->mock(EventMediaExtractionService::class, function (MockInterface $mock) use ($domainTag, $sourceTag, $disciplineTag, $issueTag): void {
        $mock->shouldReceive('extract')
            ->once()
            ->andReturn([
                'title' => 'Daurah Fiqh Keluarga',
                'description' => 'Kupasan berkaitan fiqh keluarga semasa.',
                'event_date' => now()->addDays(7)->toDateString(),
                'prayer_time' => EventPrayerTime::LainWaktu->value,
                'custom_time' => '20:30',
                'end_time' => '22:00',
                'event_category_ids' => [eventCategoryId('kelas_kursus')],
                'event_format' => EventFormat::Physical->value,
                'visibility' => EventVisibility::Public->value,
                'gender' => EventGenderRestriction::All->value,
                'age_group' => [EventAgeGroup::Adults->value],
                'children_allowed' => false,
                'is_muslim_only' => true,
                'languages' => [languageId('ms'), languageId('en')],
                'domain_tags' => [$domainTag->id],
                'source_tags' => [$sourceTag->id],
                'discipline_tags' => [(string) $disciplineTag->id],
                'issue_tags' => [(string) $issueTag->id],
            ]);
    });

    $component = setSubmitEventFormState(Livewire::test(Create::class), [
        'primary_organizer_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'submitter_name' => 'AI Submitter',
        'submitter_email' => 'ai-submitter@example.com',
    ])
        ->set('event_source_attachment', UploadedFile::fake()->image('poster.jpg', 1200, 1600))
        ->call('extractEventFromMedia')
        ->assertHasNoErrors(['event_source_attachment'])
        ->assertSet('data.title', 'Daurah Fiqh Keluarga')
        ->assertSet('data.prayer_time', EventPrayerTime::LainWaktu->value)
        ->assertSet('data.custom_time', '20:30')
        ->assertSet('data.event_category_ids', eventCategoryId('kelas_kursus'))
        ->assertSet('data.event_format', EventFormat::Physical->value)
        ->assertSet('data.visibility', EventVisibility::Public->value)
        ->assertSet('data.gender', EventGenderRestriction::All->value)
        ->assertSet('data.age_group', [EventAgeGroup::Adults->value])
        ->assertSet('data.children_allowed', false)
        ->assertSet('data.domain_tags', $domainTag->id)
        ->assertSet('data.source_tags', [$sourceTag->id])
        // Review is always the 5th visible step: the religious topic detail is
        // a conditional section now, not a conditional step.
        ->assertWizardCurrentStep(5);

    $state = $component->get('data');

    expect($state['languages'] ?? [])->not->toBeEmpty();
    expect($state['discipline_tags'] ?? [])->toContain((string) $disciplineTag->id);
    expect($state['issue_tags'] ?? [])->toContain((string) $issueTag->id);
    expect($state['poster'] ?? null)->not->toBeNull();

    $component->call('submit')->assertHasNoErrors()->assertRedirect(route('submit-event.success'));

    $event = Event::query()->where('title', 'Daurah Fiqh Keluarga')->sole();
    expect($event->getMedia('poster'))->toHaveCount(1);
});

it('rejects unsupported files before calling AI extraction', function () {
    $this->mock(EventMediaExtractionService::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('extract');
    });

    Livewire::test(Create::class)
        ->set('event_source_attachment', UploadedFile::fake()->create('notes.txt', 10, 'text/plain'))
        ->call('extractEventFromMedia')
        ->assertHasErrors(['event_source_attachment']);

});
