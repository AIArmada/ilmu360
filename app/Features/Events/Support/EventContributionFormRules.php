<?php

declare(strict_types=1);

namespace App\Features\Events\Support;

use AIArmada\Events\Features\Taxonomy\Models\EventTaxonomy;
use AIArmada\Events\Features\Taxonomy\Models\EventTerm;
use App\Features\Events\Api\Enums\EventAgeGroup;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Api\EventCategoryPolicyResolver;
use App\Features\Events\Api\EventTaxonomyCode;
use App\Features\Events\Enums\Terms\DomainTermCode;
use App\Features\Events\Services\OrganizerResolver;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\Prayer\Api\RamadanGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Entrypoint-neutral state rules for the canonical public event form.
 *
 * Both the submit-event wizard and contribution update tabs evaluate the same
 * category, topic, age-group and organizer rules; entrypoints only supply
 * their own state keys.
 */
final class EventContributionFormRules
{
    /**
     * @return list<string>
     */
    public static function normalizeAgeGroupState(mixed $state): array
    {
        if ($state instanceof Collection) {
            $state = $state->all();
        }

        if (! is_array($state)) {
            $state = [$state];
        }

        return collect($state)
            ->map(function (mixed $ageGroup): ?string {
                if ($ageGroup instanceof EventAgeGroup) {
                    return $ageGroup->value;
                }

                if (is_string($ageGroup) && filled($ageGroup)) {
                    return $ageGroup;
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $ageGroups
     * @return array<int, string>
     */
    public static function normalizeAgeGroupSelection(array $ageGroups): array
    {
        $ageGroups = array_values(array_unique($ageGroups));
        $specificAgeGroups = [
            EventAgeGroup::Adults->value,
            EventAgeGroup::Youth->value,
            EventAgeGroup::Children->value,
            EventAgeGroup::Seniors->value,
        ];

        if (in_array(EventAgeGroup::AllAges->value, $ageGroups, true)) {
            return [EventAgeGroup::AllAges->value];
        }

        if (count(array_intersect($specificAgeGroups, $ageGroups)) === count($specificAgeGroups)) {
            return [EventAgeGroup::AllAges->value];
        }

        return $ageGroups;
    }

    public static function shouldForceChildrenAllowed(mixed $ageGroups): bool
    {
        $normalizedAgeGroups = self::normalizeAgeGroupState($ageGroups);

        return in_array(EventAgeGroup::Children->value, $normalizedAgeGroups, true)
            || in_array(EventAgeGroup::AllAges->value, $normalizedAgeGroups, true);
    }

    public static function hasCommunityCategorySelection(mixed $categoryIds): bool
    {
        return app(EventCategoryPolicyResolver::class)->requiresPhysicalDelivery(
            app(EventCategoryCatalog::class)->validateTermIds(self::toList($categoryIds)),
        );
    }

    public static function categoriesRequirePersons(mixed $categoryIds): bool
    {
        return app(EventCategoryPolicyResolver::class)->requiresSpeaker(
            app(EventCategoryCatalog::class)->validateTermIds(self::toList($categoryIds)),
        );
    }

    public static function selectedPrimaryOrganizerKind(mixed $organizerKind, mixed $primaryOrganizerId): ?string
    {
        if (in_array($organizerKind, ['institution', 'person'], true)) {
            return $organizerKind;
        }

        return self::resolvedPrimaryOrganizerType($primaryOrganizerId);
    }

    public static function resolvedPrimaryOrganizerType(mixed $primaryOrganizerId): ?string
    {
        if (! is_scalar($primaryOrganizerId)) {
            return null;
        }

        $organizerId = trim((string) $primaryOrganizerId);

        if ($organizerId === '') {
            return null;
        }

        return OrganizerResolver::kindForId($organizerId);
    }

    public static function isPrayerTime(mixed $value, EventPrayerTime $expected): bool
    {
        return $value instanceof EventPrayerTime
            ? $value === $expected
            : $value === $expected->value;
    }

    /**
     * @return list<string>
     */
    public static function normalizeSingleSelectList(mixed $state): array
    {
        if ($state instanceof Collection) {
            $state = $state->all();
        }

        if (! is_array($state)) {
            $state = [$state];
        }

        return collect($state)
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function normalizeStringList(mixed $values): array
    {
        if ($values instanceof Collection) {
            $values = $values->all();
        }

        if (! is_array($values)) {
            $values = [$values];
        }

        return collect($values)
            ->map(fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function selectedIds(mixed $state): array
    {
        if ($state instanceof Collection) {
            $state = $state->all();
        }

        if (! is_array($state)) {
            $state = [$state];
        }

        return collect($state)
            ->filter(fn (mixed $value): bool => is_scalar($value) && Str::isUuid((string) $value))
            ->map(fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }

    public static function hasAgamaKerohanianTopic(mixed $topicIds): bool
    {
        $ids = self::selectedIds($topicIds);

        if ($ids === []) {
            return false;
        }

        $taxonomyId = EventTaxonomy::query()->where('code', EventTaxonomyCode::Domain->value)->value('id');

        if (! is_string($taxonomyId)) {
            return false;
        }

        return EventTerm::query()
            ->where('event_taxonomy_id', $taxonomyId)
            ->whereIn('id', $ids)
            ->where('code', DomainTermCode::AgamaKerohanian->value)
            ->exists();
    }

    public static function hasSelection(mixed $value): bool
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            return collect($value)->contains(fn (mixed $item): bool => filled($item));
        }

        return filled($value);
    }

    /**
     * Calendar-gated prayer labels keyed by value. Both entrypoints resolve
     * the country from their own state shape; callers pass the ISO2 code.
     *
     * @return array<string, string>
     */
    public static function prayerTimeOptions(mixed $eventDate, mixed $timezone, ?string $countryCode = null): array
    {
        return collect(EventPrayerTime::cases())
            ->filter(function (EventPrayerTime $eventPrayerTime) use ($eventDate, $timezone, $countryCode): bool {
                if ($eventDate === null || $eventDate === '') {
                    return ! in_array($eventPrayerTime, [
                        EventPrayerTime::SebelumJumaat,
                        EventPrayerTime::SelepasJumaat,
                        EventPrayerTime::SelepasTarawih,
                    ], true);
                }

                $resolvedDate = self::parseEventDate($eventDate, $timezone);

                if (! $resolvedDate instanceof Carbon) {
                    return true;
                }

                if ($eventPrayerTime === EventPrayerTime::SebelumJumaat || $eventPrayerTime === EventPrayerTime::SelepasJumaat) {
                    return $resolvedDate->isFriday();
                }

                if ($eventPrayerTime === EventPrayerTime::SelepasZuhur) {
                    return ! $resolvedDate->isFriday();
                }

                if ($eventPrayerTime === EventPrayerTime::SelepasTarawih) {
                    return app(RamadanGate::class)->isRamadan($resolvedDate, $resolvedDate->tzName, $countryCode);
                }

                return true;
            })
            ->mapWithKeys(fn (EventPrayerTime $eventPrayerTime): array => [$eventPrayerTime->value => $eventPrayerTime->getLabel()])
            ->toArray();
    }

    /**
     * Resolve or create a pending taxonomy term, mirroring the contribution
     * form's create-option contract.
     *
     * @param  array<string, mixed>  $data
     */
    public static function ensureTaxonomyTerm(EventTaxonomyCode $type, array $data): string
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            return '';
        }

        return app(EventAuthoring::class)->ensureTaxonomyTerm($type->value, $name);
    }

    /**
     * @return list<mixed>
     */
    private static function toList(mixed $value): array
    {
        if ($value instanceof Collection) {
            return $value->all();
        }

        if (! is_array($value)) {
            return filled($value) ? [$value] : [];
        }

        return array_values($value);
    }

    private static function parseEventDate(mixed $eventDate, mixed $timezone): ?Carbon
    {
        if (! is_string($eventDate) || trim($eventDate) === '') {
            return null;
        }

        $resolvedTimezone = is_string($timezone) && trim($timezone) !== ''
            ? trim($timezone)
            : 'Asia/Kuala_Lumpur';

        try {
            return Carbon::parse($eventDate, $resolvedTimezone)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
