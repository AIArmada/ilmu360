<?php

declare(strict_types=1);

namespace App\Shared\Discovery;

final class SearchFilterKeys
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'country_id',
            'state_id',
            'city_id',
            'area_assignments',
            'institution_id',
            'venue_id',
            'person_ids',
            'key_person_roles',
            'person_in_charge_ids',
            'person_in_charge_search',
            'person_name_search',
            'moderator_ids',
            'imam_ids',
            'khatib_ids',
            'bilal_ids',
            'domain_tag_ids',
            'discipline_tag_ids',
            'source_tag_ids',
            'issue_tag_ids',
            'reference_ids',
            'reference_author_search',
            'starts_on_local_date',
            'language_codes',
            'event_category_ids',
            'event_format',
            'gender',
            'starts_after',
            'starts_before',
            'time_scope',
            'prayer_time',
            'timing_mode',
            'starts_time_from',
            'starts_time_until',
            'children_allowed',
            'is_muslim_only',
            'has_event_url',
            'has_live_url',
            'has_end_time',
            'age_group',
        ];
    }
}
