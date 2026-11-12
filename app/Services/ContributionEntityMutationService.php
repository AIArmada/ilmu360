<?php

namespace App\Services;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\Addressing\Support\ModelResolver;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Contacting\Models\SocialProfile;
use AIArmada\Events\Models\FacilityType;
use AIArmada\Events\Models\VenueFacility;
use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Enums\Gender;
use AIArmada\Persons\Enums\PersonNameType;
use AIArmada\References\Enums\ReferenceRecordKind;
use AIArmada\References\Rules\Isbn;
use App\Features\Directory\Api\InstitutionWorkflow;
use App\Features\Directory\Api\PersonWorkflow;
use App\Features\Directory\Api\ReferenceWorkflow;
use App\Features\Events\Api\EventAuthoring;
use App\Forms\SharedFormSchema;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\Institution;
use App\Models\InstitutionName;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Models\Venue;
use App\Platform\Location\AddressAssignments;
use App\Rules\ValidAreaAssignmentRoles;
use App\Shared\Contributions\ContributionEntityState;
use App\Shared\Directory\Enums\InstitutionNameType;
use App\Shared\Directory\Enums\InstitutionType;
use App\Shared\Directory\Enums\ReferencePartType;
use App\Shared\Directory\Enums\ReferenceType;
use App\Shared\Events\Enums\EventAgeGroup;
use App\Shared\Events\Enums\EventFormat;
use App\Shared\Events\Enums\EventGenderRestriction;
use App\Shared\Events\Enums\EventKeyPersonRole;
use App\Shared\Events\Enums\EventVisibility;
use App\Shared\Events\EventCategoryCatalog;
use App\Shared\Prayer\EventPrayerTime;
use App\Shared\Taxonomy\EventTaxonomyCode;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use RuntimeException;

class ContributionEntityMutationService implements ContributionEntityState
{
    public function __construct(
        private readonly EventAuthoring $authoring,
        private readonly AddressCountryResolver $addressingCountryResolver,
        private readonly InstitutionWorkflow $institutionWorkflow,
        private readonly PersonWorkflow $personWorkflow,
        private readonly ReferenceWorkflow $referenceWorkflow,
    ) {}

    /** @return array<string, mixed> */
    public function stateFor(Model $entity): array
    {
        return match (true) {
            $entity instanceof Institution => $this->institutionState($entity),
            $entity instanceof Person => $this->personState($entity),
            $entity instanceof Reference => $this->referenceState($entity),
            $entity instanceof Event => $this->eventState($entity),
            $entity instanceof Venue => $this->venueState($entity),
            default => throw new RuntimeException('Unsupported contribution entity type.'),
        };
    }

    /**
     * @return array{
     *     accepts_partial_updates: bool,
     *     fields: list<array<string, mixed>>,
     *     conditional_rules: list<array<string, mixed>>,
     *     direct_edit_media_fields: list<string>
     * }
     */
    public function contractFor(Model $entity): array
    {
        return match (true) {
            $entity instanceof Institution => [
                'accepts_partial_updates' => true,
                'fields' => [
                    $this->field('name', 'string', maxLength: 255),
                    $this->field('names', 'array'),
                    $this->field('type', 'string', allowedValues: $this->enumValues(InstitutionType::class)),
                    $this->field('description', 'rich_text'),
                    $this->field('address', 'object'),
                    $this->field('address.country_id', 'uuid', catalog: route('api.client.catalogs.countries')),
                    $this->field('contactMethods', 'array<object>'),
                    $this->field('social_media', 'array<object>'),
                    $this->field('donation_channels', 'array<object>'),
                ],
                'conditional_rules' => [],
                'direct_edit_media_fields' => ['logo', 'cover', 'gallery'],
            ],
            $entity instanceof Person => [
                'accepts_partial_updates' => true,
                'fields' => [
                    $this->field('name', 'string', maxLength: 255),
                    $this->field('family_name', 'string', maxLength: 100),
                    $this->field('names', 'array'),
                    $this->field('title_assignments', 'array'),
                    $this->field('title_ids', 'array<int>'),
                    $this->field('gender', 'string', allowedValues: $this->enumValues(Gender::class)),
                    $this->field('bio', 'rich_text'),
                    $this->field('language_ids', 'array<string>', catalog: route('api.client.catalogs.languages')),
                    $this->field('institution_id', 'uuid', catalog: route('api.client.catalogs.submit-institutions')),
                    $this->field('institution_position', 'string', maxLength: 255),
                    $this->field('institutions', 'array<object>', catalog: route('api.client.catalogs.submit-institutions')),
                    $this->field('address', 'object'),
                    $this->field('address.country_id', 'uuid', catalog: route('api.client.catalogs.countries')),
                    $this->field('contactMethods', 'array<object>'),
                    $this->field('social_media', 'array<object>'),
                ],
                'conditional_rules' => [],
                'direct_edit_media_fields' => ['avatar', 'main', 'profile', 'cover', 'gallery'],
            ],
            $entity instanceof Reference => [
                'accepts_partial_updates' => true,
                'fields' => [
                    $this->field('title', 'string', maxLength: 255),
                    $this->field('author_ids', 'array<string>', catalog: route('api.client.catalogs.reference-authors')),
                    $this->field('type', 'string', allowedValues: $this->enumValues(ReferenceType::class)),
                    $this->field('parent_id', 'uuid'),
                    $this->field('record_kind', 'string', allowedValues: $this->enumValues(ReferenceRecordKind::class)),
                    $this->field('edition_number', 'integer'),
                    $this->field('edition_label', 'string', maxLength: 255),
                    $this->field('isbn', 'string', maxLength: 20),
                    $this->field('language', 'string', maxLength: 10),
                    $this->field('part_type', 'string', allowedValues: $this->enumValues(ReferencePartType::class)),
                    $this->field('part_number', 'string', maxLength: 255),
                    $this->field('part_label', 'string', maxLength: 255),
                    $this->field('publication_year', 'integer'),
                    $this->field('publisher', 'string', maxLength: 255),
                    $this->field('description', 'string'),
                    $this->field('url', 'url'),
                    $this->field('social_media', 'array<object>'),
                ],
                'conditional_rules' => [],
                'direct_edit_media_fields' => ['front_cover', 'back_cover', 'gallery'],
            ],
            $entity instanceof Event => [
                'accepts_partial_updates' => true,
                'fields' => [
                    $this->field('title', 'string', maxLength: 255),
                    $this->field('description', 'rich_text'),
                    $this->field('event_date', 'date'),
                    $this->field('prayer_time', 'string', allowedValues: $this->enumValues(EventPrayerTime::class)),
                    $this->field('custom_time', 'time'),
                    $this->field('end_time', 'time'),
                    $this->field('timezone', 'timezone'),
                    $this->field('event_category_ids', 'array<uuid>', allowedValues: array_keys(app(EventCategoryCatalog::class)->options())),
                    $this->field('gender', 'string', allowedValues: $this->enumValues(EventGenderRestriction::class)),
                    $this->field('age_group', 'array<string>', allowedValues: $this->enumValues(EventAgeGroup::class)),
                    $this->field('children_allowed', 'boolean'),
                    $this->field('is_muslim_only', 'boolean'),
                    $this->field('event_format', 'string', allowedValues: $this->enumValues(EventFormat::class)),
                    $this->field('visibility', 'string', allowedValues: $this->enumValues(EventVisibility::class)),
                    $this->field('event_url', 'url'),
                    $this->field('live_url', 'url'),
                    $this->field('recording_url', 'url'),
                    $this->field('primary_organizer_id', 'uuid'),
                    $this->field('location_same_as_institution', 'boolean'),
                    $this->field('location_type', 'string', allowedValues: ['institution', 'venue']),
                    $this->field('location_institution_id', 'uuid', catalog: route('api.client.catalogs.submit-institutions')),
                    $this->field('location_venue_id', 'uuid', catalog: route('api.client.catalogs.venues')),
                    $this->field('space_id', 'uuid', catalog: route('api.client.catalogs.spaces')),
                    $this->field('language_ids', 'array<string>', catalog: route('api.client.catalogs.languages')),
                    $this->field('domain_tags', 'array<string>', catalog: route('api.client.catalogs.taxonomy-terms', ['type' => EventTaxonomyCode::Domain->value])),
                    $this->field('discipline_tags', 'array<string>', catalog: route('api.client.catalogs.taxonomy-terms', ['type' => EventTaxonomyCode::Discipline->value])),
                    $this->field('source_tags', 'array<string>', catalog: route('api.client.catalogs.taxonomy-terms', ['type' => EventTaxonomyCode::Source->value])),
                    $this->field('issue_tags', 'array<string>', catalog: route('api.client.catalogs.taxonomy-terms', ['type' => EventTaxonomyCode::Issue->value])),
                    $this->field('reference_ids', 'array<string>', catalog: route('api.client.catalogs.references')),
                    $this->field('series_ids', 'array<string>'),
                    $this->field('person_ids', 'array<string>', catalog: route('api.client.catalogs.submit-persons')),
                    $this->field('other_key_people', 'array<object>'),
                ],
                'conditional_rules' => [
                    ['field' => 'custom_time', 'required_when' => ['prayer_time' => [EventPrayerTime::LainWaktu->value]]],
                    ['field' => 'location_type', 'required_when' => ['location_same_as_institution' => [false]]],
                    ['field' => 'location_institution_id', 'required_when' => ['location_type' => ['institution']]],
                    ['field' => 'location_venue_id', 'required_when' => ['location_type' => ['venue']]],
                ],
                'direct_edit_media_fields' => ['cover', 'poster', 'gallery'],
            ],
            default => throw new RuntimeException('Unsupported contribution entity type.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function updateRulesFor(Model $entity): array
    {
        return match (true) {
            $entity instanceof Institution => [
                'name' => ['sometimes', 'string', 'max:255'],
                'names' => ['nullable', 'array'],
                'type' => ['sometimes', Rule::in($this->enumValues(InstitutionType::class))],
                'description' => ['nullable'],
                'address' => ['sometimes', 'array'],
                'address.country_id' => ['required_with:address', 'uuid', Rule::exists(ModelResolver::countryClass(), 'id')],
                'address.state_id' => ['nullable', 'uuid', Rule::exists(ModelResolver::stateClass(), 'id')],
                'address.city_id' => ['nullable', 'uuid', Rule::exists(ModelResolver::cityClass(), 'id')],
                'address.area_assignments' => ['sometimes', 'array', new ValidAreaAssignmentRoles],
                'address.area_assignments.*' => ['nullable', 'uuid', Rule::exists(ModelResolver::areaClass(), 'id')],
                'address.line1' => ['nullable', 'string', 'max:255'],
                'address.line2' => ['nullable', 'string', 'max:255'],
                'address.postcode' => ['nullable', 'string', 'max:16'],
                'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'address.google_maps_url' => ['nullable', 'url', 'max:255'],
                'address.google_place_id' => ['nullable', 'string', 'max:255'],
                'address.google_feature_id' => ['nullable', 'string', 'max:255'],
                'address.google_cid' => ['nullable', 'string', 'max:255'],
                'address.google_entity_id' => ['nullable', 'string', 'max:255'],
                'address.waze_url' => ['nullable', 'url', 'max:255'],
                'contactMethods' => ['sometimes', 'array'],
                'contactMethods.*.type' => ['required_with:contactMethods.*.value', Rule::in($this->enumValues(ContactMethodType::class))],
                'contactMethods.*.value' => ['required_with:contactMethods.*.type', 'string', 'max:255'],
                'contactMethods.*.purpose' => ['nullable', Rule::in($this->enumValues(ContactPurpose::class))],
                'contactMethods.*.is_public' => ['nullable', 'boolean'],
                'social_media' => ['sometimes', 'array'],
                'social_media.*.platform' => ['required_with:social_media.*.handle,social_media.*.url', Rule::in($this->enumValues(SocialPlatform::class))],
                'social_media.*.handle' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
                'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.handle'],
                'donation_channels' => ['sometimes', 'array'],
                'donation_channels.*.label' => ['nullable', 'string', 'max:255'],
                'donation_channels.*.recipient' => ['required_with:donation_channels.*.method', 'string', 'max:255'],
                'donation_channels.*.method' => ['required_with:donation_channels.*.recipient', Rule::in(['bank_account', 'duitnow', 'ewallet'])],
                'donation_channels.*.bank_name' => ['nullable', 'string', 'max:255'],
                'donation_channels.*.bank_code' => ['nullable', 'string', 'max:255'],
                'donation_channels.*.account_number' => ['nullable', 'string', 'max:2000'],
                'donation_channels.*.duitnow_type' => ['nullable', 'string', 'max:255'],
                'donation_channels.*.duitnow_value' => ['nullable', 'string', 'max:2000'],
                'donation_channels.*.ewallet_provider' => ['nullable', 'string', 'max:255'],
                'donation_channels.*.ewallet_handle' => ['nullable', 'string', 'max:255'],
                'donation_channels.*.ewallet_qr_payload' => ['nullable', 'string', 'max:2000'],
                'donation_channels.*.reference_note' => ['nullable', 'string', 'max:255'],
                'donation_channels.*.is_default' => ['nullable', 'boolean'],
            ],
            $entity instanceof Person => [
                'name' => ['sometimes', 'string', 'max:255'],
                'family_name' => ['sometimes', 'nullable', 'string', 'max:100'],
                'names' => ['nullable', 'array'],
                'names.*.id' => ['nullable', 'uuid'],
                'names.*.name_type' => ['required', Rule::in($this->enumValues(PersonNameType::class))],
                'names.*.full_name' => ['required', 'string', 'max:255'],
                'names.*.language_code' => ['nullable', 'string', 'max:10'],
                'names.*.is_primary' => ['nullable', 'boolean'],
                'institutions' => ['nullable', 'array'],
                'institutions.*.institution_id' => ['required', 'uuid', 'exists:institutions,id'],
                'institutions.*.position' => ['nullable', 'string', 'max:255'],
                'institutions.*.is_primary' => ['nullable', 'boolean'],
                'title_ids' => ['nullable', 'array'],
                'title_ids.*' => ['uuid', 'exists:'.config('persons.database.tables.titles', 'titles').',id'],
                'title_assignments' => ['nullable', 'array'],
                'title_assignments.*.id' => ['nullable', 'uuid'],
                'title_assignments.*.title_id' => ['required', 'uuid', 'exists:'.config('persons.database.tables.titles', 'titles').',id'],
                'title_assignments.*.date_awarded' => ['nullable', 'date'],
                'title_assignments.*.date_expired' => ['nullable', 'date'],
                'title_assignments.*.status' => ['required', Rule::in($this->enumValues(AssignmentStatus::class))],
                'gender' => ['sometimes', Rule::in($this->enumValues(Gender::class))],
                'bio' => ['nullable'],
                'institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],
                'institution_position' => ['nullable', 'string', 'max:255'],
                'address' => ['sometimes', 'array'],
                'address.country_id' => ['required_with:address', 'uuid', Rule::exists(ModelResolver::countryClass(), 'id')],
                'address.state_id' => ['prohibited'],
                'address.city_id' => ['prohibited'],
                'address.area_assignments' => ['sometimes', 'array', new ValidAreaAssignmentRoles],
                'address.area_assignments.*' => ['nullable', 'uuid', Rule::exists(ModelResolver::areaClass(), 'id')],
                'address.line1' => ['prohibited'],
                'address.line2' => ['prohibited'],
                'address.postcode' => ['prohibited'],
                'address.latitude' => ['prohibited'],
                'address.longitude' => ['prohibited'],
                'address.google_maps_url' => ['prohibited'],
                'address.google_place_id' => ['prohibited'],
                'address.google_feature_id' => ['prohibited'],
                'address.google_cid' => ['prohibited'],
                'address.google_entity_id' => ['prohibited'],
                'address.waze_url' => ['prohibited'],
                'language_ids' => ['sometimes', 'array'],
                'language_ids.*' => ['string', 'exists:languages,id'],
                'contactMethods' => ['sometimes', 'array'],
                'contactMethods.*.type' => ['required_with:contactMethods.*.value', Rule::in($this->enumValues(ContactMethodType::class))],
                'contactMethods.*.value' => ['required_with:contactMethods.*.type', 'string', 'max:255'],
                'contactMethods.*.purpose' => ['nullable', Rule::in($this->enumValues(ContactPurpose::class))],
                'contactMethods.*.is_public' => ['nullable', 'boolean'],
                'social_media' => ['sometimes', 'array'],
                'social_media.*.platform' => ['required_with:social_media.*.handle,social_media.*.url', Rule::in($this->enumValues(SocialPlatform::class))],
                'social_media.*.handle' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
                'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.handle'],
            ],
            $entity instanceof Reference => [
                'title' => ['sometimes', 'string', 'max:255'],
                'author_ids' => ['sometimes', 'nullable', 'array'],
                'author_ids.*' => ['uuid', Rule::exists('persons', 'id')->whereIn('status', ['verified', 'pending'])],
                'type' => ['sometimes', Rule::in($this->enumValues(ReferenceType::class))],
                'parent_id' => ['nullable', 'uuid', Rule::exists('references', 'id')->where('type', ReferenceType::Book->value)->whereIn('record_kind', ['work', 'edition'])],
                'record_kind' => ['sometimes', Rule::enum(ReferenceRecordKind::class)],
                'edition_number' => ['nullable', 'integer', 'min:1'],
                'edition_label' => ['nullable', 'string', 'max:255'],
                'isbn' => ['nullable', 'string', 'max:20', new Isbn],
                'language' => ['nullable', 'string', 'max:10', Rule::exists('languages', 'code')],
                'part_type' => ['nullable', Rule::in($this->enumValues(ReferencePartType::class))],
                'part_number' => ['nullable', 'string', 'max:255'],
                'part_label' => ['nullable', 'string', 'max:255'],
                'publication_year' => ['nullable', 'integer', 'min:-3000', 'max:'.((int) now()->year + 5)],
                'publisher' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'url' => ['nullable', 'url:http,https', 'max:255'],
                'social_media' => ['sometimes', 'array'],
                'social_media.*.platform' => ['required_with:social_media.*.handle,social_media.*.url', Rule::in($this->enumValues(SocialPlatform::class))],
                'social_media.*.handle' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
                'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.handle'],
            ],
            $entity instanceof Event => [
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable'],
                'event_date' => ['sometimes', 'date'],
                'prayer_time' => ['sometimes', Rule::in($this->enumValues(EventPrayerTime::class))],
                'custom_time' => ['nullable', 'date_format:H:i'],
                'end_time' => ['nullable', 'date_format:H:i'],
                'timezone' => ['sometimes', 'timezone'],
                'event_category_ids' => ['sometimes', 'array'],
                'event_category_ids.*' => ['uuid', Rule::in(array_keys(app(EventCategoryCatalog::class)->options()))],
                'gender' => ['sometimes', Rule::in($this->enumValues(EventGenderRestriction::class))],
                'age_group' => ['sometimes', 'array'],
                'age_group.*' => ['string', Rule::in($this->enumValues(EventAgeGroup::class))],
                'children_allowed' => ['nullable', 'boolean'],
                'is_muslim_only' => ['nullable', 'boolean'],
                'event_format' => ['sometimes', Rule::in($this->enumValues(EventFormat::class))],
                'visibility' => ['sometimes', Rule::in($this->enumValues(EventVisibility::class))],
                'event_url' => ['nullable', 'url', 'max:255'],
                'live_url' => ['nullable', 'url', 'max:255'],
                'recording_url' => ['nullable', 'url', 'max:255'],
                'primary_organizer_id' => ['sometimes', 'nullable', 'uuid'],
                'location_same_as_institution' => ['sometimes', 'boolean'],
                'location_type' => ['sometimes', 'string', Rule::in(['institution', 'venue'])],
                'location_institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],
                'location_venue_id' => ['nullable', 'uuid', 'exists:venues,id'],
                'language_ids' => ['sometimes', 'array'],
                'language_ids.*' => ['string', 'exists:languages,id'],
                'domain_tags' => ['sometimes', 'array'],
                'domain_tags.*' => ['string', 'max:120'],
                'discipline_tags' => ['sometimes', 'array'],
                'discipline_tags.*' => ['string', 'max:120'],
                'source_tags' => ['sometimes', 'array'],
                'source_tags.*' => ['string', 'max:120'],
                'issue_tags' => ['sometimes', 'array'],
                'issue_tags.*' => ['string', 'max:120'],
                'reference_ids' => ['sometimes', 'array'],
                'reference_ids.*' => ['uuid', 'exists:references,id'],
                'series_ids' => ['sometimes', 'array'],
                'series_ids.*' => ['uuid', 'exists:series,id'],
                'person_ids' => ['sometimes', 'array'],
                'person_ids.*' => ['uuid', 'exists:persons,id'],
                'other_key_people' => ['sometimes', 'array'],
                'other_key_people.*.role_code' => ['required_with:other_key_people.*.display_name,other_key_people.*.involveable_id', Rule::in($this->enumValues(EventKeyPersonRole::class))],
                'other_key_people.*.involveable_type' => ['nullable', 'string', 'max:255'],
                'other_key_people.*.involveable_id' => ['nullable', 'uuid', 'exists:persons,id', 'required_without:other_key_people.*.display_name'],
                'other_key_people.*.display_name' => ['nullable', 'string', 'max:255', 'required_without:other_key_people.*.involveable_id'],
                'other_key_people.*.visibility' => ['nullable', Rule::in(['public', 'private'])],
                'other_key_people.*.notes' => ['nullable', 'string', 'max:1000'],
            ],
            default => throw new RuntimeException('Unsupported contribution entity type.'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createInstitution(array $payload, User $proposer): Institution
    {
        $institutionId = $this->institutionWorkflow->createFromContribution($payload, (string) $proposer->getKey());

        return Institution::query()->findOrFail($institutionId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPerson(array $payload, User $proposer): Person
    {
        $personId = $this->personWorkflow->createFromContribution($payload, (string) $proposer->getKey());

        return Person::query()->findOrFail($personId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createReference(array $payload, User $proposer): Reference
    {
        $referenceId = $this->referenceWorkflow->createFromContribution($payload);

        return Reference::query()->withoutOwnerScope()->findOrFail($referenceId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(Model $entity, array $payload): array
    {
        return match (true) {
            $entity instanceof Institution => $this->applyInstitution($entity, $payload),
            $entity instanceof Person => $this->applyPerson($entity, $payload),
            $entity instanceof Reference => $this->referenceWorkflow->applyContribution((string) $entity->getKey(), $payload),
            $entity instanceof Event => $this->authoring->applyContributionUpdate((string) $entity->getKey(), $payload),
            default => throw new RuntimeException('Unsupported contribution entity type.'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyInstitution(Institution $institution, array $payload): array
    {
        return $this->institutionWorkflow->applyContribution((string) $institution->getKey(), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyPerson(Person $person, array $payload): array
    {
        return $this->personWorkflow->applyContribution((string) $person->getKey(), $payload);
    }

    private function eventPrayerDate(Event $event): ?string
    {
        $meta = $event->timeExpressions->firstWhere('anchor_type', 'prayer')?->metadata['prayer'] ?? null;

        return is_array($meta)
            ? $this->authoring->normalizePrayerDateString($meta['prayer_date'] ?? null)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function institutionState(Institution $institution): array
    {
        $institution->loadMissing(['addresses', 'contactMethods', 'socialProfiles', 'names', 'donationChannels']);

        return [
            'name' => $institution->name,
            'names' => $institution->names->map(fn (InstitutionName $n): array => [
                'full_name' => $n->full_name,
                'name_type' => $n->name_type instanceof InstitutionNameType ? $n->name_type->value : (string) $n->name_type,
                'language_code' => $n->language_code,
                'is_primary' => (bool) $n->is_primary,
            ])->values()->all(),
            'type' => $institution->type instanceof BackedEnum ? $institution->type->value : (string) $institution->type,
            'description' => $institution->description,
            'address' => $this->addressState($institution->primaryAddress()),
            'contactMethods' => $this->contactMethodsState($institution->contactMethods),
            'social_media' => $this->socialMediaState($institution->socialProfiles),
            'donation_channels' => $this->donationChannelsState($institution->donationChannels),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personState(Person $person): array
    {
        $person->loadMissing([
            'addresses',
            'contactMethods',
            'socialProfiles',
            'languages',
            'names',
            'titleAssignments',
            'institutions',
        ]);

        return [
            'name' => $person->name,
            'family_name' => $person->family_name,
            'middle_name' => $person->middle_name,
            'names' => $person->names->map(fn (Model $name): array => [
                'id' => (string) $name->getKey(),
                'name_type' => $name->name_type instanceof PersonNameType ? $name->name_type->value : (string) $name->name_type,
                'full_name' => $name->full_name,
                'language_code' => $name->language_code,
                'is_primary' => (bool) $name->is_primary,
            ])->values()->all(),
            'title_assignments' => $person->titleAssignments->map(fn (Model $assignment): array => [
                'id' => (string) $assignment->getKey(),
                'title_id' => (string) $assignment->title_id,
                'date_awarded' => $assignment->date_awarded?->format('Y-m-d'),
                'date_expired' => $assignment->date_expired?->format('Y-m-d'),
                'status' => $assignment->status instanceof AssignmentStatus ? $assignment->status->value : (string) $assignment->status,
            ])->values()->all(),
            'title_ids' => $person->titleAssignments->pluck('title_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'gender' => $person->gender instanceof BackedEnum ? $person->gender->value : '',
            'bio' => $person->bio,
            'language_ids' => $person->languages->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'institutions' => $person->institutions->map(fn (Institution $institution): array => [
                'institution_id' => (string) $institution->getKey(),
                'institution_name' => $institution->name,
                'position' => $this->institutionPivotPosition($institution),
                'is_primary' => $this->institutionPivotIsPrimary($institution),
            ])->values()->all(),
            'address' => $this->addressState($person->primaryAddress()),
            'contactMethods' => $this->contactMethodsState($person->contactMethods),
            'social_media' => $this->socialMediaState($person->socialProfiles),
        ];
    }

    private function institutionPivotPosition(?Institution $institution): ?string
    {
        if (! $institution instanceof Institution) {
            return null;
        }

        $position = data_get($institution->getRelations(), 'pivot.position');

        return is_string($position) && $position !== '' ? $position : null;
    }

    private function institutionPivotIsPrimary(Institution $institution): bool
    {
        return (bool) data_get($institution->getRelations(), 'pivot.is_primary', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceState(Reference $reference): array
    {
        $reference->loadMissing(['socialProfiles']);

        return [
            'title' => $reference->title,
            'author_ids' => $reference->authorIdsValue(),
            'type' => $reference->type,
            'parent_id' => $reference->parent_id,
            'record_kind' => $reference->record_kind,
            'edition_number' => $reference->edition_number,
            'edition_label' => $reference->edition_label,
            'isbn' => $reference->isbn,
            'language' => $reference->language,

            'part_type' => $reference->partTypeValue(),
            'part_number' => $reference->partNumberValue(),
            'part_label' => $reference->partLabelValue(),
            'publication_year' => $reference->year,
            'publisher' => $reference->publisher,
            'description' => $reference->description,
            'url' => $reference->url,
            'social_media' => $this->socialMediaState($reference->socialProfiles),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventState(Event $event): array
    {
        $event->loadMissing([
            'references',
            'series',
            'classifications',
            'keyPeople.person',
            'languages:id,event_id',
            'primaryOccurrence',
            'primaryOrganizerInvolvement',
            'primaryLocation',
            'audiences',
            'audienceProfiles',
            'timeExpressions',
            'links',
        ]);

        $tags = $event->classifications->groupBy('taxonomy_code');

        return [
            'title' => $event->title,
            'description' => $event->description,
            'starts_at' => $event->starts_at?->toDateTimeString(),
            'ends_at' => $event->ends_at?->toDateTimeString(),
            'timezone' => $event->timezone,
            'timing_mode' => $event->timing_mode instanceof BackedEnum
                ? $event->timing_mode->value
                : (is_string($event->timing_mode) && $event->timing_mode !== '' ? $event->timing_mode : null),
            'prayer_reference' => $event->prayer_reference instanceof BackedEnum
                ? $event->prayer_reference->value
                : (is_string($event->prayer_reference) && $event->prayer_reference !== '' ? $event->prayer_reference : null),
            'prayer_offset' => $event->prayer_offset instanceof BackedEnum
                ? $event->prayer_offset->value
                : (is_string($event->prayer_offset) && $event->prayer_offset !== '' ? $event->prayer_offset : null),
            'prayer_display_text' => $event->prayer_display_text,
            // The persisted prayer day restores the form's event_date for
            // overnight starts (whose local day differs from the prayer
            // day) before helper-field derivation in UI/API/originals.
            'prayer_date' => $this->eventPrayerDate($event),
            'event_category_ids' => $event->classifications->where('taxonomy_code', EventCategoryCatalog::TAXONOMY_CODE)->pluck('event_term_id')->values()->all(),
            'gender' => $event->gender instanceof BackedEnum ? $event->gender->value : (string) $event->gender,
            'age_group' => $this->enumCollectionValues($event->age_group),
            'children_allowed' => (bool) $event->children_allowed,
            'is_muslim_only' => (bool) $event->is_muslim_only,
            'event_format' => $event->delivery_mode instanceof BackedEnum ? $event->delivery_mode->value : (string) $event->delivery_mode,
            'visibility' => $event->visibility instanceof BackedEnum ? $event->visibility->value : (string) $event->visibility,
            'event_url' => $event->event_url,
            'live_url' => $event->live_url,
            'recording_url' => $event->recording_url,
            'primary_organizer_id' => $event->primaryOrganizerInvolvement?->involveable_id,
            'institution_id' => $event->institution_id,
            'venue_id' => $event->default_venue_id,
            'space_id' => $event->primaryLocation?->venue_space_id,
            'language_ids' => $event->languages->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'domain_tags' => $tags->get(EventTaxonomyCode::Domain->value, collect())->pluck('event_term_id')->values()->all(),
            'discipline_tags' => $tags->get(EventTaxonomyCode::Discipline->value, collect())->pluck('event_term_id')->values()->all(),
            'source_tags' => $tags->get(EventTaxonomyCode::Source->value, collect())->pluck('event_term_id')->values()->all(),
            'issue_tags' => $tags->get(EventTaxonomyCode::Issue->value, collect())->pluck('event_term_id')->values()->all(),
            'reference_ids' => $event->references->pluck('id')->values()->all(),
            'series_ids' => $event->series->pluck('id')->values()->all(),
            'person_ids' => $event->keyPeople
                ->where('role_code', EventKeyPersonRole::Speaker->value)
                ->pluck('involveable_id')
                ->filter(fn (mixed $personId): bool => is_string($personId) && $personId !== '')
                ->values()
                ->all(),
            'other_key_people' => $event->keyPeople
                ->reject(fn ($keyPerson): bool => $keyPerson->role_code === EventKeyPersonRole::Speaker->value)
                ->map(fn ($keyPerson): array => [
                    'role_code' => (string) $keyPerson->role_code,
                    'involveable_type' => $keyPerson->involveable_type,
                    'involveable_id' => $keyPerson->involveable_id,
                    'display_name' => $keyPerson->display_name,
                    'visibility' => $keyPerson->visibility ?? 'public',
                    'notes' => $keyPerson->notes,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function venueState(Venue $venue): array
    {
        $venue->loadMissing(['addresses', 'contactMethods', 'socialProfiles', 'facilities.facilityType']);

        return [
            'name' => $venue->name,
            'venue_type' => $venue->venue_type instanceof BackedEnum ? $venue->venue_type->value : (string) $venue->venue_type,
            'facilities' => $venue->facilities
                ->map(static function (VenueFacility $facility): string {
                    $facilityType = $facility->getRelation('facilityType');

                    return $facilityType instanceof FacilityType ? $facilityType->code : '';
                })
                ->filter(static fn (string $code): bool => $code !== '')
                ->values()
                ->all(),
            'address' => $this->addressState($venue->primaryAddress()),
            'contactMethods' => $this->contactMethodsState($venue->contactMethods),
            'social_media' => $this->socialMediaState($venue->socialProfiles),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncInstitutionNames(Institution $institution, array $payload): void
    {
        $names = $payload['names'] ?? null;

        if (! is_array($names)) {
            return;
        }

        $this->institutionWorkflow->syncNames((string) $institution->getKey(), $names);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncInstitutionRelations(Institution $institution, array $payload): void
    {
        $this->institutionWorkflow->syncRelations((string) $institution->getKey(), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncPersonRelations(Person $person, array $payload): void
    {
        $this->personWorkflow->syncRelations((string) $person->getKey(), $payload);
    }

    public function syncPersonNames(Person $person, mixed $namesPayload): void
    {
        $this->personWorkflow->syncNames((string) $person->getKey(), $namesPayload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncReferenceRelations(Reference $reference, array $payload): void
    {
        $this->referenceWorkflow->syncRelations((string) $reference->getKey(), $payload);
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     * @return list<string|int>
     */
    private function enumValues(string $enumClass): array
    {
        return array_map(
            static fn (BackedEnum $case): string|int => $case->value,
            $enumClass::cases(),
        );
    }

    /**
     * @param  list<string|int>|null  $allowedValues
     * @return array<string, mixed>
     */
    private function field(
        string $name,
        string $type,
        ?int $maxLength = null,
        ?array $allowedValues = null,
        ?string $catalog = null,
    ): array {
        return array_filter([
            'name' => $name,
            'type' => $type,
            'required' => false,
            'max_length' => $maxLength,
            'allowed_values' => $allowedValues,
            'catalog' => $catalog,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  iterable<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeStringArray(iterable $values): array
    {
        return collect($values)
            ->map(function (mixed $value): ?string {
                if ($value instanceof BackedEnum) {
                    return trim((string) $value->value) ?: null;
                }

                return is_string($value) && trim($value) !== '' ? trim($value) : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function enumCollectionValues(mixed $collection): array
    {
        if ($collection instanceof Collection) {
            return $collection
                ->map(fn (mixed $value): ?string => $value instanceof BackedEnum ? $value->value : (is_string($value) ? $value : null))
                ->filter()
                ->values()
                ->all();
        }

        if (is_array($collection)) {
            return $this->normalizeStringArray($collection);
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function addressState(?Address $address): array
    {
        if (! $address instanceof Address) {
            return SharedFormSchema::hydrateAddressFormState([
                'country_id' => null,
                'area_assignments' => [],
                'line1' => null,
                'line2' => null,
                'postcode' => null,
                'latitude' => null,
                'longitude' => null,
                'google_maps_url' => null,
                'google_place_id' => null,
                'google_feature_id' => null,
                'google_cid' => null,
                'google_entity_id' => null,
                'waze_url' => null,
            ]);
        }

        return SharedFormSchema::hydrateAddressFormState([
            'country_id' => $address->country_id ?? $this->addressingCountryResolver->resolveId($address->country_code),
            'state_id' => $address->state_id,
            'city_id' => $address->city_id,
            'state' => $address->state,
            'city' => $address->city,
            'area_assignments' => AddressAssignments::forAddress($address),
            'line1' => $address->line1,
            'line2' => $address->line2,
            'postcode' => $address->postcode,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
            'google_maps_url' => $address->google_maps_url,
            'google_place_id' => $address->google_place_id,
            'google_feature_id' => $address->google_feature_id,
            'google_cid' => $address->google_cid,
            'google_entity_id' => $address->google_entity_id,
            'waze_url' => $address->waze_url,
        ]);
    }

    /**
     * @param  Collection<int, ContactMethod>  $contacts
     * @return list<array<string, mixed>>
     */
    private function contactMethodsState(Collection $contacts): array
    {
        return $contacts
            ->sortBy('created_at')
            ->map(fn (ContactMethod $contact): array => [
                'type' => $contact->type instanceof BackedEnum ? $contact->type->value : (string) $contact->type,
                'purpose' => $contact->purpose instanceof BackedEnum ? $contact->purpose->value : (string) $contact->purpose,
                'value' => $contact->value,
                'is_primary' => (bool) $contact->is_primary,
                'is_public' => (bool) $contact->is_public,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, SocialProfile>  $entries
     * @return list<array<string, mixed>>
     */
    private function socialMediaState(Collection $entries): array
    {
        return $entries
            ->sortBy('created_at')
            ->map(fn (SocialProfile $entry): array => [
                'platform' => $entry->platform instanceof BackedEnum ? $entry->platform->value : (string) $entry->platform,
                'handle' => $entry->handle,
                'url' => $entry->url,
                'is_primary' => (bool) $entry->is_primary,
                'is_public' => (bool) $entry->is_public,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, DonationChannel>  $channels
     * @return list<array<string, mixed>>
     */
    private function donationChannelsState(Collection $channels): array
    {
        return $channels
            ->sortBy('created_at')
            ->map(fn (DonationChannel $channel): array => $this->donationChannelRow($channel))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function donationChannelRow(DonationChannel $channel): array
    {
        return [
            'label' => $channel->label,
            'recipient' => $channel->recipient,
            'method' => $channel->method,
            'bank_code' => $channel->bank_code,
            'bank_name' => $channel->bank_name,
            'account_number' => $channel->account_number,
            'duitnow_type' => $channel->duitnow_type,
            'duitnow_value' => $channel->duitnow_value,
            'ewallet_provider' => $channel->ewallet_provider,
            'ewallet_handle' => $channel->ewallet_handle,
            'ewallet_qr_payload' => $channel->ewallet_qr_payload,
            'reference_note' => $channel->reference_note,
            'is_default' => (bool) $channel->is_default,
        ];
    }
}
