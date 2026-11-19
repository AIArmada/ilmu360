<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Policies;

use App\Features\Events\Models\Event;
use App\Features\Events\Models\Series;
use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Api\SpeakerStatus;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Features\ShareTracking\Api\Enums\ShareSubjectType;
use App\Features\ShareTracking\Services\ShareTrackingUrlService;
use InvalidArgumentException;

/**
 * Redirect-time destination revalidation for share short links.
 *
 * Creation-time checks cannot govern a redirect that happens days later:
 * an event may be unpublished, an organizer deactivated, or a person
 * removed. Every short-link resolution revalidates the destination against
 * the same publicity rules the public show pages enforce, without any
 * staff bypass. Unknown, private, or unpublished destinations never open
 * through the share entry; the adapter shows honest unavailable content
 * instead, and no eligibility decision is ever bypassed.
 */
final readonly class ShareDestinationPolicy
{
    public function __construct(
        private ShareTrackingUrlService $shareTrackingUrlService,
    ) {}

    public function isPubliclyReachable(string $destinationUrl): bool
    {
        try {
            $target = $this->shareTrackingUrlService->classifyShareTarget($destinationUrl);
        } catch (InvalidArgumentException) {
            return false;
        }

        $subjectId = is_string($target['subject_id'] ?? null) && $target['subject_id'] !== ''
            ? $target['subject_id']
            : null;

        return match ($target['subject_type'] ?? ShareSubjectType::Page->value) {
            ShareSubjectType::Event->value => $this->isEventReachable($subjectId),
            ShareSubjectType::Institution->value => $this->isInstitutionReachable($subjectId),
            ShareSubjectType::Person->value => $this->isPersonReachable($subjectId),
            ShareSubjectType::Series->value => $this->isSeriesReachable($subjectId),
            ShareSubjectType::Reference->value => $this->isReferenceReachable($subjectId),
            // Generic public pages and search listings carry no subject
            // lifecycle; the route allowlist above is the whole check.
            default => true,
        };
    }

    private function isEventReachable(?string $subjectId): bool
    {
        if ($subjectId === null) {
            return false;
        }

        $event = Event::query()->whereKey($subjectId)->first();

        return $event instanceof Event && $event->isPubliclyReachable();
    }

    private function isInstitutionReachable(?string $subjectId): bool
    {
        if ($subjectId === null) {
            return false;
        }

        $institution = Institution::query()->whereKey($subjectId)->first();

        return $institution instanceof Institution
            && in_array($institution->status, InstitutionStatus::publiclyVisible(), true);
    }

    private function isPersonReachable(?string $subjectId): bool
    {
        if ($subjectId === null) {
            return false;
        }

        $person = Person::query()->whereKey($subjectId)->first();

        return $person instanceof Person
            && in_array((string) $person->status, ['verified', 'pending'], true)
            && $person->speaker_status === SpeakerStatus::Active;
    }

    private function isSeriesReachable(?string $subjectId): bool
    {
        if ($subjectId === null) {
            return false;
        }

        $series = Series::query()->whereKey($subjectId)->first();

        return $series instanceof Series && (string) $series->status === 'active';
    }

    private function isReferenceReachable(?string $subjectId): bool
    {
        if ($subjectId === null) {
            return false;
        }

        $reference = Reference::query()->whereKey($subjectId)->first();

        return $reference instanceof Reference && $reference->isPubliclyVisible();
    }
}
