<?php

declare(strict_types=1);

use App\Livewire\Pages\Institutions\Show;
use App\Models\Event;
use App\Models\Institution;
use App\Shared\Events\Enums\EventFormat;
use App\Shared\Events\Enums\EventVisibility;
use App\Support\Events\PrimaryOccurrenceSql;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * D1c: grouped public submissions project one parent over many occurrences.
 * Their end instant must follow the latest eligible occurrence end so an
 * ongoing grouped majlis stays upcoming instead of falling out of both scopes.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-04-16 16:30:00', 'UTC'));
});

/**
 * @param  list<array{starts_at: string, ends_at?: string|null, status?: string, visibility?: string}>  $occurrences
 */
function groupedEndInstantEvent(string $title, Institution $institution, array $occurrences): Event
{
    $event = Event::factory()->create([
        'title' => $title,
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now()->subDay(),
        'starts_at' => null,
        'delivery_mode' => EventFormat::Physical,
        'metadata' => ['public_submission_group' => ['key' => 'group-'.Str::uuid()]],
    ]);

    foreach ($occurrences as $occurrence) {
        $startsAt = Carbon::parse($occurrence['starts_at'], 'UTC');
        $endsAt = isset($occurrence['ends_at']) ? Carbon::parse($occurrence['ends_at'], 'UTC') : null;

        unset($occurrence['starts_at'], $occurrence['ends_at']);

        $event->occurrences()->create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'timezone' => 'Asia/Kuala_Lumpur',
            'status' => 'published',
            'visibility' => EventVisibility::Public->value,
            'delivery_mode' => EventFormat::Physical->value,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            ...$occurrence,
        ]);
    }

    return $event->refresh();
}

/**
 * @return array{upcoming: bool, past: bool}
 */
function groupedEndInstantScopes(Event $event): array
{
    $eventsTable = (new Event)->getTable();
    $endInstant = PrimaryOccurrenceSql::endInstant($eventsTable);

    return [
        'upcoming' => Event::query()->whereKey($event->getKey())->whereRaw("{$endInstant} >= ?", [now()])->exists(),
        'past' => Event::query()->whereKey($event->getKey())->whereRaw("{$endInstant} < ?", [now()])->exists(),
    ];
}

it('classifies a grouped event with an ongoing occurrence as upcoming on the sql, page and api projections', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = groupedEndInstantEvent('Grouped Ongoing', $institution, [
        ['starts_at' => '2026-04-16 15:30:00', 'ends_at' => '2026-04-16 17:00:00'],
    ]);

    expect(groupedEndInstantScopes($event))->toBe(['upcoming' => true, 'past' => false]);

    $component = Livewire::test(Show::class, ['institution' => $institution]);
    $upcoming = $component->instance()
        ->getUpcomingEventsProperty()
        ->map(static fn ($leaf): string => $leaf->event->title)
        ->all();
    $past = $component->instance()
        ->getPastEventsProperty()
        ->map(static fn ($leaf): string => $leaf->event->title)
        ->all();

    expect($upcoming)->toBe(['Grouped Ongoing'])
        ->and($past)->toBe([]);

    $response = $this->getJson(route('api.client.institutions.show', ['institutionKey' => $institution->slug]))
        ->assertOk();

    expect($response->json('data.upcoming_total'))->toBe(1)
        ->and($response->json('data.past_total'))->toBe(0)
        ->and($response->json('data.upcoming_events.0.id'))->toBe((string) $event->getKey());
});

it('classifies a grouped event whose eligible occurrences all ended as past', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = groupedEndInstantEvent('Grouped Ended', $institution, [
        ['starts_at' => '2026-04-14 10:00:00', 'ends_at' => '2026-04-14 12:00:00'],
        ['starts_at' => '2026-04-16 14:00:00', 'ends_at' => '2026-04-16 15:45:00'],
        ['starts_at' => '2026-04-20 09:00:00', 'ends_at' => '2026-04-20 11:00:00', 'status' => 'draft', 'visibility' => 'private'],
    ]);

    expect(groupedEndInstantScopes($event))->toBe(['upcoming' => false, 'past' => true]);

    $response = $this->getJson(route('api.client.institutions.show', ['institutionKey' => $institution->slug]))
        ->assertOk();

    expect($response->json('data.upcoming_total'))->toBe(0)
        ->and($response->json('data.past_total'))->toBe(1)
        ->and($response->json('data.past_events.0.id'))->toBe((string) $event->getKey());
});

it('leaves a grouped event with no eligible occurrence outside both time scopes', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = groupedEndInstantEvent('Grouped Ineligible', $institution, [
        ['starts_at' => '2026-04-20 09:00:00', 'ends_at' => '2026-04-20 11:00:00', 'status' => 'draft', 'visibility' => 'private'],
    ]);

    expect(groupedEndInstantScopes($event))->toBe(['upcoming' => false, 'past' => false])
        ->and($event->fresh()->toSearchableArray()['ends_at'])->toBeNull();
});

it('keeps a grouped event with only future occurrences upcoming', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = groupedEndInstantEvent('Grouped Future', $institution, [
        ['starts_at' => '2026-04-18 09:00:00', 'ends_at' => '2026-04-18 11:00:00'],
        ['starts_at' => '2026-04-20 09:00:00', 'ends_at' => '2026-04-20 11:00:00'],
    ]);

    expect(groupedEndInstantScopes($event))->toBe(['upcoming' => true, 'past' => false]);
});

it('indexes the maximum effective grouped end instant and falls back to starts_at for open-ended occurrences', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = groupedEndInstantEvent('Grouped Index Max', $institution, [
        ['starts_at' => '2026-04-14 10:00:00', 'ends_at' => '2026-04-14 12:00:00'],
        ['starts_at' => '2026-04-16 15:30:00', 'ends_at' => '2026-04-16 17:00:00'],
        ['starts_at' => '2026-04-19 09:00:00', 'ends_at' => '2026-04-19 11:30:00'],
        ['starts_at' => '2026-04-21 09:00:00', 'ends_at' => '2026-04-21 12:00:00', 'visibility' => 'private'],
        ['starts_at' => '2026-04-22 09:00:00', 'ends_at' => '2026-04-22 12:00:00', 'status' => 'cancelled'],
    ]);

    $payload = $event->fresh()->toSearchableArray();

    expect($payload['ends_at'])->toBe(Carbon::parse('2026-04-19 11:30:00', 'UTC')->timestamp)
        ->and($payload['starts_at'])->not->toBe(0);

    $openEnded = groupedEndInstantEvent('Grouped Index Open Ended', $institution, [
        ['starts_at' => '2026-04-17 09:00:00', 'ends_at' => null],
    ]);

    expect($openEnded->fresh()->toSearchableArray()['ends_at'])
        ->toBe(Carbon::parse('2026-04-17 09:00:00', 'UTC')->timestamp);
});

it('keeps the managed first-occurrence end and open-ended index behavior unchanged', function (): void {
    $firstStartsAt = now()->subHours(4);
    $firstEndsAt = now()->subHours(3);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now()->subDay(),
        'starts_at' => $firstStartsAt,
        'ends_at' => $firstEndsAt,
    ]);

    $event->occurrences()->create([
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'status' => 'scheduled',
        'visibility' => EventVisibility::Public->value,
    ]);

    $eventsTable = (new Event)->getTable();
    $endInstant = PrimaryOccurrenceSql::endInstant($eventsTable);

    expect(Event::query()->whereKey($event->getKey())->whereRaw("{$endInstant} < ?", [now()])->exists())->toBeTrue()
        ->and($event->fresh()->toSearchableArray()['ends_at'])->toBe($firstEndsAt->timestamp);

    $openEnded = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now()->subDay(),
        'starts_at' => now()->addHours(2),
        'ends_at' => null,
    ]);

    expect($openEnded->fresh()->toSearchableArray()['ends_at'])
        ->toBe($openEnded->fresh()->starts_at?->timestamp);
});
