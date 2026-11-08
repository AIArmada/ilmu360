<?php

declare(strict_types=1);

namespace App\Support\Discovery;

use App\Contracts\EventCategoryCatalog;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\TimingMode;
use App\Shared\Discovery\SearchFilterCatalog;
use App\Shared\Prayer\EventPrayerTime;

final readonly class EventSearchFilterCatalog implements SearchFilterCatalog
{
    public function __construct(private EventCategoryCatalog $categories) {}

    public function allowedValues(string $filterKey): array
    {
        return match ($filterKey) {
            'event_format' => array_column(EventFormat::cases(), 'value'),
            'gender' => array_column(EventGenderRestriction::cases(), 'value'),
            'age_group' => array_column(EventAgeGroup::cases(), 'value'),
            'key_person_roles' => array_keys(EventKeyPersonRole::nonSpeakerOptions()),
            'prayer_time' => array_column(EventPrayerTime::cases(), 'value'),
            'timing_mode' => array_column(TimingMode::cases(), 'value'),
            'time_scope' => ['upcoming', 'past', 'all'],
            default => [],
        };
    }

    public function label(string $filterKey, string $value): ?string
    {
        return match ($filterKey) {
            'event_format' => EventFormat::tryFrom($value)?->getLabel(),
            'gender' => EventGenderRestriction::tryFrom($value)?->getLabel(),
            'age_group' => EventAgeGroup::tryFrom($value)?->getLabel(),
            'key_person_roles' => EventKeyPersonRole::tryFrom($value)?->getLabel(),
            'prayer_time' => EventPrayerTime::tryFrom($value)?->getLabel(),
            'timing_mode' => TimingMode::tryFrom($value)?->label(),
            'event_category_ids' => $this->categories->options()[$value] ?? null,
            'time_scope' => match ($value) {
                'upcoming' => __('Upcoming'),
                'past' => __('Past'),
                'all' => __('All Time'),
                default => null,
            },
            default => null,
        };
    }

    public function validCategoryIds(array $ids): array
    {
        return $this->categories->validTermIds($ids);
    }
}
