<?php

use AIArmada\FilamentAuthz\Facades\Authz;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Services\EventKeyPersonSyncService;
use App\Support\Authz\MemberRoleScopes;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

it('prefills the submit-event form from a duplicated public event', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Duplikasi',
        'allow_public_event_submission' => true,
    ]);
    $venue = Venue::factory()->create(['name' => 'Dewan Duplikasi']);
    $speaker = Speaker::factory()->create([
        'allow_public_event_submission' => true,
    ]);
    $moderator = Speaker::factory()->create([
        'allow_public_event_submission' => true,
    ]);
    $domainTag = Tag::factory()->domain()->create();
    $disciplineTag = Tag::factory()->discipline()->create();
    $sourceTag = Tag::factory()->source()->create();
    $issueTag = Tag::factory()->issue()->create();
    $reference = Reference::factory()->verified()->create();

    $event = Event::factory()->for($institution)->create([
        'title' => 'Kuliah Tafsir Dwi-Mingguan',
        'description' => ['html' => '<p>Huraian ayat pilihan untuk jamaah setempat.</p>'],
        'status' => 'approved',
        'visibility' => EventVisibility::Public->value,
        'published_at' => now()->subDay(),
        'timezone' => 'Asia/Kuala_Lumpur',
        'starts_at' => Carbon::parse('2026-05-10 20:15:00', 'Asia/Kuala_Lumpur')->utc(),
        'ends_at' => Carbon::parse('2026-05-10 22:00:00', 'Asia/Kuala_Lumpur')->utc(),
        'event_type' => [EventType::KuliahCeramah->value],
        'event_format' => EventFormat::Physical->value,
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'institution_id' => $institution->id,
        'venue_id' => $venue->id,
        'gender' => EventGenderRestriction::MenOnly->value,
        'age_group' => [EventAgeGroup::Adults->value],
        'children_allowed' => false,
        'is_muslim_only' => true,
        'event_url' => 'https://example.test/event',
        'live_url' => 'https://example.test/live',
    ]);

    $event->syncTags([$domainTag, $disciplineTag, $sourceTag, $issueTag]);
    $event->references()->attach($reference->id);
    $event->syncLanguages([40, 101]);

    app(EventKeyPersonSyncService::class)->sync(
        $event,
        [(string) $speaker->id],
        [[
            'role' => EventKeyPersonRole::Moderator->value,
            'speaker_id' => (string) $moderator->id,
            'name' => null,
            'is_public' => true,
            'notes' => 'Moderator utama',
        ]],
    );

    $component = Livewire::withQueryParams(['duplicate' => $event->id])
        ->test('pages.submit-event.create');

    $component->assertFormSet([
        'event_date' => '2026-05-10',
        'prayer_time' => EventPrayerTime::LainWaktu->value,
        'custom_time' => '20:15',
        'end_time' => '22:00',
    ]);

    $descriptionState = $component->get('data.description');

    expect($component->get('data.title'))->toBe('Kuliah Tafsir Dwi-Mingguan')
        ->and(data_get($descriptionState, 'content.0.content.0.text'))->toBe('Huraian ayat pilihan untuk jamaah setempat.')
        ->and($component->get('data.event_type'))->toBe([EventType::KuliahCeramah->value])
        ->and($component->get('data.event_format'))->toBe(EventFormat::Physical->value)
        ->and($component->get('data.visibility'))->toBe(EventVisibility::Public->value)
        ->and($component->get('data.organizer_type'))->toBe('institution')
        ->and($component->get('data.organizer_institution_id'))->toBe($institution->id)
        ->and($component->get('data.location_same_as_institution'))->toBeFalse()
        ->and($component->get('data.location_type'))->toBe('venue')
        ->and($component->get('data.location_venue_id'))->toBe($venue->id)
        ->and($component->get('data.event_url'))->toBe('https://example.test/event')
        ->and($component->get('data.live_url'))->toBe('https://example.test/live')
        ->and($component->get('data.speakers'))->toBe([(string) $speaker->id])
        ->and($component->get('data.languages'))->toEqualCanonicalizing([40, 101])
        ->and($component->get('data.domain_tags'))->toEqualCanonicalizing([(string) $domainTag->id])
        ->and($component->get('data.discipline_tags'))->toEqualCanonicalizing([(string) $disciplineTag->id])
        ->and($component->get('data.source_tags'))->toEqualCanonicalizing([(string) $sourceTag->id])
        ->and($component->get('data.issue_tags'))->toEqualCanonicalizing([(string) $issueTag->id])
        ->and($component->get('data.references'))->toEqualCanonicalizing([(string) $reference->id])
        ->and(array_values($component->get('data.other_key_people')))->toBe([
            [
                'role' => EventKeyPersonRole::Moderator->value,
                'speaker_id' => (string) $moderator->id,
                'name' => null,
                'is_public' => true,
                'notes' => 'Moderator utama',
            ],
        ]);
});

it('filters inaccessible organizer and speaker defaults when duplicating an event', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Tertutup',
        'allow_public_event_submission' => false,
    ]);
    $speaker = Speaker::factory()->create([
        'allow_public_event_submission' => false,
    ]);

    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Asal Tertutup',
        'status' => 'approved',
        'visibility' => EventVisibility::Public->value,
        'published_at' => now()->subDay(),
        'timezone' => 'Asia/Kuala_Lumpur',
        'starts_at' => Carbon::parse('2026-06-12 19:30:00', 'Asia/Kuala_Lumpur')->utc(),
        'event_type' => [EventType::KuliahCeramah->value],
        'event_format' => EventFormat::Physical->value,
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'institution_id' => $institution->id,
    ]);

    app(EventKeyPersonSyncService::class)->sync(
        $event,
        [(string) $speaker->id],
        [[
            'role' => EventKeyPersonRole::Moderator->value,
            'speaker_id' => (string) $speaker->id,
            'name' => null,
            'is_public' => true,
            'notes' => 'Moderator tidak awam',
        ]],
    );

    $component = Livewire::withQueryParams(['duplicate' => $event->id])
        ->test('pages.submit-event.create');

    expect($component->get('data.organizer_institution_id'))->toBeNull()
        ->and($component->get('data.speakers'))->toBe([])
        ->and(array_values($component->get('data.other_key_people')))->toBe([
            [
                'role' => EventKeyPersonRole::Moderator->value,
                'speaker_id' => null,
                'name' => $speaker->formatted_name,
                'is_public' => true,
                'notes' => 'Moderator tidak awam',
            ],
        ]);
});

it('allows institution admins to duplicate managed non-public events', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Pentadbir Duplikasi',
        'allow_public_event_submission' => false,
    ]);

    $institution->members()->syncWithoutDetaching([$user->id]);

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();

    $institutionScope = app(MemberRoleScopes::class)->institution();

    Authz::withScope($institutionScope, function () use ($user): void {
        $user->syncRoles(['admin']);
    }, $user);

    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Dalaman Untuk Duplikasi',
        'status' => 'draft',
        'visibility' => EventVisibility::Private->value,
        'is_active' => false,
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'institution_id' => $institution->id,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['duplicate' => $event->id])
        ->test('pages.submit-event.create')
        ->assertSet('data.title', 'Majlis Dalaman Untuk Duplikasi')
        ->assertSet('data.organizer_type', 'institution')
        ->assertSet('data.organizer_institution_id', $institution->id);
});

it('prefills duplicated event times in the selected public country timezone instead of the viewer timezone', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Rentas Zon',
        'allow_public_event_submission' => true,
    ]);

    $event = Event::factory()->for($institution)->create([
        'title' => 'Majlis Tengah Malam Malaysia',
        'status' => 'approved',
        'visibility' => EventVisibility::Public->value,
        'published_at' => now()->subDay(),
        'timezone' => 'Asia/Kuala_Lumpur',
        'starts_at' => Carbon::parse('2026-05-10 00:30:00', 'Asia/Kuala_Lumpur')->utc(),
        'ends_at' => Carbon::parse('2026-05-10 02:00:00', 'Asia/Kuala_Lumpur')->utc(),
        'event_type' => [EventType::KuliahCeramah->value],
        'event_format' => EventFormat::Physical->value,
        'organizer_type' => Institution::class,
        'organizer_id' => $institution->id,
        'institution_id' => $institution->id,
    ]);

    $component = Livewire::withCookie('user_timezone', 'America/Los_Angeles')
        ->withCookie('public_country', 'malaysia')
        ->withQueryParams(['duplicate' => $event->id])
        ->test('pages.submit-event.create');

    $component->assertFormSet([
        'event_date' => '2026-05-10',
        'prayer_time' => EventPrayerTime::LainWaktu->value,
        'custom_time' => '00:30',
        'end_time' => '02:00',
    ]);
});
