<?php

declare(strict_types=1);

namespace App\Features\Events\Support;

use AIArmada\CommerceSupport\Support\UserDateTimeFormatter;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use AIArmada\Events\Models\EventTimeExpression;
use App\Features\Events\Api\PublicScheduleSlug;
use App\Features\Events\Models\Event;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One public majlis: an occurrence with its meaningful sessions attached.
 */
final readonly class PublicScheduleLeaf implements \App\Features\Discovery\Api\PublicScheduleLeaf
{
    /**
     * @param  Collection<int, EventSession>  $sessions
     */
    public function __construct(
        public Event $event,
        public EventOccurrence $occurrence,
        public Collection $sessions = new Collection,
    ) {}

    public function entityType(): string
    {
        return 'occurrence';
    }

    public function id(): string
    {
        return (string) $this->occurrence->getKey();
    }

    public function eventId(): string
    {
        return (string) $this->event->getKey();
    }

    public function eventStatus(): string
    {
        return (string) $this->event->status;
    }

    /**
     * @return list<string>
     */
    public function sessionIds(): array
    {
        return $this->sessions
            ->map(fn (EventSession $session): string => (string) $session->getKey())
            ->values()
            ->all();
    }

    public function event(): Event
    {
        return $this->event;
    }

    public function occurrence(): EventOccurrence
    {
        return $this->occurrence;
    }

    /**
     * @return iterable<int, EventSession>
     */
    public function sessions(): iterable
    {
        return $this->sessions;
    }

    /**
     * @param  list<string>  $sessionIds
     */
    public function withSessions(array $sessionIds): self
    {
        $wanted = array_flip($sessionIds);

        return new self(
            $this->event,
            $this->occurrence,
            $this->sessions
                ->filter(static fn (EventSession $session): bool => isset($wanted[(string) $session->getKey()]))
                ->values(),
        );
    }

    public function title(): string
    {
        return trim((string) (
            $this->occurrence->title
            ?: $this->event->title
        ));
    }

    public function startsAt(): ?CarbonImmutable
    {
        $value = $this->occurrence->getAttribute('starts_at');

        return $value instanceof CarbonInterface ? CarbonImmutable::instance($value) : null;
    }

    public function endsAt(): ?CarbonImmutable
    {
        $value = $this->occurrence->getAttribute('ends_at');

        return $value instanceof CarbonInterface ? CarbonImmutable::instance($value) : null;
    }

    public function timingText(string $format = 'g:i A'): string
    {
        $expressions = $this->scopedTimeExpressions();
        $prayer = $expressions->firstWhere('anchor_type', 'prayer');

        if ($prayer instanceof EventTimeExpression && filled($prayer->display_label)) {
            return __($prayer->display_label);
        }

        if ($expressions->isEmpty() && $this->event->isPrayerRelative()) {
            return (string) $this->event->timing_display;
        }

        $startsAt = $this->startsAt();

        return $startsAt instanceof CarbonInterface ? UserDateTimeFormatter::format($startsAt, $format) : __('TBC');
    }

    public function isPrayerRelative(): bool
    {
        $expressions = $this->scopedTimeExpressions();

        return $expressions->isNotEmpty()
            ? $expressions->contains(fn (EventTimeExpression $expression): bool => $expression->time_mode === 'prayer_relative')
            : $this->event->isPrayerRelative();
    }

    /** @return Collection<int, EventTimeExpression> */
    private function scopedTimeExpressions(): Collection
    {
        return $this->occurrence->timeExpressions->whereNull('event_session_id');
    }

    public function url(): string
    {
        return route('events.occurrence', [
            'event' => $this->event,
            'occurrenceSlug' => PublicScheduleSlug::occurrence(
                slug: (string) $this->occurrence->slug,
                title: $this->occurrence->title,
                id: (string) $this->occurrence->getKey(),
            ),
        ]);
    }
}
