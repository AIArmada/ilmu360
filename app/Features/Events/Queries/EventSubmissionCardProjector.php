<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use AIArmada\CommerceSupport\Support\UserDateTimeFormatter;
use AIArmada\CommerceSupport\Support\UserTimezoneResolver;
use AIArmada\Events\Models\EventInvolvement;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventReference;
use App\Features\Events\Api\Data\EventSubmissionCard;
use App\Features\Events\Api\Enums\EventKeyPersonRole;
use App\Features\Events\Api\PublicScheduleSlug;
use App\Features\Events\Api\PublicScheduleVisibility;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventSubmission;
use App\Features\References\Api\ReferenceLookup;
use Carbon\CarbonImmutable;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;

final readonly class EventSubmissionCardProjector
{
    public function __construct(private ReferenceLookup $references) {}

    public function toCard(EventSubmission $submission): EventSubmissionCard
    {
        $event = $submission->event;
        $occurrence = $submission->occurrence;
        $title = $occurrence instanceof EventOccurrence && filled($occurrence->title)
            ? (string) $occurrence->title
            : ($event instanceof Event ? (string) $event->title : null);

        $parentTitle = $occurrence instanceof EventOccurrence
            && $event instanceof Event
            && filled($event->title)
            && (string) $event->title !== $title
            ? (string) $event->title
            : null;

        $status = $occurrence instanceof EventOccurrence
            ? $occurrence->status
            : ($event instanceof Event ? $event->status : null);
        $statusValue = $status !== null ? (string) $status : null;
        $statusLabel = match (true) {
            $status instanceof HasLabel => (string) $status->getLabel(),
            $status !== null && method_exists($status, 'label') => (string) $status->label(),
            $statusValue !== null => str($statusValue)->headline()->toString(),
            default => null,
        };

        $startsAt = $occurrence instanceof EventOccurrence
            ? $occurrence->starts_at
            : ($event instanceof Event ? $event->starts_at : null);
        $endsAt = $occurrence instanceof EventOccurrence
            ? $occurrence->ends_at
            : ($event instanceof Event ? $event->ends_at : null);

        $dateLabel = $startsAt !== null ? UserDateTimeFormatter::translatedFormat($startsAt, 'l, j F Y') : null;
        $timeLabel = null;

        if ($startsAt !== null) {
            $timeLabel = UserDateTimeFormatter::format($startsAt, 'h:i A');

            if ($endsAt !== null) {
                $viewerTimezone = UserTimezoneResolver::resolve();
                $sameDay = $startsAt->copy()->timezone($viewerTimezone)->isSameDay($endsAt->copy()->timezone($viewerTimezone));
                $timeLabel .= ' – '.UserDateTimeFormatter::format($endsAt, $sameDay ? 'h:i A' : 'j M, h:i A');
            }
        }

        $url = null;
        if ($event instanceof Event && $event->isPubliclyReachable()) {
            if ($occurrence instanceof EventOccurrence) {
                if (app(PublicScheduleVisibility::class)->isPublicOccurrence((string) $occurrence->getKey())) {
                    $url = route('events.occurrence', [
                        $event,
                        PublicScheduleSlug::occurrence(
                            slug: (string) $occurrence->slug,
                            title: $occurrence->title,
                            id: (string) $occurrence->getKey(),
                        ),
                    ]);
                }
            } else {
                $url = route('events.show', $event);
            }
        }

        $rawSubmissionData = $submission->getAttribute('submission_data');
        $createdAt = $submission->created_at;

        return new EventSubmissionCard(
            id: (string) $submission->getKey(),
            eventId: (string) $submission->event_id,
            eventTitle: $event instanceof Event ? (string) $event->title : null,
            eventStatus: $event instanceof Event ? (string) $event->status : null,
            eventUrl: $url,
            occurrenceId: $occurrence instanceof EventOccurrence ? (string) $occurrence->getKey() : null,
            createdAt: $createdAt !== null ? CarbonImmutable::parse($createdAt)->toIso8601String() : null,
            createdAtHuman: $createdAt !== null ? CarbonImmutable::parse($createdAt)->diffForHumans() : null,
            notes: is_array($rawSubmissionData) && is_string($rawSubmissionData['notes'] ?? null) ? $rawSubmissionData['notes'] : null,
            presentation: [
                'title' => $title,
                'parent_title' => $parentTitle,
                'status_value' => $statusValue,
                'status_label' => $statusLabel,
                'date_label' => $dateLabel,
                'time_label' => $timeLabel,
                'url' => $url,
            ],
            details: $this->details($event, $occurrence),
        );
    }

    /** @return list<string> */
    private function details(?Event $event, ?EventOccurrence $occurrence): array
    {
        if (! $event instanceof Event && ! $occurrence instanceof EventOccurrence) {
            return [];
        }

        $institution = null;
        $speakers = collect();
        $references = collect();

        if ($occurrence instanceof EventOccurrence) {
            $organizer = $occurrence->involvements->firstWhere('role_code', 'organizer')?->involveable;
            $institution = $this->isMorph($organizer, 'institution') ? $organizer : null;

            $speakers = $occurrence->involvements
                ->where('role_code', EventKeyPersonRole::Speaker->value)
                ->map(function (EventInvolvement $involvement): ?string {
                    $person = $involvement->involveable;
                    $name = $this->isMorph($person, 'person') ? $person->getAttribute('formatted_name') : null;

                    return is_string($name) && $name !== ''
                        ? $name
                        : (filled($involvement->display_name) ? (string) $involvement->display_name : null);
                })
                ->filter()
                ->values();

            $references = $occurrence->references
                ->map(function (EventReference $relation): ?string {
                    $record = $relation->referenceable;

                    if ($this->isMorph($record, 'reference')) {
                        return $this->references->title((string) $record->getKey());
                    }

                    return filled($relation->title) ? (string) $relation->title : null;
                })
                ->filter()
                ->values();
        }

        if ($event instanceof Event) {
            $institution ??= $event->institution;

            if ($speakers->isEmpty()) {
                $speakers = $event->persons
                    ->map(static fn (Model $person): ?string => is_string($person->getAttribute('formatted_name')) ? $person->getAttribute('formatted_name') : null)
                    ->filter();
            }

            if ($references->isEmpty()) {
                $references = $event->references
                    ->map(fn (Model $reference): ?string => $this->references->title((string) $reference->getKey()))
                    ->filter();
            }
        }

        $details = [];
        if ($institution instanceof Model) {
            $name = $institution->getAttribute('display_name') ?? $institution->getAttribute('name');
            if (is_string($name) && $name !== '') {
                $details[] = __('Institution: :name', ['name' => $name]);
            }
        }
        if ($speakers->isNotEmpty()) {
            $details[] = __('Speakers: :names', ['names' => $speakers->join(', ')]);
        }
        if ($references->isNotEmpty()) {
            $details[] = __('References: :names', ['names' => $references->join(', ')]);
        }

        return $details;
    }

    private function isMorph(mixed $record, string $alias): bool
    {
        return $record instanceof Model && $record->getMorphClass() === $alias;
    }
}
