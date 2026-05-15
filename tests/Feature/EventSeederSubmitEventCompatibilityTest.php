<?php

use App\Enums\EventFormat;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\User;
use App\Models\Venue;
use App\Support\Location\PreferredCountryResolver;
use Database\Seeders\EventSeeder;
use Database\Seeders\TagSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    ensureEventSeederMalaysiaCountryExists();
});

function ensureEventSeederMalaysiaCountryExists(): void
{
    if (DB::table('countries')->where('id', PreferredCountryResolver::MALAYSIA_ID)->exists()) {
        return;
    }

    DB::table('countries')->insert([
        'id' => PreferredCountryResolver::MALAYSIA_ID,
        'iso2' => 'MY',
        'name' => 'Malaysia',
        'status' => 1,
        'phone_code' => '60',
        'iso3' => 'MYS',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ]);
}

it('seeds schedule events with required submit-event fields', function () {
    $this->seed(TagSeeder::class);

    // Prevent bulk event generation in EventSeeder so test stays focused and fast.
    Event::factory()->create();

    $this->seed(EventSeeder::class);

    $expectedSuffix = Carbon::parse('2026-01-05', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $event = Event::query()
        ->where('slug', "dhuha-adab-iman-ust-mukhlisur-riyadus-my-{$expectedSuffix}")
        ->first();

    expect($event)->not->toBeNull();

    $ageGroup = $event?->age_group;

    expect($ageGroup)->toBeInstanceOf(Collection::class)
        ->and($ageGroup?->isNotEmpty())->toBeTrue()
        ->and($event?->organizer_type)->toBeIn([Institution::class, Speaker::class])
        ->and($event?->organizer_id)->not->toBeNull();

    $tagTypes = $event?->tags()->pluck('type')->unique()->values()->all() ?? [];

    expect($tagTypes)
        ->toContain('domain')
        ->toContain('discipline');

    $hasInstitutionLocation = is_string($event?->institution_id) && $event->institution_id !== '';
    $hasVenueLocation = is_string($event?->venue_id) && $event->venue_id !== '';

    expect($hasInstitutionLocation xor $hasVenueLocation)->toBeTrue();
});

it('clears seeded online event physical location during backfill', function () {
    $this->seed(TagSeeder::class);

    $institution = Institution::factory()->create();
    $venue = Venue::factory()->create();
    $space = Space::factory()->create();

    $event = Event::factory()->create([
        'event_format' => EventFormat::Online,
        'institution_id' => $institution->id,
        'venue_id' => $venue->id,
        'space_id' => $space->id,
        'submitter_id' => null,
        'user_id' => null,
    ]);

    $this->seed(EventSeeder::class);

    $event->refresh();

    expect($event->event_format)->toBe(EventFormat::Online)
        ->and($event->institution_id)->toBeNull()
        ->and($event->venue_id)->toBeNull()
        ->and($event->space_id)->toBeNull();
});

it('does not duplicate seeded schedule events when the seeder reruns', function () {
    $this->seed(TagSeeder::class);

    Event::factory()->create();

    $this->seed(EventSeeder::class);
    $this->seed(EventSeeder::class);

    $expectedSuffix = Carbon::parse('2026-01-05', 'Asia/Kuala_Lumpur')->format('j-n-y');
    $startsAt = Carbon::parse('2026-01-05 10:30:00', 'Asia/Kuala_Lumpur')->utc();

    expect(Event::query()
        ->where('title', 'Dhuha: Adab Iman')
        ->where('starts_at', $startsAt)
        ->count())->toBe(1)
        ->and(Event::query()->where('slug', "dhuha-adab-iman-ust-mukhlisur-riyadus-my-{$expectedSuffix}")->count())->toBe(1);
});

it('matches the original seeded schedule row after manual venue edits', function () {
    $this->seed(TagSeeder::class);

    Event::factory()->create();

    $this->seed(EventSeeder::class);

    $startsAt = Carbon::parse('2026-01-05 10:30:00', 'Asia/Kuala_Lumpur')->utc();
    $event = Event::query()
        ->where('title', 'Dhuha: Adab Iman')
        ->where('starts_at', $startsAt)
        ->firstOrFail();

    $seededVenueId = $event->venue_id;
    $replacementVenue = Venue::factory()->create();

    $event->update([
        'venue_id' => $replacementVenue->id,
    ]);

    $this->seed(EventSeeder::class);

    $reloadedEvent = Event::query()
        ->where('title', 'Dhuha: Adab Iman')
        ->where('starts_at', $startsAt)
        ->get();
    $expectedSuffix = Carbon::parse('2026-01-05', 'Asia/Kuala_Lumpur')->format('j-n-y');

    expect($reloadedEvent)->toHaveCount(1)
        ->and($reloadedEvent->first()?->slug)->toBe("dhuha-adab-iman-ust-mukhlisur-riyadus-my-{$expectedSuffix}")
        ->and($reloadedEvent->first()?->venue_id)->toBe($seededVenueId);
});

it('does not overwrite unrelated events that share a schedule title and start time', function () {
    $this->seed(TagSeeder::class);

    $startsAt = Carbon::parse('2026-01-05 10:30:00', 'Asia/Kuala_Lumpur')->utc();
    $unrelatedVenue = Venue::factory()->create();
    $owner = User::factory()->create();
    $unrelatedEvent = Event::factory()->create([
        'title' => 'Dhuha: Adab Iman',
        'slug' => 'manual-same-slot-event',
        'starts_at' => $startsAt,
        'venue_id' => $unrelatedVenue->id,
        'event_format' => EventFormat::Physical,
        'user_id' => $owner->id,
    ]);

    $this->seed(EventSeeder::class);

    $matchingEvents = Event::query()
        ->where('title', 'Dhuha: Adab Iman')
        ->where('starts_at', $startsAt)
        ->orderBy('id')
        ->get();
    $seededEvent = $matchingEvents->firstWhere('slug', 'dhuha-adab-iman-ust-mukhlisur-riyadus-my-5-1-26');

    expect($matchingEvents)->toHaveCount(2)
        ->and($unrelatedEvent->fresh()?->id)->toBe($unrelatedEvent->id)
        ->and($unrelatedEvent->fresh()?->venue_id)->toBe($unrelatedVenue->id)
        ->and($seededEvent)->not->toBeNull();
});

it('re-canonicalizes reused schedule speaker slugs before rebuilding seeded event slugs', function () {
    $this->seed(TagSeeder::class);

    Event::factory()->create();

    $this->seed(EventSeeder::class);

    $expectedSuffix = Carbon::parse('2026-01-05', 'Asia/Kuala_Lumpur')->format('j-n-y');
    $startsAt = Carbon::parse('2026-01-05 10:30:00', 'Asia/Kuala_Lumpur')->utc();
    $event = Event::query()
        ->where('title', 'Dhuha: Adab Iman')
        ->where('starts_at', $startsAt)
        ->firstOrFail();
    $speaker = $event->speakers()->firstOrFail();

    Speaker::withoutTimestamps(function () use ($speaker): void {
        $speaker->forceFill([
            'slug' => 'legacy-random-speaker-slug',
        ])->saveQuietly();
    });

    $event->update([
        'slug' => 'legacy-random-event-slug',
    ]);

    $this->seed(EventSeeder::class);

    expect($speaker->fresh()?->slug)->toBe('ust-mukhlisur-riyadus-my')
        ->and($event->fresh()?->slug)->toBe("dhuha-adab-iman-ust-mukhlisur-riyadus-my-{$expectedSuffix}");
});

it('creates and then reuses a dedicated schedule speaker when duplicate-name speakers already exist', function () {
    $this->seed(TagSeeder::class);

    Event::factory()->create();

    $firstDuplicate = Speaker::factory()->create([
        'name' => 'Ust Mukhlisur Riyadus',
        'slug' => 'ust-mukhlisur-riyadus-duplicate-1',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $secondDuplicate = Speaker::factory()->create([
        'name' => 'Ust Mukhlisur Riyadus',
        'slug' => 'ust-mukhlisur-riyadus-duplicate-2',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $this->seed(EventSeeder::class);

    $startsAt = Carbon::parse('2026-01-05 10:30:00', 'Asia/Kuala_Lumpur')->utc();
    $event = Event::query()
        ->where('title', 'Dhuha: Adab Iman')
        ->where('starts_at', $startsAt)
        ->firstOrFail();
    $scheduleSpeaker = $event->speakers()->firstOrFail();
    $speakerCountAfterFirstSeed = Speaker::query()->where('name', 'Ust Mukhlisur Riyadus')->count();

    expect([$firstDuplicate->id, $secondDuplicate->id])->not->toContain($scheduleSpeaker->id);

    $this->seed(EventSeeder::class);

    $event->refresh();

    expect($event->speakers()->firstOrFail()->id)->toBe($scheduleSpeaker->id)
        ->and(Speaker::query()->where('name', 'Ust Mukhlisur Riyadus')->count())->toBe($speakerCountAfterFirstSeed);
});

it('creates a dedicated schedule speaker when exactly one unrelated same-name speaker already exists', function () {
    $this->seed(TagSeeder::class);

    Event::factory()->create();

    $existingSpeaker = Speaker::factory()->create([
        'name' => 'Ust Mukhlisur Riyadus',
        'slug' => 'existing-ust-mukhlisur-riyadus',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $this->seed(EventSeeder::class);

    $startsAt = Carbon::parse('2026-01-05 10:30:00', 'Asia/Kuala_Lumpur')->utc();
    $event = Event::query()
        ->where('title', 'Dhuha: Adab Iman')
        ->where('starts_at', $startsAt)
        ->firstOrFail();
    $scheduleSpeaker = $event->speakers()->firstOrFail();

    expect($scheduleSpeaker->id)->not->toBe($existingSpeaker->id)
        ->and(Speaker::query()->where('name', 'Ust Mukhlisur Riyadus')->count())->toBe(2);
});

it('reuses the original seeded organizer speaker when the speaker pivot was detached before rerun', function () {
    $this->seed(TagSeeder::class);

    Event::factory()->create();

    $this->seed(EventSeeder::class);

    $startsAt = Carbon::parse('2026-01-05 10:30:00', 'Asia/Kuala_Lumpur')->utc();
    $event = Event::query()
        ->where('title', 'Dhuha: Adab Iman')
        ->where('starts_at', $startsAt)
        ->firstOrFail();
    $speaker = $event->speakers()->firstOrFail();
    $speakerCountAfterFirstSeed = Speaker::query()->where('name', 'Ust Mukhlisur Riyadus')->count();

    $event->keyPeople()->delete();

    $this->seed(EventSeeder::class);

    expect($event->fresh()?->speakers()->firstOrFail()->id)->toBe($speaker->id)
        ->and(Speaker::query()->where('name', 'Ust Mukhlisur Riyadus')->count())->toBe($speakerCountAfterFirstSeed);
});

it('reuses the same dedicated speaker across repeated schedule rows in a fresh seed run', function () {
    $this->seed(TagSeeder::class);

    Event::factory()->create();

    $this->seed(EventSeeder::class);

    $firstEvent = Event::query()
        ->where('title', 'Dhuha: Berusrah Bersama')
        ->whereDate('starts_at', '2026-01-19')
        ->firstOrFail();
    $secondEvent = Event::query()
        ->where('title', 'Dhuha: Berusrah Bersama')
        ->whereDate('starts_at', '2026-01-26')
        ->firstOrFail();

    expect($firstEvent->speakers()->firstOrFail()->id)->toBe($secondEvent->speakers()->firstOrFail()->id)
        ->and(Speaker::query()->where('name', 'Ust Mohd Nazri Abdul Razak')->count())->toBe(1);
});
