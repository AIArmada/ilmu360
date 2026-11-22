<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use App\Features\Events\Api\Data\PublicScheduleRenderedCard;
use Carbon\CarbonInterface;

/** Schedule sections return immutable rendered card values, never Eloquent models. */
interface EventSchedulePageData
{
    /** @param list<string> $savedEventIds
     * @param  list<string>  $goingEventIds
     * @return array{upcoming: list<PublicScheduleRenderedCard>, past: list<PublicScheduleRenderedCard>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}
     */
    public function forInstitution(
        string $institutionId, int $upcomingLimit, int $pastLimit,
        ?CarbonInterface $rangeFrom = null, ?CarbonInterface $rangeTo = null,
        array $savedEventIds = [], array $goingEventIds = [],
    ): array;

    /**
     * Speaker-specific public schedule, filtered to visible involvements.
     *
     * @param  list<string>  $savedEventIds
     * @param  list<string>  $goingEventIds
     * @return array{upcoming: list<PublicScheduleRenderedCard>, past: list<PublicScheduleRenderedCard>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}
     */
    public function forPerson(
        string $personId,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
        array $savedEventIds = [],
        array $goingEventIds = [],
    ): array;

    /** @param list<string> $referenceIds
     * @param  list<string>  $savedEventIds
     * @param  list<string>  $goingEventIds
     * @return array{upcoming: list<PublicScheduleRenderedCard>, past: list<PublicScheduleRenderedCard>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}
     */
    public function forReferenceFamily(
        array $referenceIds, int $upcomingLimit, int $pastLimit,
        ?CarbonInterface $rangeFrom = null, ?CarbonInterface $rangeTo = null,
        array $savedEventIds = [], array $goingEventIds = [],
    ): array;
}
