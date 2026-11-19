<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Frontend;

use App\Features\Events\Api\Enums\EventAgeGroup;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\EventGenderRestriction;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Api\EventTaxonomyCode;
use App\Features\Events\Api\SubmissionTimingContract;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Models\User;
use App\Platform\Api\Frontend\Contracts\FrontendFormContractContributor;
use App\Platform\Api\Frontend\FrontendFormContractKey;
use App\Platform\Api\Frontend\Support\FrontendFormContractDefaults;
use App\Platform\Api\Frontend\Support\FrontendFormContractField;

final readonly class SubmitEventFormContractContributor implements FrontendFormContractContributor
{
    public function __construct(
        private EventCategoryCatalog $eventCategories,
        private SubmissionTimingContract $timing,
        private FrontendFormContractDefaults $defaults,
    ) {}

    public function supports(string $key): bool
    {
        return $key === FrontendFormContractKey::SUBMIT_EVENT;
    }

    public function manifest(?User $user): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function contract(string $key, array $context): array
    {
        $user = $context['user'] ?? null;

        return $this->submitEvent($user instanceof User ? $user : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function submitEvent(?User $user): array
    {
        $defaultLanguageId = $this->defaults->defaultLanguageId();
        $defaultLanguages = $defaultLanguageId === null ? [] : [$defaultLanguageId];
        $timezoneCatalog = $this->timing->countryTimezoneCatalog();
        $submissionCountryIds = array_keys($timezoneCatalog);
        $multiTimezoneCountryIds = [];

        foreach ($timezoneCatalog as $countryId => $timezones) {
            if (count($timezones) > 1) {
                $multiTimezoneCountryIds[] = $countryId;
            }
        }

        return [
            'flow' => 'submit_event',
            'method' => 'POST',
            'endpoint' => route('api.client.submit-event.store'),
            'auth_required' => false,
            'captcha_required_when_turnstile_enabled' => true,
            'captcha_action' => config('services.turnstile.expected_action'),
            'defaults' => [
                'event_id' => null,
                'scoped_institution_id' => null,
                'submitter_name' => $user?->name,
                'submitter_email' => $user?->email,
                'children_allowed' => true,
                'gender' => EventGenderRestriction::All->value,
                'age_group' => [EventAgeGroup::AllAges->value],
                'languages' => $defaultLanguages,
                'event_format' => EventFormat::Physical->value,
                'visibility' => EventVisibility::Public->value,
                'primary_organizer_id' => null,
                'location_same_as_institution' => true,
                'location_type' => 'institution',
                'is_muslim_only' => false,
                'other_key_people' => [],
                'captcha_token' => null,
                'submission_country_id' => null,
                'submission_timezone' => null,
            ],
            'fields' => [
                FrontendFormContractField::make('title', 'string', required: true, maxLength: 255),
                FrontendFormContractField::make('description', 'rich_text', required: false),
                FrontendFormContractField::make('event_id', 'uuid', required: false),
                FrontendFormContractField::make('scoped_institution_id', 'uuid', required: false),
                FrontendFormContractField::make('event_category_ids', 'array<uuid>', required: true, allowedValues: array_keys($this->eventCategories->options())),
                FrontendFormContractField::make('event_date', 'date', required: true),
                FrontendFormContractField::make('prayer_time', 'string', required: true, allowedValues: FrontendFormContractField::enumValues(EventPrayerTime::class)),
                FrontendFormContractField::make('custom_time', 'time', required: false),
                FrontendFormContractField::make('end_time', 'time', required: false),
                FrontendFormContractField::make('event_format', 'string', required: true, default: EventFormat::Physical->value, allowedValues: FrontendFormContractField::enumValues(EventFormat::class)),
                FrontendFormContractField::make('visibility', 'string', required: true, default: EventVisibility::Public->value, allowedValues: FrontendFormContractField::enumValues(EventVisibility::class)),
                FrontendFormContractField::make('event_url', 'url', required: false),
                FrontendFormContractField::make('live_url', 'url', required: false),
                FrontendFormContractField::make('gender', 'string', required: true, default: EventGenderRestriction::All->value, allowedValues: FrontendFormContractField::enumValues(EventGenderRestriction::class)),
                FrontendFormContractField::make('age_group', 'array<string>', required: true, default: [EventAgeGroup::AllAges->value], allowedValues: FrontendFormContractField::enumValues(EventAgeGroup::class)),
                FrontendFormContractField::make('children_allowed', 'boolean', required: false, default: true),
                FrontendFormContractField::make('is_muslim_only', 'boolean', required: false, default: false),
                FrontendFormContractField::make('languages', 'array<string>', required: true, default: $defaultLanguages, catalog: route('api.client.catalogs.languages')),
                FrontendFormContractField::make('domain_tags', 'array<string>', required: false, catalog: route('api.client.catalogs.taxonomy-terms', ['type' => EventTaxonomyCode::Domain->value])),
                FrontendFormContractField::make('discipline_tags', 'array<string>', required: false, catalog: route('api.client.catalogs.taxonomy-terms', ['type' => EventTaxonomyCode::Discipline->value])),
                FrontendFormContractField::make('source_tags', 'array<string>', required: false, catalog: route('api.client.catalogs.taxonomy-terms', ['type' => EventTaxonomyCode::Source->value])),
                FrontendFormContractField::make('issue_tags', 'array<string>', required: false, catalog: route('api.client.catalogs.taxonomy-terms', ['type' => EventTaxonomyCode::Issue->value])),
                FrontendFormContractField::make('references', 'array<string>', required: false, catalog: route('api.client.catalogs.references')),
                FrontendFormContractField::make('primary_organizer_id', 'uuid', required: true, meta: [
                    'catalogs' => [
                        'institution' => route('api.client.catalogs.submit-institutions'),
                        'person' => route('api.client.catalogs.submit-persons'),
                    ],
                ]),
                FrontendFormContractField::make('location_same_as_institution', 'boolean', required: false, default: true),
                FrontendFormContractField::make('location_type', 'string', required: false, default: 'institution', allowedValues: ['institution', 'venue']),
                FrontendFormContractField::make('location_institution_id', 'uuid', required: false, catalog: route('api.client.catalogs.submit-institutions')),
                FrontendFormContractField::make('location_venue_id', 'uuid', required: false, catalog: route('api.client.catalogs.venues')),
                FrontendFormContractField::make('space_id', 'uuid', required: false, catalog: route('api.client.catalogs.spaces')),
                FrontendFormContractField::make('persons', 'array<string>', required: false, catalog: route('api.client.catalogs.submit-persons')),
                FrontendFormContractField::make('other_key_people', 'array<object>', required: false),
                FrontendFormContractField::make('submission_country_id', 'uuid', required: true, allowedValues: $submissionCountryIds),
                FrontendFormContractField::make('submission_timezone', 'string', required: false, maxLength: 64, meta: [
                    'description' => 'Required when the selected submission_country_id links multiple differing timezones; must match one of the effective country timezones. Countries with a single effective timezone resolve automatically.',
                    'options_by_country' => $timezoneCatalog,
                ]),
                FrontendFormContractField::make('submitter_name', 'string', required: ! $user instanceof User, maxLength: 255),
                FrontendFormContractField::make('submitter_email', 'email', required: false),
                FrontendFormContractField::make('submitter_phone', 'string', required: false),
                FrontendFormContractField::make('notes', 'string', required: false, maxLength: 1000),
                FrontendFormContractField::make('captcha_token', 'string', required: false),
                FrontendFormContractField::make('cover', 'file', required: false, acceptedMimeTypes: FrontendFormContractField::imageMimeTypes(), maxFileSizeKb: FrontendFormContractField::maxUploadSizeKb(), meta: [
                    'required_aspect_ratio' => '16:9',
                    'media_role' => 'website_app_cover',
                ]),
                FrontendFormContractField::make('poster', 'file', required: false, acceptedMimeTypes: FrontendFormContractField::imageMimeTypes(), maxFileSizeKb: FrontendFormContractField::maxUploadSizeKb(), meta: [
                    'required_aspect_ratio' => '3:4',
                    'media_role' => 'external_distribution_poster',
                ]),
                FrontendFormContractField::make('gallery', 'array<file>', required: false, acceptedMimeTypes: FrontendFormContractField::imageMimeTypes(), maxFileSizeKb: FrontendFormContractField::maxUploadSizeKb(), maxFiles: 10),
            ],
            'conditional_rules' => [
                ['field' => 'location_type', 'required_when' => ['location_same_as_institution' => [false]]],
                ['field' => 'location_institution_id', 'required_when' => ['location_type' => ['institution']]],
                ['field' => 'location_venue_id', 'required_when' => ['location_type' => ['venue']]],
                ['field' => 'submitter_email', 'required_when_missing' => ['submitter_phone']],
                ['field' => 'submitter_phone', 'required_when_missing' => ['submitter_email']],
                ['field' => 'submission_timezone', 'required_when' => ['submission_country_id' => $multiTimezoneCountryIds]],
            ],
        ];
    }
}
