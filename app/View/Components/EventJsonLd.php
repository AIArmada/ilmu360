<?php

namespace App\View\Components;

use App\Enums\EventChangeType;
use App\Enums\ScheduleState;
use App\Models\Address;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\EventSettings;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\State;
use App\Models\Venue;
use Illuminate\View\Component;
use Illuminate\View\View;
use Spatie\Tags\Tag;

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
            $venueAddress = $venue->addressModel;
            $region = '';

            if ($venueAddress instanceof Address && $venueAddress->state instanceof State) {
                $region = $venueAddress->state->name;
            }

            $jsonLd['location'] = [
                '@type' => 'Place',
                'name' => $venue->name,
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $venue->address_line1,
                    'addressLocality' => '',
                    'addressRegion' => $region,
                    'addressCountry' => 'MY',
                ],
            ];

            if ($venueAddress instanceof Address && $venueAddress->lat !== null && $venueAddress->lng !== null) {
                $jsonLd['location']['geo'] = [
                    '@type' => 'GeoCoordinates',
                    'latitude' => $venueAddress->lat,
                    'longitude' => $venueAddress->lng,
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

        if ($event->tags->isNotEmpty()) {
            $jsonLd['about'] = $event->tags->map(function (mixed $tag): array {
                $name = $tag instanceof Tag ? $tag->name : '';

                return [
                    '@type' => 'Thing',
                    'name' => is_string($name) ? $name : '',
                ];
            })->toArray();
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
            && in_array($notice->type, [
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
        $settings = $event->settings;

        if (in_array((string) $event->status, ['rejected', 'cancelled'], true)) {
            return 'https://schema.org/Discontinued';
        }

        if ($event->schedule_state === ScheduleState::Postponed) {
            return 'https://schema.org/Discontinued';
        }

        if ($settings instanceof EventSettings
            && $settings->registration_required
            && $settings->capacity !== null
            && $event->registrations_count >= $settings->capacity) {
            return 'https://schema.org/SoldOut';
        }

        return 'https://schema.org/InStock';
    }
}
