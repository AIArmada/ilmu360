<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Institution;
use App\Shared\Events\Enums\EventFormat;
use App\Support\Location\VisitorCountryResolver;

/**
 * D0 characterization: pin the visibility matrix that D1a applies.
 *
 * Ruled matrix:
 * - home + /majlis: approved, pending and cancelled + public + published.
 * - REST event list: approved, pending and cancelled + public + published.
 * - REST event detail + Livewire event detail: approved, pending and cancelled
 *   + public/unlisted + published.
 * - sitemap: approved, pending and cancelled + public + published.
 * - draft and private events are absent from every public surface; unlisted
 *   events are reachable by direct link but absent from listings/sitemaps.
 */
beforeEach(function (): void {
    // PublicScheduleDiscoveryTest does the same: the visitor-country memo is a
    // static per-app/request scope and must not leak a stale UUID across tests.
    app(VisitorCountryResolver::class)->forget();
});

function d0VisibilityEvent(string $title, string $status, string $visibility, bool $published = true): Event
{
    $event = Event::factory()->create([
        'title' => $title,
        'institution_id' => Institution::factory(),
        'default_venue_id' => null,
        'delivery_mode' => EventFormat::Physical->value,
        'status' => $status,
        'visibility' => $visibility,
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
    ]);

    // Home is featured-only; mark every matrix event featured so the home
    // surface can be asserted against the same fixture set.
    $event->occurrences()->update(['is_featured' => true]);

    if (! $published) {
        $event->updateQuietly(['published_at' => null]);
    }

    return $event->refresh();
}

/**
 * @return array<string, Event>
 */
function d0VisibilityMatrix(): array
{
    return [
        'approved_public' => d0VisibilityEvent('D0 Matrix Approved Public', 'approved', 'public'),
        'pending_public' => d0VisibilityEvent('D0 Matrix Pending Public', 'pending', 'public'),
        'cancelled_public' => d0VisibilityEvent('D0 Matrix Cancelled Public', 'cancelled', 'public'),
        'draft_public' => d0VisibilityEvent('D0 Matrix Draft Public', 'draft', 'public'),
        'approved_unlisted' => d0VisibilityEvent('D0 Matrix Approved Unlisted', 'approved', 'unlisted'),
        'approved_private' => d0VisibilityEvent('D0 Matrix Approved Private', 'approved', 'private'),
        'approved_unpublished' => d0VisibilityEvent('D0 Matrix Approved Unpublished', 'approved', 'public', published: false),
    ];
}

it('pins the home visibility matrix', function (): void {
    $events = d0VisibilityMatrix();

    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee($events['approved_public']->title)
        ->assertSee($events['pending_public']->title)
        ->assertSee($events['cancelled_public']->title)
        ->assertDontSee($events['draft_public']->title)
        ->assertDontSee($events['approved_unlisted']->title)
        ->assertDontSee($events['approved_private']->title)
        ->assertDontSee($events['approved_unpublished']->title);
});

it('pins the majlis listing visibility matrix', function (): void {
    $events = d0VisibilityMatrix();

    $response = $this->get(route('events.index'));

    $response->assertOk()
        ->assertSee($events['approved_public']->title)
        ->assertSee($events['pending_public']->title)
        ->assertSee($events['cancelled_public']->title)
        ->assertDontSee($events['draft_public']->title)
        ->assertDontSee($events['approved_unlisted']->title)
        ->assertDontSee($events['approved_private']->title)
        ->assertDontSee($events['approved_unpublished']->title);
});

it('pins the REST event list visibility matrix', function (): void {
    $events = d0VisibilityMatrix();

    $response = $this->getJson(route('api.events.index', ['per_page' => 50]));
    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

    expect($ids)
        ->toContain((string) $events['approved_public']->getKey())
        ->toContain((string) $events['pending_public']->getKey())
        ->toContain((string) $events['cancelled_public']->getKey())
        ->not->toContain((string) $events['draft_public']->getKey())
        ->not->toContain((string) $events['approved_unlisted']->getKey())
        ->not->toContain((string) $events['approved_private']->getKey())
        ->not->toContain((string) $events['approved_unpublished']->getKey());
});

it('pins the REST event detail visibility matrix', function (): void {
    $events = d0VisibilityMatrix();

    foreach (['approved_public', 'pending_public', 'cancelled_public', 'approved_unlisted'] as $key) {
        $this->getJson(route('api.events.show', $events[$key]))->assertOk();
    }

    foreach (['draft_public', 'approved_private', 'approved_unpublished'] as $key) {
        $this->getJson(route('api.events.show', $events[$key]))->assertNotFound();
    }
});

it('pins the Livewire event detail visibility matrix', function (): void {
    $events = d0VisibilityMatrix();

    foreach (['approved_public', 'pending_public', 'cancelled_public', 'approved_unlisted'] as $key) {
        $this->get(route('events.show', $events[$key]))->assertOk();
    }

    foreach (['draft_public', 'approved_private', 'approved_unpublished'] as $key) {
        $this->get(route('events.show', $events[$key]))->assertNotFound();
    }
});

it('pins the event sitemap visibility matrix', function (): void {
    $events = d0VisibilityMatrix();

    $response = $this->get(route('sitemap.events'));

    $response->assertOk();

    foreach (['approved_public', 'pending_public', 'cancelled_public'] as $key) {
        $response->assertSee(route('events.show', $events[$key]), false);
    }

    foreach (['draft_public', 'approved_unlisted', 'approved_private', 'approved_unpublished'] as $key) {
        $response->assertDontSee(route('events.show', $events[$key]), false);
    }
});

it('lists the registered sitemap routes in the sitemap index', function (): void {
    $response = $this->get(route('sitemap.index'));

    $response->assertOk()
        ->assertSee(route('sitemap.events'), false)
        ->assertSee(route('sitemap.institutions'), false)
        ->assertSee(route('sitemap.persons'), false)
        ->assertDontSee(url('/sitemap-events.xml'), false)
        ->assertDontSee(url('/sitemap-institutions.xml'), false)
        ->assertDontSee(url('/sitemap-persons.xml'), false);

    expect(route('sitemap.events'))->toEndWith('/peta-laman-majlis.xml')
        ->and(route('sitemap.institutions'))->toEndWith('/peta-laman-institusi.xml')
        ->and(route('sitemap.persons'))->toEndWith('/peta-laman-penceramah.xml');
});
