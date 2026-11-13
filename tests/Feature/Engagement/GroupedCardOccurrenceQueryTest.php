<?php

declare(strict_types=1);

use AIArmada\Events\Contracts\EventSearchRelationProvider;
use App\Models\Event;
use App\Models\Institution;
use App\Shared\Events\Enums\EventFormat;
use App\Shared\Events\Enums\EventVisibility;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function groupedCardEvent(Institution $institution, string $title): Event
{
    $event = Event::factory()->create([
        'title' => $title,
        'institution_id' => $institution->getKey(),
        'default_venue_id' => null,
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now()->subDay(),
        'starts_at' => null,
        'ends_at' => null,
        'delivery_mode' => EventFormat::Physical,
        'metadata' => ['public_submission_group' => ['key' => 'group-'.Str::uuid()]],
    ]);

    $event->occurrences()->create([
        'title' => $title,
        'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'published',
        'visibility' => EventVisibility::Public->value,
        'delivery_mode' => EventFormat::Physical->value,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
    ]);

    return $event->refresh();
}

it('renders grouped event cards without per-card occurrence queries', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified']);

    $events = collect(range(1, 3))
        ->map(fn (int $index): Event => groupedCardEvent($institution, 'Grouped Card '.$index));

    $relations = app(EventSearchRelationProvider::class)->relations();

    $hydrated = Event::query()
        ->whereKey($events->map(fn (Event $event): string => (string) $event->getKey())->all())
        ->with($relations)
        ->get();

    expect($hydrated)->toHaveCount(3)
        ->and($hydrated->every(fn (Event $event): bool => $event->relationLoaded('goingWindowOccurrences')))->toBeTrue();

    $occurrenceQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$occurrenceQueries): void {
        if (str_contains($query->sql, 'from "event_occurrences"')) {
            $occurrenceQueries[] = $query->sql;
        }
    });

    foreach ($hydrated as $event) {
        $window = $event->goingWindow();
        $occurrenceEnd = $event->goingWindowOccurrences->first()?->ends_at;

        expect($window->isOpenAt(now()))->toBeTrue()
            ->and($window->effectiveEnd()?->getTimestamp())->toBe($occurrenceEnd?->getTimestamp());

        view('components.events.card', ['event' => $event])->render();
    }

    expect($occurrenceQueries)->toBe([]);
});
