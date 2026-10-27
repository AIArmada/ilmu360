<?php

use App\Actions\Events\Submission\PersistOccurrenceSubmissionAction;
use App\Data\Events\ValidatedEventSubmission;
use App\Enums\EventFormat;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(EventRoleSeeder::class);
});

function occurrenceSlugSubmission(string $title, Institution|Person $organizer, ?Person $person = null): ValidatedEventSubmission
{
    $eventDate = '2027-03-02';

    return new ValidatedEventSubmission(
        state: [
            'title' => $title,
            'event_date' => $eventDate,
            'event_format' => EventFormat::Online->value,
            'visibility' => EventVisibility::Public->value,
            'persons' => $person instanceof Person ? [(string) $person->getKey()] : [],
            'age_group' => [],
            'languages' => [],
            'event_category_ids' => [],
            'domain_tags' => [],
            'discipline_tags' => [],
            'source_tags' => [],
            'issue_tags' => [],
            'other_key_people' => [],
            'references' => [],
        ],
        startsAt: Carbon::parse($eventDate.' 19:00', 'Asia/Kuala_Lumpur')->utc(),
        endsAt: null,
        timezone: 'Asia/Kuala_Lumpur',
        primaryOrganizer: $organizer,
        targetInstitutionId: null,
        targetVenueId: null,
        prayerTime: null,
        prayerReference: null,
        prayerOffset: null,
        prayerDisplayText: null,
        autoApproved: false,
        sessionSubmission: false,
        submitter: null,
        eventContainer: null,
        personSlugSegments: $person instanceof Person ? [(string) $person->slug] : [],
    );
}

it('omits a matching occurrence title and keeps the date while uniquifying within the parent', function () {
    $organizer = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create([
        'title' => 'Kuliah Maghrib Masjid Shah Alam',
        'slug' => 'kuliah-maghrib-masjid-shah-alam',
    ]);

    $firstOccurrence = app(PersistOccurrenceSubmissionAction::class)->handle(
        $event,
        occurrenceSlugSubmission('Kuliah Maghrib', $organizer),
        pendingReview: false,
    );
    $secondOccurrence = app(PersistOccurrenceSubmissionAction::class)->handle(
        $event,
        occurrenceSlugSubmission('Kuliah Maghrib', $organizer),
        pendingReview: false,
    );

    $this->assertModelExists($firstOccurrence);
    $this->assertModelExists($secondOccurrence);

    expect($firstOccurrence->slug)->toBe('2-3-27')
        ->and($secondOccurrence->slug)->toBe('2-3-27-2');
});

it('keeps the person and date when omitting a matching occurrence title', function () {
    $person = Person::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create([
        'title' => 'Kuliah Maghrib Masjid Shah Alam',
        'slug' => 'kuliah-maghrib-masjid-shah-alam',
    ]);

    $occurrence = app(PersistOccurrenceSubmissionAction::class)->handle(
        $event,
        occurrenceSlugSubmission('Kuliah Maghrib', $person, $person),
        pendingReview: false,
    );

    $this->assertModelExists($occurrence);

    expect($occurrence->slug)->toBe($person->slug.'-2-3-27');
});

it('keeps the occurrence title when it does not appear in the parent slug', function () {
    $person = Person::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create([
        'title' => 'Kuliah Maghrib Masjid Shah Alam',
        'slug' => 'kuliah-maghrib-masjid-shah-alam',
    ]);

    $occurrence = app(PersistOccurrenceSubmissionAction::class)->handle(
        $event,
        occurrenceSlugSubmission('Bengkel Tajwid', $person, $person),
        pendingReview: false,
    );

    $this->assertModelExists($occurrence);

    expect($occurrence->slug)->toBe('bengkel-tajwid-'.$person->slug.'-2-3-27');
});
