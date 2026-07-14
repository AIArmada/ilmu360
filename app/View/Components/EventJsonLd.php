<?php

namespace App\View\Components;

use AIArmada\Addressing\Models\Address;
use AIArmada\Events\Models\EventAccessPolicy;
use App\Enums\EventChangeType;
use App\Enums\ScheduleState;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Venue;
use Illuminate\View\Component;
use Illuminate\View\View;

class EventJsonLd extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public Event $event
    ) {}

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View
    {
        return view('components.event-json-ld');
    }

    /**
     * Generate JSON-LD structured data for the event.
     * Schema.org Event type per documentation B9b.
     *
     * @return array<string, mixed>
     */
    public function jsonLd(): array
    {
        $event = $this->event;
        $venue = $event->venue;
        $institution = $event->institution;

        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->title,
            'description' => $event->description_text,
            'startDate' => $event->starts_at?->toIso8601String(),
            'endDate' => $event->ends_at?->toIso8601String(),
            'eventStatus' => $this->getEventStatus(),
            'eventAttendanceMode' => $this->getAttendanceMode(),
            'url' => route('events.show', $event),
        ];

        if ($venue instanceof Venue) {
            $venueAddress = $venue->primaryAddress();
            $region = '';

            if ($venueAddress instanceof Address && is_string($venueAddress->state)) {
                $region = $venueAddress->state;
            }

            $jsonLd['location'] = [
                '@type' => 'Place',
                'name' => $venue->name,
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $venue->primaryAddress()?->line1,
                    'addressLocality' => $venueAddress?->city,
                    'addressRegion' => $region,
                    'addressCountry' => $venueAddress?->country_code,
                ],
            ];

            if ($venueAddress instanceof Address && $venueAddress->latitude !== null && $venueAddress->longitude !== null) {
                $jsonLd['location']['geo'] = [
                    '@type' => 'GeoCoordinates',
                    'latitude' => $venueAddress->latitude,
                    'longitude' => $venueAddress->longitude,
                ];
            }
        } elseif ($institution instanceof Institution) {
            $jsonLd['location'] = [
                '@type' => 'Place',
                'name' => $institution->name,
            ];
        }

        if ($institution instanceof Institution) {
            $jsonLd['organizer'] = [
                '@type' => 'Organization',
                'name' => $institution->name,
                'url' => route('institutions.show', $institution),
            ];
        }

        if ($event->speakers->isNotEmpty()) {
            $jsonLd['performer'] = $event->speakers->map(fn (Speaker $speaker): array => [
                '@type' => 'Person',
                'name' => $speaker->name,
                'url' => route('speakers.show', $speaker),
            ])->toArray();
        }

        if ($event->card_image_url !== '') {
            $jsonLd['image'] = $event->card_image_url;
        }

        $jsonLd['offers'] = [
            '@type' => 'Offer',
            'price' => '0',
            'priceCurrency' => 'MYR',
            'availability' => $this->getAvailability(),
            'url' => route('events.show', $event),
        ];

        $event->loadMissing(['classifications']);

        if ($event->classifications->isNotEmpty()) {
            $jsonLd['about'] = $event->classifications
                ->map(fn ($classification): array => [
                    '@type' => 'Thing',
                    'name' => (string) ($classification->term_code ?? $classification->taxonomy_code ?? ''),
                ])
                ->filter(fn (array $item): bool => $item['name'] !== '')
                ->values()
                ->all();
        }

        $jsonLd['inLanguage'] = match ($event->language) {
            'malay' => 'ms',
            'english' => 'en',
            'arabic' => 'ar',
            'mixed' => ['ms', 'en'],
            default => 'ms',
        };

        return $jsonLd;
    }

    /**
     * Get Schema.org event status.
     */
    protected function getEventStatus(): string
    {
        if (in_array((string) $this->event->status, ['rejected', 'cancelled'], true)) {
            return 'https://schema.org/EventCancelled';
        }

        if ($this->event->schedule_state === ScheduleState::Postponed) {
            return 'https://schema.org/EventPostponed';
        }

        $notice = $this->event->latestPublishedChangeAnnouncement;

        if ($notice instanceof EventChangeAnnouncement
            && in_array($notice->update_type, [
                EventChangeType::RescheduledEarlier,
                EventChangeType::RescheduledLater,
                EventChangeType::ScheduleChanged,
            ], true)) {
            return 'https://schema.org/EventRescheduled';
        }

        return 'https://schema.org/EventScheduled';
    }

    /**
     * Get Schema.org attendance mode.
     */
    protected function getAttendanceMode(): string
    {
        if ($this->event->live_url) {
            return 'https://schema.org/MixedEventAttendanceMode';
        }

        return 'https://schema.org/OfflineEventAttendanceMode';
    }

    /**
     * Get Schema.org availability.
     */
    protected function getAvailability(): string
    {
        $event = $this->event;
        $accessPolicy = $event->accessPolicy;

        if (in_array((string) $event->status, ['rejected', 'cancelled'], true)) {
            return 'https://schema.org/Discontinued';
        }

        if ($event->schedule_state === ScheduleState::Postponed) {
            return 'https://schema.org/Discontinued';
        }

        if ($accessPolicy instanceof EventAccessPolicy
            && $accessPolicy->registration_required
            && $accessPolicy->capacity !== null
            && $event->registrations_count >= $accessPolicy->capacity) {
            return 'https://schema.org/SoldOut';
        }

        return 'https://schema.org/InStock';
    }
}
