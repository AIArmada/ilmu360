<?php

namespace App\Services;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Contracts\ResolvesEventTimeExpression;
use AIArmada\Events\Models\EventTimeExpression;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Models\Event;
use Carbon\Carbon;
use DateTimeInterface;

readonly class PrayerTimeExpressionResolver implements ResolvesEventTimeExpression
{
    public function __construct(
        private PrayerTimeService $prayerTimeService,
    ) {}

    public function resolve(EventTimeExpression $expression, array $context = []): ?DateTimeInterface
    {
        if ($expression->anchor_type !== 'prayer' || $expression->anchor_code === null) {
            return null;
        }

        $prayerReference = PrayerReference::tryFrom($expression->anchor_code);

        if ($prayerReference === null) {
            return null;
        }

        $event = OwnerContext::withOwner(null, fn () => Event::query()->find($expression->event_id));

        if ($event === null) {
            return null;
        }

        $eventDate = $event->starts_at ?? $expression->resolved_starts_at ?? now();

        $coordinates = $this->resolveCoordinates($event);

        if ($coordinates === null) {
            return null;
        }

        $timezone = $event->timezone ?? 'Asia/Kuala_Lumpur';

        $date = Carbon::parse($eventDate)->setTimezone($timezone);

        $offset = (int) ($expression->offset_minutes ?? 0);

        return $this->prayerTimeService->calculateStartTime(
            $date,
            $prayerReference,
            $offset >= 0
                ? PrayerOffset::After30
                : PrayerOffset::Before30,
            $coordinates['lat'],
            $coordinates['lng'],
            $timezone,
        );
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function resolveCoordinates(Event $event): ?array
    {
        $venue = $event->venue;

        if ($venue === null) {
            return $this->defaultCoordinates();
        }

        $address = $venue->primaryAddress();

        if ($address === null) {
            return $this->defaultCoordinates();
        }

        $lat = $address->latitude;
        $lng = $address->longitude;

        if ($lat === null || $lng === null) {
            return $this->defaultCoordinates();
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    /**
     * @return array{lat: float, lng: float}
     */
    private function defaultCoordinates(): array
    {
        return ['lat' => 3.139, 'lng' => 101.6869]; // Kuala Lumpur
    }
}
