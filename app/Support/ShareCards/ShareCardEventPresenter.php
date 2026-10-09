<?php

declare(strict_types=1);

namespace App\Support\ShareCards;

use AIArmada\Events\Models\EventOccurrence;
use App\Contracts\ShareCards\ShareCardRenderInput;
use App\Enums\EventChangeType;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\InstitutionStatus;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Support\Events\EventDetailPresenter;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Canonical event facts for share-card rendering.
 *
 * Assembles the leaf renderer's explicit input from current public event
 * state: verbatim title, occurrence-aware schedule in the published
 * display timezone, venue/speaker labels, verified organizer/speaker
 * state, and cancellation/change labels. No translation lookup happens
 * for user content (titles render verbatim in any supported script);
 * chrome copy uses the card locale through the standard translator.
 *
 * Relations are read from loaded state only and degrade to omission,
 * never to a second query path with different semantics: the service
 * eager-loads the canonical relation set before calling here.
 *
 * Non-final so the card fallback boundary can be proven with a throwing
 * test double; production wiring always uses this class.
 */
readonly class ShareCardEventPresenter
{
    public function toRenderInput(
        Event $event,
        ?EventOccurrence $occurrence,
        string $locale,
        string $displayTimezone,
    ): ShareCardRenderInput {
        $detail = new EventDetailPresenter($event, $occurrence);
        $scope = $occurrence ?? $event;

        return new ShareCardRenderInput(
            title: (string) $event->title,
            locale: $locale,
            brandLine: $this->brandLine(),
            scheduleLabel: $this->scheduleLabel($event, $occurrence, $displayTimezone),
            venueLabel: $detail->locationLabel($detail->primaryLocationFor($scope)),
            speakerLabel: $this->speakerLabel($event),
            verifiedLabel: $this->verifiedLabel($event),
            statusLabel: $this->statusLabel($event, $occurrence),
            coverImageBytes: $this->coverImageBytes($event),
        );
    }

    public function scheduleLabel(Event $event, ?EventOccurrence $occurrence, string $displayTimezone): string
    {
        $start = $occurrence instanceof EventOccurrence ? $occurrence->starts_at : $event->starts_at;
        $end = $occurrence instanceof EventOccurrence ? $occurrence->ends_at : $event->ends_at;

        $prayerLabel = $event->isPrayerRelative() ? $this->prayerLabel($event) : null;

        if (! $start instanceof CarbonInterface) {
            return $prayerLabel ?? __('Time to be confirmed');
        }

        // Compact single-strip composition: the weekday is dropped so the
        // printed display-timezone abbreviation always survives. The middle
        // (clock time or prayer label) yields to a fixed tail budget
        // instead of pushing the zone off the strip.
        $date = UserDateTimeFormatter::translatedFormat($start, 'j M Y', null, $displayTimezone);
        $abbreviation = $start->copy()->timezone($displayTimezone)->format('T');
        $tail = "({$abbreviation})";

        if ($prayerLabel !== null) {
            $middle = $prayerLabel;

            if ($end instanceof CarbonInterface && $end->getTimestamp() !== $start->getTimestamp()) {
                $middle .= ' — '.UserDateTimeFormatter::format($end, 'g:i A', null, $displayTimezone);
            }
        } else {
            $middle = $this->timeRange($start, $end, $displayTimezone);
        }

        return trim($date.' · '.$this->fitMiddle($middle, $date, $tail).' '.$tail);
    }

    private function prayerLabel(Event $event): ?string
    {
        // Canonical prayer presentation, mirroring the event timing
        // display: explicit custom text wins, else the generic
        // reference/offset label. Unlike the screen display, this never
        // falls back to a viewer-timezone clock time: an unresolvable
        // prayer configuration yields no prayer label.
        if (! $event->isPrayerRelative()) {
            return null;
        }

        if (is_string($event->prayer_display_text) && trim($event->prayer_display_text) !== '') {
            return __($event->prayer_display_text);
        }

        $prayerReference = $event->prayer_reference instanceof PrayerReference
            ? $event->prayer_reference
            : PrayerReference::tryFrom((string) $event->prayer_reference);
        $prayerOffset = $event->prayer_offset instanceof PrayerOffset
            ? $event->prayer_offset
            : PrayerOffset::tryFrom((string) $event->prayer_offset);
        $prayerTime = EventPrayerTime::fromPrayerTiming($prayerReference, $prayerOffset);

        return $prayerTime instanceof EventPrayerTime ? $prayerTime->getLabel() : null;
    }

    private function timeRange(CarbonInterface $start, mixed $end, string $displayTimezone): string
    {
        $startTime = UserDateTimeFormatter::format($start, 'g:i A', null, $displayTimezone);

        if (! $end instanceof CarbonInterface || $end->getTimestamp() === $start->getTimestamp()) {
            return $startTime;
        }

        $sameDay = $end->copy()->timezone($displayTimezone)->format('Y-m-d')
            === $start->copy()->timezone($displayTimezone)->format('Y-m-d');

        if (! $sameDay) {
            $endDate = UserDateTimeFormatter::translatedFormat($end, 'j M', null, $displayTimezone);

            return "{$startTime} — {$endDate}";
        }

        // Shared meridiem collapses: 8:00–10:00 PM. Split halves keep
        // both markers so the range never misreads.
        $startMeridiem = UserDateTimeFormatter::format($start, 'A', null, $displayTimezone);
        $endMeridiem = UserDateTimeFormatter::format($end, 'A', null, $displayTimezone);

        if ($startMeridiem === $endMeridiem) {
            $startShort = UserDateTimeFormatter::format($start, 'g:i', null, $displayTimezone);
            $endShort = UserDateTimeFormatter::format($end, 'g:i', null, $displayTimezone);

            return "{$startShort}–{$endShort} {$startMeridiem}";
        }

        $endTime = UserDateTimeFormatter::format($end, 'g:i A', null, $displayTimezone);

        return "{$startTime}–{$endTime}";
    }

    private function fitMiddle(string $middle, string $date, string $tail): string
    {
        // Strip budget in characters: date + separators + middle + zone.
        // Only the middle yields; date and zone are never cut.
        $budget = 40 - mb_strlen($date) - mb_strlen($tail) - 4;

        if ($budget < 8) {
            $budget = 8;
        }

        if (mb_strlen($middle) <= $budget) {
            return $middle;
        }

        return mb_substr($middle, 0, $budget - 1).'…';
    }

    private function brandLine(): string
    {
        return __('Majlis Ilmu').' · '.(string) config('app.name');
    }

    private function speakerLabel(Event $event): ?string
    {
        if (! $event->relationLoaded('keyPeople')) {
            return null;
        }

        $speaker = $event->keyPeople
            ->filter(static fn (EventKeyPerson $keyPerson): bool => $keyPerson->role_code === EventKeyPersonRole::Speaker->value
                && (string) $keyPerson->visibility === 'public')
            ->first();

        if (! $speaker instanceof EventKeyPerson) {
            return null;
        }

        $person = $speaker->relationLoaded('person') ? $speaker->person : null;
        $name = $person !== null ? (string) ($person->formatted_name ?? '') : '';

        return trim($name) !== '' ? trim($name) : null;
    }

    private function verifiedLabel(Event $event): ?string
    {
        $institution = $event->relationLoaded('institution') ? $event->institution : null;

        if ($institution !== null && $institution->status === InstitutionStatus::Verified) {
            return __('Verified organiser');
        }

        if ($event->relationLoaded('keyPeople')) {
            $verifiedSpeaker = $event->keyPeople
                ->filter(static fn (EventKeyPerson $keyPerson): bool => $keyPerson->role_code === EventKeyPersonRole::Speaker->value
                    && (string) $keyPerson->visibility === 'public')
                ->first(static fn (EventKeyPerson $keyPerson): bool => $keyPerson->relationLoaded('person')
                    && $keyPerson->person !== null
                    && (string) $keyPerson->person->status === 'verified');

            if ($verifiedSpeaker instanceof EventKeyPerson) {
                return __('Verified speaker');
            }
        }

        return null;
    }

    private function statusLabel(Event $event, ?EventOccurrence $occurrence): ?string
    {
        if ($occurrence instanceof EventOccurrence) {
            $occurrenceLabel = match ((string) $occurrence->status) {
                'cancelled' => EventChangeType::Cancelled->publicBadgeLabel(),
                'postponed' => EventChangeType::Postponed->publicBadgeLabel(),
                'rescheduled', 'delayed' => EventChangeType::ScheduleChanged->publicBadgeLabel(),
                default => null,
            };

            if (is_string($occurrenceLabel)) {
                return $occurrenceLabel;
            }
        }

        $eventLabel = $event->public_change_badge_label;

        return is_string($eventLabel) && trim($eventLabel) !== '' ? $eventLabel : null;
    }

    private function coverImageBytes(Event $event): ?string
    {
        // Mirror the canonical card-image priority (cover, poster,
        // institution logo) using converted renditions. Oversized art is
        // omitted so the raster budget can never sink the whole card.
        $candidates = [
            [$event->getFirstMedia('cover'), 'card'],
            [$event->getFirstMedia('poster'), 'poster_thumb'],
        ];

        $institution = $event->relationLoaded('institution') ? $event->institution : null;

        if ($institution !== null && method_exists($institution, 'getFirstMedia')) {
            $candidates[] = [$institution->getFirstMedia('logo'), 'thumb'];
        }

        foreach ($candidates as [$media, $conversion]) {
            $bytes = $this->conversionBytes($media, $conversion);

            if (is_string($bytes)) {
                return $bytes;
            }
        }

        return null;
    }

    private function conversionBytes(mixed $media, string $conversion): ?string
    {
        if (! $media instanceof Media) {
            return null;
        }

        try {
            $path = $media->getPath($conversion);
        } catch (\Throwable) {
            return null;
        }

        if (! is_file($path)) {
            return null;
        }

        $size = filesize($path);

        if ($size === false || $size <= 0 || $size > 1500000) {
            return null;
        }

        $bytes = file_get_contents($path);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }
}
