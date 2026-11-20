<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Policies;

use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Api\Orm\SeriesAuthoring;
use App\Features\Institutions\Api\Orm\InstitutionLookup;
use App\Features\Persons\Api\Orm\PersonLookup;
use App\Features\References\Api\Orm\ReferenceLookup;
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

        return app(EventScheduleReads::class)->isPubliclyReachable($subjectId);
    }

    private function isInstitutionReachable(?string $subjectId): bool
    {
        if ($subjectId === null) {
            return false;
        }

        return app(InstitutionLookup::class)->findDirectoryFollowableSubject($subjectId) !== null;
    }

    private function isPersonReachable(?string $subjectId): bool
    {
        if ($subjectId === null) {
            return false;
        }

        return app(PersonLookup::class)->findDirectoryFollowableSubject($subjectId) !== null;
    }

    private function isSeriesReachable(?string $subjectId): bool
    {
        if ($subjectId === null) {
            return false;
        }

        return app(SeriesAuthoring::class)->isPubliclyReachable($subjectId);
    }

    private function isReferenceReachable(?string $subjectId): bool
    {
        if ($subjectId === null) {
            return false;
        }

        return app(ReferenceLookup::class)->findDirectoryFollowableSubject($subjectId) !== null;
    }
}
