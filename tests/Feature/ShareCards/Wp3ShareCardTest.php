<?php

declare(strict_types=1);

use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\Venue;
use AIArmada\Events\Models\VenueSpace;
use App\Contracts\ShareCards\ShareCardReadiness;
use App\Contracts\ShareCards\ShareCardRenderer;
use App\Contracts\ShareCards\ShareCardRenderInput;
use App\Contracts\ShareCards\ShareCardRenderResult;
use App\Enums\EventChangeType;
use App\Enums\EventKeyPersonRole;
use App\Jobs\RenderShareCardJob;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Person;
use App\Services\ShareCards\ShareCardService;
use App\Support\ShareCards\PangoShareCardRenderer;
use App\Support\ShareCards\ShareCardEventPresenter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');

    $this->cards = app(ShareCardService::class);
});

function wp3scEvent(array $overrides = []): Event
{
    $event = Event::factory()->create(array_merge([
        'title' => 'Kuliah Maghrib Perdana',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => '2026-10-12 12:00:00',
        'ends_at' => '2026-10-12 14:00:00',
        'timing_mode' => 'absolute',
    ], $overrides));

    EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'slug' => 'kuliah-maghrib-okt-12',
        'starts_at' => '2026-10-12 12:00:00',
        'ends_at' => '2026-10-12 14:00:00',
    ]);

    $fresh = $event->fresh();

    expect($fresh)->toBeInstanceOf(Event::class);

    return $fresh;
}

function wp3scPngSize(string $bytes): array
{
    $info = getimagesizefromstring($bytes);

    expect($info)->not->toBeFalse();

    return [(int) $info[0], (int) $info[1], (string) $info['mime']];
}

it('serves a reachable immutable 1200x630 card', function (): void {
    $event = wp3scEvent();
    $url = $this->cards->cardUrl($event);

    expect($url)->toStartWith(route('share.card', ['event' => $event->slug]));

    $response = $this->get($url);
    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('immutable')
        ->and($cacheControl)->toContain('max-age=31536000');

    $bytes = (string) $response->getContent();

    expect($bytes)->toStartWith("\x89PNG\r\n\x1a\n")
        ->and(wp3scPngSize($bytes))->toBe([1200, 630, 'image/png']);

    // Second hit serves the cached file: same bytes, no rerender.
    $disk = Storage::disk('public');
    $files = $disk->files('share-cards');

    expect($files)->toHaveCount(1);

    $second = $this->get($url);
    $second->assertOk();

    expect((string) $second->getContent())->toBe($bytes)
        ->and($disk->files('share-cards'))->toHaveCount(1);
});

it('redirects missing and stale revision pins to the canonical URL', function (): void {
    $event = wp3scEvent();

    $bare = $this->get(route('share.card', ['event' => $event->slug]));
    $bare->assertRedirect();

    $canonical = (string) $bare->headers->get('Location');

    expect($canonical)->toContain('v=');

    $this->get($canonical)->assertOk();

    $stale = $this->get(route('share.card', ['event' => $event->slug]).'?locale=ms&v=deadbeefdeadbeef');
    $stale->assertRedirect($canonical);
});

it('selects the intended occurrence accurately', function (): void {
    $event = wp3scEvent();

    $second = EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'slug' => 'kuliah-maghrib-okt-19',
        'starts_at' => '2026-10-19 12:00:00',
        'ends_at' => '2026-10-19 14:00:00',
    ]);

    $url = $this->cards->cardUrl($event, 'kuliah-maghrib-okt-19');

    expect($url)->toContain('occurrence=kuliah-maghrib-okt-19');

    $this->get($url)->assertOk();

    $input = $this->cards->renderInputFor(
        $event->fresh(),
        $second->fresh(),
        'ms',
        $this->cards->resolveDisplayTimezone($event)
    );

    // 12:00 UTC renders as 8:00 PM in +08 with the October date.
    expect($input->scheduleLabel)->toContain('19')
        ->and($input->scheduleLabel)->toContain('8:00')
        ->and($this->cards->cardUrl($event, 'no-such-occurrence'))->toBeNull();

    $this->get(route('share.card', ['event' => $event->slug]).'?locale=ms&occurrence=no-such-occurrence&v=x')
        ->assertNotFound();
});

it('rejects malformed occurrence keys honestly on every driver', function (): void {
    $event = wp3scEvent();

    // Malformed keys never reach the uuid key lookup (PostgreSQL
    // raises a uuid syntax error for them); the slug lookup misses
    // and the card 404s like any unknown occurrence.
    foreach (['!!!', "1' OR '1'='1", str_repeat('x', 500), 'not-a-uuid'] as $key) {
        expect($this->cards->resolveOccurrence($event, $key))->toBeNull()
            ->and($this->cards->cardUrl($event, $key))->toBeNull();

        $this->get(route('share.card', ['event' => $event->slug]).'?locale=ms&occurrence='.urlencode($key).'&v=x')
            ->assertNotFound();
    }

    // A well-formed but unknown UUID still resolves through the key
    // path and 404s.
    $unknown = '00000000-0000-0000-0000-000000000000';

    expect($this->cards->resolveOccurrence($event, $unknown))->toBeNull();

    $this->get(route('share.card', ['event' => $event->slug]).'?locale=ms&occurrence='.$unknown.'&v=x')
        ->assertNotFound();
});

it('renders multilingual titles verbatim without dropping scripts', function (): void {
    $event = wp3scEvent(['title' => 'محاضرة الفجر الأسبوعية']);

    $url = $this->cards->cardUrl($event, null, 'ar');

    expect($url)->toContain('locale=ar');

    $response = $this->get($url);
    $response->assertOk();

    $bytes = (string) $response->getContent();

    expect($bytes)->toStartWith("\x89PNG\r\n\x1a\n")
        ->and(wp3scPngSize($bytes))->toBe([1200, 630, 'image/png']);

    $input = $this->cards->renderInputFor($event->fresh(), null, 'ar', 'UTC');

    expect($input->title)->toBe('محاضرة الفجر الأسبوعية')
        ->and($input->locale)->toBe('ar');

    $this->get(route('share.card', ['event' => $event->slug]).'?locale=xx&v=x')->assertNotFound();
});

it('prints the published display timezone and isolates cache keys by zone', function (): void {
    $event = wp3scEvent(['timezone' => 'Pacific/Auckland']);

    expect($this->cards->resolveDisplayTimezone($event))->toBe('Pacific/Auckland');

    $input = $this->cards->renderInputFor($event->fresh(), null, 'ms', 'Pacific/Auckland');

    // 12:00 UTC on 12 Oct is 1:00 AM on 13 Oct in NZDT.
    expect($input->scheduleLabel)->toContain('13')
        ->and($input->scheduleLabel)->toContain('NZDT')
        ->and(mb_strlen($input->scheduleLabel))->toBeLessThanOrEqual(40);

    $aucklandUrl = $this->cards->cardUrl($event);

    $event->update(['timezone' => 'UTC']);
    $utcUrl = $this->cards->cardUrl($event->fresh());

    expect($aucklandUrl)->not->toBe($utcUrl);

    // Unconfigured events fall back to the configured default zone.
    expect($this->cards->resolveDisplayTimezone(wp3scEvent(['timezone' => null])))
        ->toBe((string) config('app.default_user_timezone'));
});

it('shows cancellation and rescheduling honestly on reachable cards', function (): void {
    $cancelled = wp3scEvent(['status' => 'cancelled']);

    $cancelledInput = $this->cards->renderInputFor($cancelled->fresh(), null, 'ms', 'UTC');

    expect($cancelledInput->statusLabel)->toBe(EventChangeType::Cancelled->publicBadgeLabel());
    $this->get((string) $this->cards->cardUrl($cancelled))->assertOk();

    $event = wp3scEvent();
    $occurrence = $event->occurrences()->firstOrFail();
    $occurrence->update(['status' => 'postponed']);

    $postponedInput = $this->cards->renderInputFor($event->fresh(), $occurrence->fresh(), 'ms', 'UTC');

    expect($postponedInput->statusLabel)->toBe(EventChangeType::Postponed->publicBadgeLabel());

    $occurrence->update(['status' => 'rescheduled']);

    $rescheduledInput = $this->cards->renderInputFor($event->fresh(), $occurrence->fresh(), 'ms', 'UTC');

    expect($rescheduledInput->statusLabel)->toBe(EventChangeType::ScheduleChanged->publicBadgeLabel());
    $this->get((string) $this->cards->cardUrl($event->fresh(), (string) $occurrence->slug))->assertOk();
});

it('labels verified organizers and speakers from current state', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = wp3scEvent(['institution_id' => $institution->getKey()]);

    $input = $this->cards->renderInputFor($event->fresh(), null, 'ms', 'UTC');

    expect($input->verifiedLabel)->toBe('Penganjur disahkan');

    $person = Person::factory()->create(['status' => 'verified']);
    EventKeyPerson::factory()->create([
        'event_id' => $event->getKey(),
        'involveable_type' => 'person',
        'involveable_id' => $person->getKey(),
        'role_code' => EventKeyPersonRole::Speaker->value,
        'visibility' => 'public',
    ]);

    $pendingInstitution = Institution::factory()->create(['status' => 'pending']);
    $plain = wp3scEvent(['institution_id' => $pendingInstitution->getKey()]);

    $speakerOnly = $this->cards->renderInputFor($plain->fresh(), null, 'ms', 'UTC');

    expect($speakerOnly->verifiedLabel)->toBeNull();

    EventKeyPerson::factory()->create([
        'event_id' => $plain->getKey(),
        'involveable_type' => 'person',
        'involveable_id' => $person->getKey(),
        'role_code' => EventKeyPersonRole::Speaker->value,
        'visibility' => 'public',
    ]);

    $withSpeaker = $this->cards->renderInputFor($plain->fresh(), null, 'en', 'UTC');

    expect($withSpeaker->verifiedLabel)->toBe('Verified speaker')
        ->and($withSpeaker->speakerLabel)->toBe((string) $person->formatted_name);
});

it('uses the canonical prayer presentation for prayer-relative events', function (): void {
    $event = wp3scEvent(['timing_mode' => 'prayer_relative']);

    // The factory seeds its own prayer expression; replace it so the
    // custom label is the canonical first prayer expression.
    $event->timeExpressions()->delete();

    $event->timeExpressions()->create([
        'event_id' => $event->getKey(),
        'time_mode' => 'prayer_relative',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'display_label' => 'Selepas Maghrib',
    ]);

    $input = $this->cards->renderInputFor($event->fresh(), null, 'ms', 'UTC');

    expect($input->scheduleLabel)->toContain('Selepas Maghrib');

    $this->get((string) $this->cards->cardUrl($event->fresh()))->assertOk();
});

it('falls back to cover art honestly when a title cannot render', function (): void {
    $event = wp3scEvent(['title' => 'Kuliah Khas 🎉🎊🥳 Mubarak']);

    $response = $this->get((string) $this->cards->cardUrl($event));

    $response->assertRedirect($event->card_image_url);

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');

    // The full title survives in alt text: nothing is silently dropped.
    $alt = $this->cards->cardAltText($event->fresh(), null, 'ms', 'UTC');

    expect($alt)->toContain('Kuliah Khas 🎉🎊🥳 Mubarak');
});

it('issues a new revision URL when card content changes', function (): void {
    $event = wp3scEvent();
    $before = $this->cards->cardUrl($event);

    $this->travelTo(now()->addSecond());
    $event->update(['title' => 'Kuliah Subuh Perdana']);
    $after = $this->cards->cardUrl($event->fresh());

    expect($after)->not->toBe($before);

    $this->get((string) $after)->assertOk();
});

it('settles the revision on venue, organizer, and speaker edits', function (): void {
    $event = wp3scEvent();
    $before = $this->cards->cardUrl($event);

    // Related rows never touch the event, so the revision must carry
    // their identity: otherwise venue/organizer/speaker edits would
    // leave stale immutable cards.
    $location = EventLocation::create([
        'event_id' => $event->getKey(),
        'location_role' => 'primary',
        'label' => 'Dewan Utama',
        'visibility' => 'public',
        'status' => 'active',
    ]);

    $withVenue = $this->cards->cardUrl($event->fresh());

    expect($withVenue)->not->toBe($before);

    $venueInput = $this->cards->renderInputFor($event->fresh(), null, 'ms', 'UTC');

    expect($venueInput->venueLabel)->toBe('Dewan Utama');

    $this->travelTo(now()->addSecond());
    $location->update(['label' => 'Dewan Besar']);

    expect($this->cards->cardUrl($event->fresh()))->not->toBe($withVenue);

    $institution = Institution::factory()->create(['status' => 'verified']);
    $event->update(['institution_id' => $institution->getKey()]);
    $withOrganizer = $this->cards->cardUrl($event->fresh());

    expect($withOrganizer)->not->toBe($withVenue);

    $this->travelTo(now()->addSecond());
    $institution->touch();

    expect($this->cards->cardUrl($event->fresh()))->not->toBe($withOrganizer);

    $person = Person::factory()->create(['status' => 'verified']);
    EventKeyPerson::factory()->create([
        'event_id' => $event->getKey(),
        'involveable_type' => 'person',
        'involveable_id' => $person->getKey(),
        'role_code' => EventKeyPersonRole::Speaker->value,
        'visibility' => 'public',
    ]);
    $withSpeaker = $this->cards->cardUrl($event->fresh());

    expect($withSpeaker)->not->toBe($withOrganizer);

    $this->travelTo(now()->addSecond());
    $person->touch();

    expect($this->cards->cardUrl($event->fresh()))->not->toBe($withSpeaker);
});

it('keeps credentials and private state out of card URLs, keys, and pixels', function (): void {
    $event = wp3scEvent();
    $url = (string) $this->cards->cardUrl($event);

    $guest = $this->getJson(route('dawah-share.payload', [
        'url' => route('events.show', $event),
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->json();

    foreach ([$guest['tracking_token'], $guest['guest_credential'], $guest['guest_claim_capability']] as $secret) {
        expect($url)->not->toContain($secret);
    }

    $bytes = (string) $this->get($url)->getContent();

    expect($bytes)->toStartWith("\x89PNG\r\n\x1a\n");

    foreach ([$guest['tracking_token'], $guest['guest_credential'], $guest['guest_claim_capability']] as $secret) {
        expect($bytes)->not->toContain($secret);
    }

    expect(Storage::disk('public')->files('share-cards'))->toHaveCount(1);
});

it('exposes cards in web OG tags, the share modal, and the API payload', function (): void {
    $event = wp3scEvent();
    $cardUrl = (string) $this->cards->cardUrl($event);

    $page = $this->get(route('events.show', $event));
    $page->assertOk();
    $page->assertSee('property="og:image"', false);
    $page->assertSee(e($cardUrl), false);
    $page->assertSee('property="og:image:width" content="1200"', false);
    $page->assertSee('property="og:image:height" content="630"', false);
    $page->assertSee('data-signal-event="share.card_downloaded"', false);
    $page->assertSee(e($cardUrl.'&download=1'), false);

    $occurrence = $event->occurrences()->firstOrFail();
    $occurrencePage = $this->get(route('events.occurrence', [$event, $occurrence->slug]));
    $occurrencePage->assertOk();
    $occurrencePage->assertSee('property="og:image"', false);

    $api = $this->getJson(route('api.events.show', $event));
    $api->assertOk();
    $api->assertJsonPath('data.share_card_url', $cardUrl);
});

it('downloads the card as an attachment with a localized filename', function (): void {
    $event = wp3scEvent();
    $url = (string) $this->cards->cardUrl($event);

    $response = $this->get($url.'&download=1');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');

    $disposition = (string) $response->headers->get('Content-Disposition');

    expect($disposition)->toStartWith('attachment;')
        ->and($disposition)->toContain('.png');
});

it('returns nothing shareable for unreachable events', function (): void {
    $draft = Event::factory()->create([
        'status' => 'draft',
        'visibility' => 'public',
        'starts_at' => now()->addDay(),
    ]);

    expect($this->cards->cardUrl($draft))->toBeNull();

    $this->get(route('share.card', ['event' => $draft->slug]))->assertNotFound();

    $page = $this->get(route('events.index'));
    $page->assertOk();
});

it('uses converted cover art when available', function (): void {
    $event = wp3scEvent();

    $event->addMedia(UploadedFile::fake()->image('cover.jpg', 1600, 900))
        ->toMediaCollection('cover');

    $media = $event->getFirstMedia('cover');

    expect($media)->not->toBeNull();

    $response = $this->get((string) $this->cards->cardUrl($event->fresh()));
    $response->assertOk();

    expect(wp3scPngSize((string) $response->getContent()))->toBe([1200, 630, 'image/png']);
});

it('prunes stale card files without touching fresh ones', function (): void {
    $disk = Storage::disk('public');
    $disk->put('share-cards/share-card-old.png', 'old-bytes');
    $disk->put('share-cards/share-card-new.png', 'new-bytes');
    // Crashed renders leave .tmp-<pid> partials with no .png suffix.
    $disk->put('share-cards/share-card-stale.tmp-99999', 'partial-bytes');
    $disk->put('share-cards/share-card-live.tmp-99998', 'live-bytes');

    $oldPath = $disk->path('share-cards/share-card-old.png');
    touch($oldPath, now()->subDays(31)->getTimestamp());
    touch($disk->path('share-cards/share-card-stale.tmp-99999'), now()->subDays(31)->getTimestamp());

    $this->artisan('app:prune-share-cards', ['--dry-run' => true])->assertOk();

    expect($disk->exists('share-cards/share-card-old.png'))->toBeTrue();

    $this->artisan('app:prune-share-cards')->assertOk();

    expect($disk->exists('share-cards/share-card-old.png'))->toBeFalse()
        ->and($disk->exists('share-cards/share-card-new.png'))->toBeTrue()
        ->and($disk->exists('share-cards/share-card-stale.tmp-99999'))->toBeFalse()
        ->and($disk->exists('share-cards/share-card-live.tmp-99998'))->toBeTrue();
});

it('pre-warms the default card after event writes', function (): void {
    Queue::fake();

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDay(),
    ]);

    EventOccurrence::factory()->create(['event_id' => $event->getKey()]);

    Queue::assertPushed(RenderShareCardJob::class);

    Queue::fake();

    $event->update(['title' => 'Warmed Title']);

    Queue::assertPushed(RenderShareCardJob::class);

    // Unrelated writes do not warm.
    Queue::fake();

    $event->update(['slug' => $event->slug.'-x']);

    Queue::assertNotPushed(RenderShareCardJob::class);
});

it('warms the default card file through the queued job', function (): void {
    $event = wp3scEvent();

    app(RenderShareCardJob::class, ['eventId' => (string) $event->getKey()])->handle($this->cards);

    $files = Storage::disk('public')->files('share-cards');

    expect($files)->toHaveCount(1);

    $bytes = Storage::disk('public')->get($files[0]);

    expect(wp3scPngSize((string) $bytes))->toBe([1200, 630, 'image/png']);
});

it('renders the multilingual proof matrix with stable rasters', function (): void {
    $matrix = [
        'ms' => 'Kuliah Maghrib Perdana',
        'ar' => 'محاضرة الفجر الأسبوعية',
        'zh' => '每周晨礼讲座',
        'ta' => 'வாராந்திர ஃபஜ்ர் சொற்பொழிவு',
        'jv' => 'ꦥꦼꦔꦗꦶꦪꦤ꧀ꦱꦸꦧꦸꦃ',
    ];

    $proofDir = storage_path('app/wp3-card-proofs');

    if (! is_dir($proofDir)) {
        mkdir($proofDir, 0755, true);
    }

    foreach ($matrix as $locale => $title) {
        $event = wp3scEvent(['title' => $title]);
        $url = (string) $this->cards->cardUrl($event, null, $locale);

        $response = $this->get($url);
        $response->assertOk();

        $bytes = (string) $response->getContent();

        expect(wp3scPngSize($bytes))->toBe([1200, 630, 'image/png']);

        file_put_contents($proofDir.'/card-'.$locale.'.png', $bytes);
    }

    $cancelled = wp3scEvent(['title' => 'Kuliah Dibatalkan', 'status' => 'cancelled']);
    $bytes = (string) $this->get((string) $this->cards->cardUrl($cancelled))->getContent();

    expect(wp3scPngSize($bytes))->toBe([1200, 630, 'image/png']);

    file_put_contents($proofDir.'/card-cancelled.png', $bytes);
});

it('settles the revision on live space and venue renames', function (): void {
    $event = wp3scEvent();
    $venue = Venue::factory()->create(['name' => 'Masjid An-Nur']);
    $space = VenueSpace::factory()->create(['venue_id' => $venue->getKey(), 'name' => 'Dewan Utama']);

    $location = EventLocation::create([
        'event_id' => $event->getKey(),
        'location_role' => 'primary',
        'venue_id' => $venue->getKey(),
        'venue_space_id' => $space->getKey(),
        'visibility' => 'public',
        'status' => 'active',
    ]);

    // Force the live fallback path: no explicit label and no stored
    // snapshot, so the presenter reads the space row itself.
    EventLocation::query()->whereKey($location->getKey())->update(['label' => null, 'space_name_snapshot' => null]);

    expect($this->cards->renderInputFor($event->fresh(), null, 'ms', 'UTC')->venueLabel)->toBe('Dewan Utama');

    $before = $this->cards->cardUrl($event->fresh());
    $locationTouchedAt = (string) $location->fresh()->updated_at;

    $this->travelTo(now()->addSecond());
    $space->update(['name' => 'Dewan Besar']);

    // The EventLocation row is untouched; pixels and revision still move.
    expect((string) $location->fresh()->updated_at)->toBe($locationTouchedAt)
        ->and($this->cards->cardUrl($event->fresh()))->not->toBe($before)
        ->and($this->cards->renderInputFor($event->fresh(), null, 'ms', 'UTC')->venueLabel)->toBe('Dewan Besar');

    // Venue fallback: no space at all, the venue name prints live.
    EventLocation::query()->whereKey($location->getKey())->update(['venue_space_id' => null, 'space_name_snapshot' => null]);

    expect($this->cards->renderInputFor($event->fresh(), null, 'ms', 'UTC')->venueLabel)->toBe('Masjid An-Nur');

    $venueBefore = $this->cards->cardUrl($event->fresh());

    $this->travelTo(now()->addSecond());
    $venue->update(['name' => 'Masjid At-Taqwa']);

    expect($this->cards->cardUrl($event->fresh()))->not->toBe($venueBefore)
        ->and($this->cards->renderInputFor($event->fresh(), null, 'ms', 'UTC')->venueLabel)->toBe('Masjid At-Taqwa');
});

it('settles the revision on institution logo replacement', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = wp3scEvent(['institution_id' => $institution->getKey()]);

    $withoutLogo = $this->cards->cardUrl($event);

    $institution->addMedia(UploadedFile::fake()->image('logo.png', 400, 400))->toMediaCollection('logo');

    $withLogo = $this->cards->cardUrl($event->fresh());

    expect($withLogo)->not->toBe($withoutLogo)
        ->and($this->cards->renderInputFor($event->fresh(), null, 'ms', 'UTC')->coverImageBytes)->not->toBeNull();

    $this->travelTo(now()->addSecond());
    $institution->clearMediaCollection('logo');
    $institution->addMedia(UploadedFile::fake()->image('logo-new.png', 400, 400))->toMediaCollection('logo');

    expect($this->cards->cardUrl($event->fresh()))->not->toBe($withLogo);

    $this->get((string) $this->cards->cardUrl($event->fresh()))->assertOk();
});

it('settles the revision on brand changes', function (): void {
    $event = wp3scEvent();
    $before = $this->cards->cardUrl($event);
    $original = (string) config('app.name');

    try {
        config(['app.name' => $original.' Brand Probe']);

        expect($this->cards->cardUrl($event->fresh()))->not->toBe($before);
    } finally {
        config(['app.name' => $original]);
    }
});

it('keeps cache keys coherent between fresh and loaded models', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = wp3scEvent(['institution_id' => $institution->getKey()]);

    EventLocation::create([
        'event_id' => $event->getKey(),
        'location_role' => 'primary',
        'label' => 'Dewan Utama',
        'visibility' => 'public',
        'status' => 'active',
    ]);

    $loaded = $event->fresh();
    $loadedUrl = (string) $this->cards->cardUrl($loaded);
    $timezone = $this->cards->resolveDisplayTimezone($loaded);
    $occurrence = $loaded->primaryOccurrence;

    $freshEvent = Event::query()->whereKey($event->getKey())->firstOrFail();
    $freshOccurrence = EventOccurrence::query()->where('event_id', $event->getKey())->first();

    expect($freshEvent->relationLoaded('locations'))->toBeFalse();

    $freshRevision = $this->cards->contentRevision($freshEvent, $freshOccurrence, 'ms', $timezone);
    $loadedRevision = $this->cards->contentRevision($loaded, $occurrence, 'ms', $timezone);

    expect($freshRevision)->toBe($loadedRevision)
        ->and($loadedUrl)->toContain($loadedRevision);
});

it('falls back honestly when the card cache read fails', function (): void {
    // No prewarm: the request must reach the cache read, not a warmed file.
    Queue::fake();

    $event = wp3scEvent();
    $url = (string) $this->cards->cardUrl($event);

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->andThrow(new RuntimeException('wp3 card cache read probe'));

    Storage::shouldReceive('disk')->with('public')->andReturn($disk);

    try {
        $response = $this->get($url);

        $response->assertRedirect($event->card_image_url);
        expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    } finally {
        Mockery::close();
    }
});

it('falls back honestly when card publishing fails', function (): void {
    // No prewarm: the request must reach the publish write, not a file.
    Queue::fake();

    $event = wp3scEvent();
    $url = (string) $this->cards->cardUrl($event);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $path = $this->cards->cacheFilePath((string) ($query['v'] ?? ''));

    // A file blocking the cache directory: every publish write fails.
    Storage::disk('public')->put('share-cards', 'blocked');

    $response = $this->get($url);

    $response->assertRedirect($event->card_image_url);
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Storage::disk('public')->exists($path))->toBeFalse();

    // No stale partial publication survives the failure.
    Storage::disk('public')->delete('share-cards');

    expect(Storage::disk('public')->exists('share-cards'))->toBeFalse();
});

it('falls back honestly when the card presenter fails', function (): void {
    // No prewarm: the request must reach the presenter, not a warmed file.
    Queue::fake();

    $event = wp3scEvent();
    $url = (string) $this->cards->cardUrl($event);

    app()->bind(ShareCardEventPresenter::class, Wp3ScThrowingPresenter::class);

    $response = $this->get($url);

    $response->assertRedirect($event->card_image_url);
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('falls back honestly when the card renderer fails', function (): void {
    // No prewarm: the request must reach the renderer, not a warmed file.
    Queue::fake();

    $event = wp3scEvent();
    $url = (string) $this->cards->cardUrl($event);

    expect($this->cards->renderer())->toBeInstanceOf(PangoShareCardRenderer::class);

    app()->bind(ShareCardRenderer::class, Wp3ScThrowingRenderer::class);

    $response = $this->get($url);

    $response->assertRedirect($event->card_image_url);
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('renders share-card meta urls escaped exactly once for browser parsing', function (): void {
    $event = wp3scEvent();
    $cardUrl = (string) $this->cards->cardUrl($event);

    expect($cardUrl)->toContain('occurrence=');

    $html = (string) $this->get(route('events.show', $event))->getContent();

    foreach (['og:image', 'og:image:secure_url', 'twitter:image'] as $property) {
        $pattern = '/<meta\s[^>]*?(?:property|name)="'.preg_quote($property, '/').'"[^>]*?content="([^"]*)"/';

        expect(preg_match($pattern, $html, $matches))->toBe(1);

        $content = $matches[1];

        // Exactly-once HTML escaping: separators encoded, never double-encoded.
        expect($content)->toContain('&amp;')
            ->and($content)->not->toContain('&amp;amp;');

        // Browser-equivalent parse: attribute entities decode to real separators.
        $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        expect($decoded)->toBe($cardUrl);

        parse_str((string) parse_url($decoded, PHP_URL_QUERY), $query);

        expect(array_key_exists('v', $query))->toBeTrue()
            ->and(array_key_exists('occurrence', $query))->toBeTrue()
            ->and($query['occurrence'] ?? null)->toBe('kuliah-maghrib-okt-12')
            ->and(array_key_exists('amp;v', $query))->toBeFalse()
            ->and(array_key_exists('amp;occurrence', $query))->toBeFalse();
    }

    expect(preg_match('/<meta\s[^>]*?property="og:url"[^>]*?content="([^"]*)"/', $html, $urlMatches))->toBe(1);

    $ogUrl = html_entity_decode($urlMatches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($ogUrl)->toBe(route('events.show', $event))
        ->and(filter_var($ogUrl, FILTER_VALIDATE_URL))->not->toBeFalse();
});

final readonly class Wp3ScThrowingPresenter extends ShareCardEventPresenter
{
    public function toRenderInput(Event $event, ?EventOccurrence $occurrence, string $locale, string $displayTimezone): ShareCardRenderInput
    {
        throw new RuntimeException('wp3 card presenter failure probe');
    }
}

final class Wp3ScThrowingRenderer implements ShareCardRenderer
{
    public function readiness(): ShareCardReadiness
    {
        throw new RuntimeException('wp3 card renderer failure probe');
    }

    public function render(ShareCardRenderInput $input): ShareCardRenderResult
    {
        throw new RuntimeException('wp3 card renderer failure probe');
    }
}
