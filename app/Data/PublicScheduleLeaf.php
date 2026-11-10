<?php

declare(strict_types=1);

namespace App\Data;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use AIArmada\Events\Models\EventTimeExpression;
use App\Models\Event;
use App\Platform\Time\UserDateTimeFormatter;
use App\Support\Events\PublicScheduleSlug;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One public majlis: an occurrence with its meaningful sessions attached.
 */
final readonly class PublicScheduleLeaf
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

    public function title(): string
    {
        return trim((string) (
            $this->occurrence->title
            ?: $this->event->title
        ));
    }

    public function startsAt(): ?CarbonInterface
    {
        $value = $this->occurrence->getAttribute('starts_at');

        return $value instanceof CarbonInterface ? $value : null;
    }

    public function endsAt(): ?CarbonInterface
    {
        $value = $this->occurrence->getAttribute('ends_at');

        return $value instanceof CarbonInterface ? $value : null;
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
            'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
        ]);
    }
}
