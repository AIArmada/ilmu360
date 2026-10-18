<?php

declare(strict_types=1);

namespace App\Services\ShareCards;

use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use App\Contracts\ShareCards\ShareCardError;
use App\Contracts\ShareCards\ShareCardRenderer;
use App\Contracts\ShareCards\ShareCardRenderInput;
use App\Contracts\ShareCards\ShareCardRenderResult;
use App\Models\Event;
use App\Support\ShareCards\PangoShareCardRenderer;
use App\Support\ShareCards\ShareCardEventPresenter;
use App\Support\ShareCards\ShareCardFonts;
use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Public share-card orchestration: canonical URLs, revision-pinned file
 * cache, bounded rendering, and honest cover fallback.
 *
 * Cards are shared-cache public artifacts. Cache keys carry only public
 * dimensions (subject revision + occurrence + locale + public display
 * timezone + renderer/font revisions); per-sharer tokens, credentials,
 * visitor state, and private counts can never enter a key, a URL, or a
 * pixel. Any render failure, including an unsupported title script,
 * falls back to the existing cover image — never a silent omission.
 */
final readonly class ShareCardService
{
    public const string CACHE_VERSION = 'v1';

    public const string DEFAULT_LOCALE = 'ms';

    public function __construct(
        private ShareCardEventPresenter $presenter,
    ) {}

    /**
     * Canonical public card URL for an event, or null when the event is
     * not publicly reachable. The revision query pins the current
     * content; callers always emit this URL verbatim.
     */
    public function cardUrl(Event $event, ?string $occurrenceKey = null, ?string $locale = null): ?string
    {
        if (! $event->isPubliclyReachable()) {
            return null;
        }

        $resolvedLocale = $this->normalizeLocale($locale);
        $occurrence = $this->resolveOccurrence($event, $occurrenceKey);

        if (is_string($occurrenceKey) && $occurrenceKey !== '' && ! $occurrence instanceof EventOccurrence) {
            return null;
        }

        $this->loadCardRelations($event, $occurrence);
        $displayTimezone = $this->resolveDisplayTimezone($event);

        $query = [
            'locale' => $resolvedLocale,
            'v' => $this->contentRevision($event, $occurrence, $resolvedLocale, $displayTimezone),
        ];

        if ($occurrence instanceof EventOccurrence) {
            $query['occurrence'] = $this->occurrenceKey($occurrence);
        }

        return route('share.card', ['event' => $event->slug]).'?'.http_build_query($query);
    }

    /**
     * Open-graph card bundle for a view: generated card URL plus its alt
     * text, or nulls when the event is not publicly reachable. Callers
     * fall back to the cover image and poster alt on nulls.
     *
     * @return array{url: string|null, alt: string|null}
     */
    public function ogCard(Event $event, ?string $occurrenceKey = null, ?string $locale = null): array
    {
        $url = $this->cardUrl($event, $occurrenceKey, $locale);

        if (! is_string($url)) {
            return ['url' => null, 'alt' => null];
        }

        $resolvedLocale = $this->normalizeLocale($locale);
        $occurrence = $this->resolveOccurrence($event, $occurrenceKey);

        return [
            'url' => $url,
            'alt' => $this->cardAltText($event, $occurrence, $resolvedLocale, $this->resolveDisplayTimezone($event)),
        ];
    }

    /**
     * Localized alt text for a card: title plus schedule, venue, and
     * status. The full title always survives here even when pixels fall
     * back to cover art.
     */
    public function cardAltText(Event $event, ?EventOccurrence $occurrence, string $locale, string $displayTimezone): string
    {
        $this->loadCardRelations($event, $occurrence);

        return $this->runInLocale($locale, function () use ($event, $occurrence, $locale, $displayTimezone): string {
            $parts = [(string) $event->title, $this->presenter->scheduleLabel($event, $occurrence, $displayTimezone)];
            $input = $this->presenter->toRenderInput($event, $occurrence, $locale, $displayTimezone);

            foreach ([$input->venueLabel, $input->speakerLabel, $input->verifiedLabel, $input->statusLabel] as $label) {
                if (is_string($label) && trim($label) !== '') {
                    $parts[] = trim($label);
                }
            }

            return implode(' · ', $parts);
        });
    }

    public function normalizeLocale(?string $locale): string
    {
        $candidate = strtolower(trim((string) $locale));

        if ($candidate !== '' && ShareCardFonts::isSupportedLocale($candidate)) {
            return $candidate;
        }

        $appLocale = strtolower((string) App::getLocale());

        if (ShareCardFonts::isSupportedLocale($appLocale)) {
            return $appLocale;
        }

        return self::DEFAULT_LOCALE;
    }

    public function isSupportedLocale(?string $locale): bool
    {
        return is_string($locale) && ShareCardFonts::isSupportedLocale(strtolower(trim($locale)));
    }

    /**
     * Resolve the intended occurrence: an explicit id-or-slug key wins,
     * else the event's primary occurrence. A null return with an explicit
     * key means the caller asked for an occurrence that does not belong
     * to this event.
     */
    public function resolveOccurrence(Event $event, ?string $occurrenceKey): ?EventOccurrence
    {
        $key = trim((string) $occurrenceKey);

        if ($key === '') {
            return $event->primaryOccurrence;
        }

        // Occurrence ids are UUIDs: only a well-formed UUID may reach the
        // key lookup, so a malformed key 404s honestly on every driver
        // instead of raising a uuid syntax error on PostgreSQL.
        if (Str::isUuid($key)) {
            $occurrence = $event->occurrences()
                ->whereKey($key)
                ->first();

            if ($occurrence instanceof EventOccurrence) {
                return $occurrence;
            }
        }

        return $event->occurrences()->where('slug', $key)->first();
    }

    /**
     * Published display timezone for card pixels: the event's own
     * timezone when valid, else the configured default viewer timezone.
     * Never a per-request viewer value: cached cards cannot vary by
     * cookie, and the zone is printed on the card itself.
     */
    public function resolveDisplayTimezone(Event $event): string
    {
        $candidate = is_string($event->timezone) ? trim($event->timezone) : '';

        if ($candidate !== '' && $this->isValidTimezone($candidate)) {
            return $candidate;
        }

        $fallback = (string) config('app.default_user_timezone', 'UTC');

        return $fallback !== '' && $this->isValidTimezone($fallback) ? $fallback : 'UTC';
    }

    /**
     * Content revision for cache keys and the revision URL pin. Covers
     * every pixel input: event/occurrence identity and lifecycles,
     * schedule and prayer facts, change announcements, venue labels with
     * their live space/venue fallbacks, and artwork including the
     * institution logo. Renderer, font, and brand revisions ride the key,
     * never viewer state.
     */
    public function contentRevision(Event $event, ?EventOccurrence $occurrence, string $locale, string $displayTimezone): string
    {
        $this->loadCardRelations($event, $occurrence);

        $cover = $event->getFirstMedia('cover');
        $poster = $event->getFirstMedia('poster');
        $announcement = $event->latestPublishedChangeAnnouncement;
        $institution = $event->relationLoaded('institution') ? $event->institution : null;
        $logo = $institution !== null && method_exists($institution, 'getFirstMedia')
            ? $institution->getFirstMedia('logo')
            : null;

        $parts = [
            self::CACHE_VERSION,
            'event', (string) $event->getKey(),
            $this->instantKey($event->updated_at),
            (string) $event->status,
            $this->instantKey($event->published_at),
            $this->instantKey($event->cancelled_at ?? null),
            (string) ($event->title ?? ''),
            $this->instantKey($event->starts_at),
            $this->instantKey($event->ends_at),
            (string) ($event->prayer_reference ?? ''),
            (string) ($event->prayer_offset ?? ''),
            (string) ($event->prayer_display_text ?? ''),
            $occurrence instanceof EventOccurrence ? (string) $occurrence->getKey() : 'none',
            $occurrence instanceof EventOccurrence ? $this->instantKey($occurrence->updated_at) : 'none',
            $occurrence instanceof EventOccurrence ? (string) $occurrence->status : 'none',
            $occurrence instanceof EventOccurrence ? $this->instantKey($occurrence->starts_at) : 'none',
            $occurrence instanceof EventOccurrence ? $this->instantKey($occurrence->ends_at) : 'none',
            $announcement !== null ? (string) $announcement->getKey() : 'none',
            $announcement !== null ? $this->instantKey($announcement->updated_at) : 'none',
            $announcement !== null
                ? ($announcement->update_type instanceof BackedEnum
                    ? (string) $announcement->update_type->value
                    : (string) ($announcement->update_type ?? ''))
                : 'none',
            $cover !== null ? (string) $cover->getKey().'/'.$this->instantKey($cover->updated_at) : 'none',
            $poster !== null ? (string) $poster->getKey().'/'.$this->instantKey($poster->updated_at) : 'none',
            // Institution-logo identity through the same accessor the
            // presenter reads: id and timestamp only, never raster bytes,
            // so listing URL builds stay cheap while a logo replacement
            // still settles the revision.
            'logo', $logo !== null ? (string) $logo->getKey().'/'.$this->instantKey($logo->updated_at) : 'none',
            // Venue, organizer, speaker, and timing-expression state feed
            // pixels (and the prewarm observer fires on them), so their
            // identity settles the revision too. Loaded state only, same
            // as the presenter reads: revision and pixels stay coherent.
            // Locations fingerprint at location level — label, snapshot,
            // and the live venueSpace/venue fallbacks the presenter can
            // read — so a space or venue rename without an EventLocation
            // touch still settles the revision.
            'locations', $this->locationsFingerprint($event->relationLoaded('locations') ? $event->getRelation('locations') : null),
            'primaryLocation', $this->primaryLocationFingerprint($event),
            'institution', $this->relationFingerprint($event, 'institution'),
            'keyPeople', $this->relationFingerprint($event, 'keyPeople'),
            'keyPersons', $this->keyPersonPersonFingerprint($event),
            'timeExpressions', $this->relationFingerprint($event, 'timeExpressions'),
            'occurrence-locations', $occurrence instanceof EventOccurrence && $occurrence->relationLoaded('locations')
                ? $this->locationsFingerprint($occurrence->getRelation('locations'))
                : 'none',
            'locale', $locale,
            'tz', $displayTimezone,
            // Brand rule: the printed brand line is the translated brand
            // word in the card locale plus the configured application name.
            // Both ride the key, so a rebrand or catalog change settles the
            // revision instead of leaving stale immutable pixels.
            'brand', (string) config('app.name').'@'.$this->runInLocale($locale, static fn (): string => (string) __('Majlis Ilmu')),
            'renderer', PangoShareCardRenderer::RENDERER_REVISION,
            'fonts', $this->fontManifestRevision(),
        ];

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /**
     * @return array{bytes: string|null, revision: string, cached: bool}
     */
    public function renderForRequest(Event $event, ?EventOccurrence $occurrence, string $locale, string $displayTimezone): array
    {
        $this->loadCardRelations($event, $occurrence);

        $revision = $this->contentRevision($event, $occurrence, $locale, $displayTimezone);
        $path = $this->cacheFilePath($revision);
        $disk = Storage::disk((string) config('share-cards.disk', 'public'));

        try {
            if ($disk->exists($path)) {
                $cached = $disk->get($path);

                if (is_string($cached) && $cached !== '' && str_starts_with($cached, "\x89PNG\r\n\x1a\n")) {
                    return ['bytes' => $cached, 'revision' => $revision, 'cached' => true];
                }

                $disk->delete($path);
            }

            $result = $this->render($event, $occurrence, $locale, $displayTimezone);

            if (! $result->ok || ! is_string($result->pngBytes) || $result->pngBytes === '') {
                return ['bytes' => null, 'revision' => $revision, 'cached' => false];
            }

            // Atomic publish: revision-pinned readers never observe a
            // partial file, and concurrent renders converge on identical
            // bytes for the same revision.
            $tmpPath = $path.'.tmp-'.getmypid();
            $disk->put($tmpPath, $result->pngBytes);
            $disk->move($tmpPath, $path);

            return ['bytes' => $result->pngBytes, 'revision' => $revision, 'cached' => false];
        } catch (Throwable) {
            // Bounded operational failure: cache, storage, presenter, and
            // render faults resolve to the honest cover fallback at the
            // controller — never a 500, never a false cache success, and
            // never a stale partial publication. Source eligibility
            // (reachability, occurrence resolution, revision) resolves
            // above, outside this boundary, and still surfaces honestly.
            try {
                $disk->delete($path.'.tmp-'.getmypid());
            } catch (Throwable) {
                // Best-effort partial cleanup; the fallback below stands.
            }

            return ['bytes' => null, 'revision' => $revision, 'cached' => false];
        }
    }

    public function renderInputFor(Event $event, ?EventOccurrence $occurrence, string $locale, string $displayTimezone): ShareCardRenderInput
    {
        $this->loadCardRelations($event, $occurrence);

        return $this->runInLocale(
            $locale,
            fn (): ShareCardRenderInput => $this->presenter->toRenderInput($event, $occurrence, $locale, $displayTimezone)
        );
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function runInLocale(string $locale, callable $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale($locale);

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }

    public function cacheFilePath(string $revision): string
    {
        return 'share-cards/share-card-'.$revision.'.png';
    }

    public function coverFallbackUrl(Event $event): string
    {
        return $event->card_image_url;
    }

    public function downloadFilename(Event $event): string
    {
        $slug = trim((string) Str::slug((string) $event->title));

        if ($slug === '') {
            $slug = 'majlis';
        }

        return substr($slug, 0, 100).'-kad.png';
    }

    public function renderer(): ShareCardRenderer
    {
        // Container seam for the operational boundary proof: production
        // resolves the pinned Pango renderer; a bound contract double
        // (tests only) may stand in to prove failure handling.
        if (app()->bound(ShareCardRenderer::class)) {
            return app(ShareCardRenderer::class);
        }

        return new PangoShareCardRenderer(public_path('fonts/share-cards'));
    }

    private function render(Event $event, ?EventOccurrence $occurrence, string $locale, string $displayTimezone): ShareCardRenderResult
    {
        try {
            $input = $this->renderInputFor($event, $occurrence, $locale, $displayTimezone);

            return $this->renderer()->render($input);
        } catch (Throwable) {
            return ShareCardRenderResult::failure(
                ShareCardError::RenderFailed,
                'Render failed unexpectedly.',
                PangoShareCardRenderer::WIDTH,
                PangoShareCardRenderer::HEIGHT,
                PangoShareCardRenderer::RENDERER_REVISION,
                'unverified',
            );
        }
    }

    private function occurrenceKey(EventOccurrence $occurrence): string
    {
        $slug = is_string($occurrence->slug) ? trim($occurrence->slug) : '';

        return $slug !== '' ? $slug : (string) $occurrence->getKey();
    }

    private function fontManifestRevision(): string
    {
        // Cheap portable pin: the manifest records every font's sha256, so
        // any font change that keeps verification green also changes these
        // bytes. Full verification stays in readiness(), never per render.
        $manifest = public_path('fonts/share-cards/'.ShareCardFonts::MANIFEST_FILE);
        $bytes = is_file($manifest) ? file_get_contents($manifest) : false;

        return is_string($bytes) && $bytes !== '' ? substr(hash('sha256', $bytes), 0, 16) : 'missing';
    }

    private function instantKey(mixed $instant): string
    {
        if ($instant instanceof CarbonInterface) {
            return $instant->copy()->utc()->format('Y-m-d\TH:i:s.uP');
        }

        return 'none';
    }

    /**
     * Deterministic identity fingerprint for a loaded relation: sorted
     * key/update pairs, so membership, order-independent identity, and
     * edits all settle the revision. Unloaded relations fingerprint as
     * unloaded rather than querying with different semantics.
     */
    private function relationFingerprint(Model $model, string $relation): string
    {
        if (! $model->relationLoaded($relation)) {
            return 'unloaded';
        }

        return $this->fingerprintModels($model->getRelation($relation));
    }

    /**
     * Location-level fingerprint for every pixel input the presenter can
     * read from a location: identity and lifecycle, the explicit label,
     * the space snapshot, and the live venueSpace/venue fallbacks behind
     * their foreign keys. Loaded state only, same as the presenter reads.
     */
    private function locationsFingerprint(mixed $related): string
    {
        if ($related === null) {
            return 'unloaded';
        }

        $locations = $related instanceof Collection ? $related->all() : [$related];
        $parts = [];

        foreach ($locations as $location) {
            if ($location instanceof EventLocation) {
                $parts[] = $this->locationFingerprint($location);
            }
        }

        sort($parts);

        return $parts === [] ? 'none' : implode(',', $parts);
    }

    private function primaryLocationFingerprint(Event $event): string
    {
        if (! $event->relationLoaded('primaryLocation')) {
            return 'unloaded';
        }

        $primary = $event->getRelation('primaryLocation');

        return $primary instanceof EventLocation ? $this->locationFingerprint($primary) : 'none';
    }

    private function locationFingerprint(EventLocation $location): string
    {
        $space = $location->relationLoaded('venueSpace') ? $location->venueSpace : null;
        $venue = $location->relationLoaded('venue') ? $location->venue : null;

        $parts = [
            (string) $location->getKey(),
            $this->instantKey($location->getAttribute('updated_at')),
            (string) ($location->label ?? ''),
            (string) ($location->space_name_snapshot ?? ''),
            (string) ($location->venue_space_id ?? ''),
            $space instanceof Model
                ? (string) $space->getKey().'@'.$this->instantKey($space->getAttribute('updated_at')).'@'.(string) ($space->getAttribute('name') ?? '')
                : 'space-none',
            (string) ($location->venue_id ?? ''),
            $venue instanceof Model
                ? (string) $venue->getKey().'@'.$this->instantKey($venue->getAttribute('updated_at')).'@'.(string) ($venue->getAttribute('name') ?? '')
                : 'venue-none',
        ];

        return implode('~', $parts);
    }

    private function keyPersonPersonFingerprint(Event $event): string
    {
        if (! $event->relationLoaded('keyPeople')) {
            return 'unloaded';
        }

        $keyPeople = $event->getRelation('keyPeople');

        if (! $keyPeople instanceof Collection) {
            return 'none';
        }

        $persons = $keyPeople
            ->map(static fn (mixed $keyPerson): mixed => $keyPerson instanceof Model && $keyPerson->relationLoaded('person')
                ? $keyPerson->getRelation('person')
                : null)
            ->filter();

        return $this->fingerprintModels($persons);
    }

    private function fingerprintModels(mixed $related): string
    {
        $models = $related instanceof Collection ? $related->all() : [$related];
        $parts = [];

        foreach ($models as $model) {
            if (! $model instanceof Model) {
                continue;
            }

            $parts[] = (string) $model->getKey().'@'.$this->instantKey($model->getAttribute('updated_at'));
        }

        sort($parts);

        return $parts === [] ? 'none' : implode(',', $parts);
    }

    private function isValidTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function loadCardRelations(Event $event, ?EventOccurrence $occurrence): void
    {
        // The canonical relation set for BOTH revision and rendering: the
        // same loaded state feeds the fingerprint and the presenter, so
        // revision and pixels can never disagree. Venue fallbacks ride
        // along because the venue label can read them live.
        $event->loadMissing([
            'institution',
            'keyPeople.person',
            'locations',
            'locations.venueSpace',
            'locations.venue',
            'primaryLocation',
            'primaryLocation.venueSpace',
            'primaryLocation.venue',
            'timeExpressions',
            'latestPublishedChangeAnnouncement',
            'primaryOccurrence',
            'displayOccurrence.media',
        ]);

        if ($occurrence instanceof EventOccurrence) {
            $occurrence->loadMissing([
                'locations' => static fn (Relation $query): Relation => $query->whereNull('event_session_id'),
                'locations.venueSpace',
                'locations.venue',
            ]);
        }
    }
}
