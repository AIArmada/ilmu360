<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Filament;

/**
 * Canonical field names of the public event form, shared by every mode.
 */
final readonly class EventContributionFormFields
{
    public const string DEFAULT_CUSTOM_TIME = '20:00';

    public const string TITLE = 'title';

    public const string EVENT_CATEGORY_IDS = 'event_category_ids';

    public const string DESCRIPTION = 'description';

    public const string EVENT_DATE = 'event_date';

    public const string PRAYER_TIME = 'prayer_time';

    public const string CUSTOM_TIME = 'custom_time';

    public const string END_TIME = 'end_time';

    public const string TIMEZONE = 'timezone';

    public const string GENDER = 'gender';

    public const string AGE_GROUP = 'age_group';

    public const string CHILDREN_ALLOWED = 'children_allowed';

    public const string IS_MUSLIM_ONLY = 'is_muslim_only';

    public const string LANGUAGES = 'languages';

    public const string DOMAIN_TAGS = 'domain_tags';

    public const string DISCIPLINE_TAGS = 'discipline_tags';

    public const string SOURCE_TAGS = 'source_tags';

    public const string ISSUE_TAGS = 'issue_tags';

    public const string REFERENCES = 'references';

    public const string EVENT_FORMAT = 'event_format';

    public const string VISIBILITY = 'visibility';

    public const string EVENT_URL = 'event_url';

    public const string LIVE_URL = 'live_url';

    public const string RECORDING_URL = 'recording_url';

    public const string PRIMARY_ORGANIZER_KIND = 'primary_organizer_kind';

    public const string PRIMARY_ORGANIZER_ID = 'primary_organizer_id';

    public const string PRIMARY_ORGANIZER_INSTITUTION_ID = 'primary_organizer_institution_id';

    public const string PRIMARY_ORGANIZER_PERSON_ID = 'primary_organizer_person_id';

    public const string SERIES_IDS = 'series_ids';

    public const string LOCATION_SAME_AS_INSTITUTION = 'location_same_as_institution';

    public const string LOCATION_TYPE = 'location_type';

    public const string LOCATION_INSTITUTION_ID = 'location_institution_id';

    public const string LOCATION_VENUE_ID = 'location_venue_id';

    public const string SPACE_IDS = 'space_ids';

    public const string PERSONS = 'persons';

    public const string OTHER_KEY_PEOPLE = 'other_key_people';

    public const string SUBMISSION_COUNTRY_ID = 'submission_country_id';

    /**
     * Canonical field name => form state key, per entrypoint.
     *
     * Create keeps the historic wizard keys; update keeps the persisted
     * contribution projection keys its original-state diff publishes.
     *
     * @return array<string, string>
     */
    public static function keys(EventContributionFormMode $mode): array
    {
        $keys = [
            self::TITLE => 'title',
            self::EVENT_CATEGORY_IDS => 'event_category_ids',
            self::DESCRIPTION => 'description',
            self::EVENT_DATE => 'event_date',
            self::PRAYER_TIME => 'prayer_time',
            self::CUSTOM_TIME => 'custom_time',
            self::END_TIME => 'end_time',
            self::TIMEZONE => 'submission_timezone',
            self::GENDER => 'gender',
            self::AGE_GROUP => 'age_group',
            self::CHILDREN_ALLOWED => 'children_allowed',
            self::IS_MUSLIM_ONLY => 'is_muslim_only',
            self::LANGUAGES => 'languages',
            self::DOMAIN_TAGS => 'domain_tags',
            self::DISCIPLINE_TAGS => 'discipline_tags',
            self::SOURCE_TAGS => 'source_tags',
            self::ISSUE_TAGS => 'issue_tags',
            self::REFERENCES => 'references',
            self::EVENT_FORMAT => 'event_format',
            self::VISIBILITY => 'visibility',
            self::EVENT_URL => 'event_url',
            self::LIVE_URL => 'live_url',
            self::RECORDING_URL => 'recording_url',
            self::PRIMARY_ORGANIZER_KIND => 'primary_organizer_kind',
            self::PRIMARY_ORGANIZER_ID => 'primary_organizer_id',
            self::PRIMARY_ORGANIZER_INSTITUTION_ID => 'primary_organizer_institution_id',
            self::PRIMARY_ORGANIZER_PERSON_ID => 'primary_organizer_person_id',
            self::SERIES_IDS => 'series_ids',
            self::LOCATION_SAME_AS_INSTITUTION => 'location_same_as_institution',
            self::LOCATION_TYPE => 'location_type',
            self::LOCATION_INSTITUTION_ID => 'location_institution_id',
            self::LOCATION_VENUE_ID => 'location_venue_id',
            self::SPACE_IDS => 'space_ids',
            self::PERSONS => 'persons',
            self::OTHER_KEY_PEOPLE => 'other_key_people',
        ];

        if ($mode->isCreate()) {
            return $keys;
        }

        return [
            ...$keys,
            self::TIMEZONE => 'timezone',
            self::LANGUAGES => 'language_ids',
            self::REFERENCES => 'reference_ids',
            self::PERSONS => 'person_ids',
        ];
    }
}
