<?php

namespace App\Observers;

use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Enums\EventPrayerTime;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Models\Address;
use App\Models\Event;
use App\Models\Speaker;
use App\Observers\Concerns\SyncsCurrentAndPreviousValues;
use App\Services\PrayerTimeService;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

class EventObserver
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected GenerateEventSlugAction $generateEventSlugAction,
        protected SyncCanonicalSlugAction $syncCanonicalSlugAction,
        protected SyncSlugRedirectAction $syncSlugRedirectAction,
        protected PrayerTimeService $prayerTimeService,
        protected PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
        protected PublicListingsCache $publicListingsCache
    ) {}

    public function creating(Event $event): void
    {
        $this->calculatePrayerRelativeTime($event);

        if (blank($event->slug)) {
            $speakerSlugSegments = [];

            if ($event->organizer_type === Speaker::class && is_string($event->organizer_id) && $event->organizer_id !== '') {
                $speakerSlugSegments = $this->generateEventSlugAction->speakerSlugSegmentsForSpeakerIds([$event->organizer_id]);
            }

            $event->slug = $this->generateEventSlugAction->handle(
                $event->title,
                $event->starts_at,
                is_string($event->timezone) ? $event->timezone : null,
                (string) $event->getKey(),
                $speakerSlugSegments,
            );
        }
    }

    /**
     * Handle the Event "updating" event.
     */
    public function updating(Event $event): void
    {
        // Only recalculate if timing-related fields changed
        if (
            $event->isDirty([
                'timing_mode',
                'prayer_reference',
                'prayer_offset',
                'venue_id',
            ])
        ) {
            $this->calculatePrayerRelativeTime($event);
        }
    }

    public function created(Event $event): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpForEvent($event);
    }

    public function updated(Event $event): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpForEvent($event);

        if ($event->wasChanged('slug')) {
            /**
             * Direct event write paths can persist the canonical slug before the
             * observer runs, so redirects are synchronized here from the model's
             * previous persisted slug snapshot.
             */
            $this->syncCanonicalSlugAction->syncChanged(
                $event,
                $event->getPrevious()['slug'] ?? null,
            );
        }

        if ($event->wasChanged(['title', 'starts_at', 'timezone', 'organizer_type', 'organizer_id'])) {
            $this->syncCurrentAndPreviousString(
                $event->title,
                $event->getPrevious()['title'] ?? null,
                fn (string $title): bool => $this->generateEventSlugAction->syncEventSlugsForTitle($title),
            );
        }

        if ($event->searchIndexShouldBeUpdated()) {
            if ($event->shouldBeSearchable()) {
                $event->searchable();

                return;
            }

            $event->unsearchable();
        }
    }

    public function deleted(Event $event): void
    {
        $this->syncSlugRedirectAction->purgeForModel($event);
        $this->generateEventSlugAction->syncEventSlugsForTitle($event->title);
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpForEvent($event);
    }

    /**
     * Calculate and set the starts_at time for prayer-relative events.
     */
    protected function calculatePrayerRelativeTime(Event $event): void
    {
        // Only process prayer-relative events
        if ($event->timing_mode !== TimingMode::PrayerRelative) {
            return;
        }

        // Ensure we have prayer reference and offset
        $prayerReference = $event->prayer_reference;
        $prayerOffset = $event->prayer_offset;

        if (! $prayerReference instanceof PrayerReference || ! $prayerOffset instanceof PrayerOffset) {
            Log::warning('Prayer-relative event missing prayer_reference or prayer_offset', [
                'event_id' => $event->id,
            ]);

            return;
        }

        // Get coordinates for prayer time calculation
        $coords = $this->getCoordinates($event);

        if ($coords === null) {
            Log::warning('Cannot calculate prayer time: no coordinates available', [
                'event_id' => $event->id,
            ]);

            return;
        }

        // Determine the event date in event timezone from the current starts_at payload first, then fallback to now.
        $eventTimezone = $event->timezone ?? 'Asia/Kuala_Lumpur';
        $startsAt = $event->starts_at;
        $rawStartsAt = $event->getAttributes()['starts_at'] ?? null;

        if ($startsAt instanceof \DateTimeInterface) {
            $eventDate = Carbon::instance($startsAt)->setTimezone($eventTimezone);
        } elseif (is_string($startsAt) && trim($startsAt) !== '') {
            $eventDate = Carbon::parse($startsAt, $eventTimezone);
        } elseif (is_string($rawStartsAt) && trim($rawStartsAt) !== '') {
            $eventDate = Carbon::parse($rawStartsAt, $eventTimezone);
        } else {
            $eventDate = Carbon::now($eventTimezone);
        }

        // Calculate the actual start time
        $calculatedTime = $this->prayerTimeService->calculateStartTime(
            $eventDate,
            $prayerReference,
            $prayerOffset,
            $coords['lat'],
            $coords['lng'],
            $eventTimezone
        );

        if ($calculatedTime instanceof CarbonInterface) {
            // Persist starts_at in UTC for storage consistency.
            $event->starts_at = \Illuminate\Support\Carbon::instance($calculatedTime)->utc();

            // Update display text if not already set
            if (empty($event->prayer_display_text)) {
                $event->prayer_display_text = EventPrayerTime::fromPrayerTiming($prayerReference, $prayerOffset)?->getLabel()
                    ?? $prayerOffset->displayText($prayerReference);
            }

            Log::info('Calculated prayer-relative start time', [
                'event_id' => $event->id,
                'prayer' => $prayerReference->value,
                'offset' => $prayerOffset->value,
                'calculated_time' => $calculatedTime->toIso8601String(),
            ]);
        } else {
            Log::warning('Failed to calculate prayer-relative start time', [
                'event_id' => $event->id,
                'prayer' => $prayerReference->value,
            ]);
        }
    }

    /**
     * Get coordinates for prayer time calculation.
     *
     * @return array{lat: float, lng: float}|null
     */
    protected function getCoordinates(Event $event): ?array
    {
        // Load venue if not loaded (with address)
        if ($event->venue_id && ! $event->relationLoaded('venue')) {
            $event->load('venue.address');
        }

        $venueAddress = $event->venue?->addressModel;

        if ($venueAddress instanceof Address
            && $venueAddress->lat !== null
            && $venueAddress->lng !== null) {
            return [
                'lat' => (float) $venueAddress->lat,
                'lng' => (float) $venueAddress->lng,
            ];
        }

        // Default to Kuala Lumpur coordinates if nothing else available
        return [
            'lat' => 3.1390,
            'lng' => 101.6869,
        ];
    }
}
