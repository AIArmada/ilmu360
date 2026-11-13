<?php

declare(strict_types=1);

use App\Features\ShareTracking\Actions\BrowserSelection\LockBrowserSelectionAction;
use App\Features\ShareTracking\Api\BrowserSelectionWriter;
use App\Models\Event;
use App\Models\ShareBrowserSelection;
use App\Models\User;
use Illuminate\Support\Str;

it('binds the browser selection writer through the feature api', function (): void {
    expect(app(BrowserSelectionWriter::class))->toBeInstanceOf(LockBrowserSelectionAction::class);
});

it('locks or creates one browser selection and reuses it on repeat', function (): void {
    $action = app(LockBrowserSelectionAction::class);

    $firstId = $action->lockOrCreate('browser-key-one');
    $secondId = $action->lockOrCreate('browser-key-one');

    expect($firstId)->toBeString()
        ->and($secondId)->toBe($firstId)
        ->and(ShareBrowserSelection::query()->count())->toBe(1)
        ->and($action->lockExisting('browser-key-one'))->toBe($firstId)
        ->and($action->lockExisting('browser-key-two'))->toBeNull();
});

it('accepts and clears the browser selection through scalar ids', function (): void {
    $action = app(LockBrowserSelectionAction::class);
    $selectionId = $action->lockOrCreate('browser-key');
    $attributionId = (string) Str::uuid();

    $action->accept($selectionId, $attributionId);

    $selection = ShareBrowserSelection::query()->findOrFail($selectionId);

    expect($action->currentAttributionId($selectionId))->toBe($attributionId)
        ->and((string) $selection->current_attribution_id)->toBe($attributionId)
        ->and($selection->accepted_at)->not->toBeNull();

    $action->clear($selectionId, 'browser-key');

    $selection->refresh();

    expect($action->currentAttributionId($selectionId))->toBeNull()
        ->and($selection->current_attribution_id)->toBeNull()
        ->and($selection->accepted_at)->toBeNull();
});

it('clears a selection resolved by browser key', function (): void {
    $action = app(LockBrowserSelectionAction::class);
    $selectionId = $action->lockOrCreate('browser-key');

    $action->accept($selectionId, (string) Str::uuid());
    $action->clear(browserKey: 'browser-key');

    $cleared = ShareBrowserSelection::query()->sole();

    expect($cleared->current_attribution_id)->toBeNull()
        ->and($cleared->accepted_at)->toBeNull();
});

it('writes, preserves and clears the browser selection through share capture', function (): void {
    $sharer = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);

    $payload = $this->actingAs($sharer)
        ->getJson(route('share.payload', [
            'url' => route('events.show', $event),
            'text' => 'Share this event',
            'title' => $event->title,
        ]))
        ->assertOk()
        ->json();

    $landing = $this->followingRedirects()->get($payload['url'])->assertOk();
    $cookie = (string) $landing->getCookie(config('share-tracking.cookie.name'))?->getValue();

    $selection = ShareBrowserSelection::query()->sole();

    expect($cookie)->not->toBe('')
        ->and($selection->current_attribution_id)->not->toBeNull()
        ->and($selection->accepted_at)->not->toBeNull();

    $acceptedAttributionId = (string) $selection->current_attribution_id;
    $acceptedAt = (string) $selection->accepted_at;

    $this->withCredentials()
        ->withCookie(config('share-tracking.cookie.name'), $cookie)
        ->followingRedirects()
        ->get($payload['url'])
        ->assertOk();

    $selection->refresh();

    expect((string) $selection->current_attribution_id)->toBe($acceptedAttributionId)
        ->and((string) $selection->accepted_at)->toBe($acceptedAt);

    $this->withCredentials()
        ->withCookie(config('share-tracking.cookie.name'), $cookie)
        ->followingRedirects()
        ->get(route('events.index', ['share' => 'deadbeef-dead-beef-dead-beefdeadbeef']))
        ->assertOk();

    $selection->refresh();

    expect($selection->current_attribution_id)->toBeNull()
        ->and($selection->accepted_at)->toBeNull();
});
