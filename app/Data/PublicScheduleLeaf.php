<?php

declare(strict_types=1);

namespace App\Data;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use AIArmada\Events\Models\EventTimeExpression;
use App\Models\Event;
use App\Support\Events\PublicScheduleSlug;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class PublicScheduleLeaf
{
    public function __construct(
        public Event $event,
        public EventOccurrence $occurrence,
        public ?EventSession $session = null,
    ) {}

    public function isSession(): bool
    {
        return $this->session instanceof EventSession;
    }

    public function entityType(): string
    {
        return $this->isSession() ? 'session' : 'occurrence';
    }

    public function id(): string
    {
        return (string) ($this->session?->getKey() ?? $this->occurrence->getKey());
    }

    public function title(): string
    {
        return trim((string) (
            $this->session?->title
            ?: $this->occurrence->title
            ?: $this->event->title
        ));
    }

    public function startsAt(): ?CarbonInterface
    {
        $value = $this->session?->getAttribute('starts_at') ?? $this->occurrence->getAttribute('starts_at');

        return $value instanceof CarbonInterface ? $value : null;
    }

    public function endsAt(): ?CarbonInterface
    {
        $value = $this->session?->getAttribute('ends_at') ?? $this->occurrence->getAttribute('ends_at');

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
        $sessionExpressions = $this->session?->timeExpressions;

        if ($sessionExpressions !== null && $sessionExpressions->isNotEmpty()) {
            return $sessionExpressions;
        }

        return $this->occurrence->timeExpressions->whereNull('event_session_id');
    }

    public function url(): string
    {
        $parameters = [
            'event' => $this->event,
            'occurrenceSlug' => PublicScheduleSlug::occurrence($this->occurrence),
        ];

        if ($this->session instanceof EventSession) {
            $parameters['sessionSlug'] = PublicScheduleSlug::session($this->session);

            return route('events.session', $parameters);
        }

        return route('events.occurrence', $parameters);
    }
}
