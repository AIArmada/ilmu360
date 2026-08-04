<?php

declare(strict_types=1);

namespace App\Data;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Models\Event;
use App\Support\Events\PublicScheduleSlug;
use Carbon\CarbonInterface;

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
