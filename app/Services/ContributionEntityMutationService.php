<?php

namespace App\Services;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\State;
use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\Addressing\Support\ModelResolver;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Contacting\Models\ContactMethod;
use AIArmada\Contacting\Models\SocialProfile;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\FacilityType;
use AIArmada\Events\Models\VenueFacility;
use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Persons\Enums\AffiliationType;
use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Enums\Gender;
use AIArmada\Persons\Enums\PersonNameType;
use AIArmada\Persons\Models\PersonName;
use AIArmada\Persons\Models\TitleAssignment;
use AIArmada\References\Enums\ReferenceContributorRole;
use AIArmada\References\Enums\ReferenceRecordKind;
use AIArmada\References\Rules\Isbn;
use App\Actions\Events\Authoring\SyncEventClassificationsAction;
use App\Actions\Events\Scheduling\SyncEventScheduleAction;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\SpaceEligibilityResolver;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventTaxonomyCode;
use App\Enums\EventVisibility;
use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Enums\TimingMode;
use App\Features\Directory\Api\InstitutionWorkflow;
use App\Forms\SharedFormSchema;
use App\Models\Affiliation;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\Institution;
use App\Models\InstitutionName;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\User;
use App\Models\Venue;
use App\Platform\Directory\EntityRelationSync;
use App\Platform\Location\AddressAssignments;
use App\Rules\ValidAreaAssignmentRoles;
use App\Shared\Directory\Enums\InstitutionNameType;
use App\Shared\Directory\Enums\InstitutionType;
use App\Shared\Prayer\EventPrayerTime;
use App\Shared\Prayer\PrayerOffset;
use App\Shared\Support\MorphAlias;
use App\Support\Events\AdminEventTimeMapper;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ContributionEntityMutationService
{
    public function __construct(
        private readonly EventKeyPersonSyncService $eventKeyPersonSyncService,
        private readonly AddMemberAction $addMemberAction,
        private readonly GeneratePersonSlugAction $generatePersonSlugAction,
        private readonly AddressCountryResolver $addressingCountryResolver,
        private readonly SpaceEligibilityResolver $spaceEligibilityResolver,
        private readonly EntityRelationSync $entityRelationSync,
        private readonly InstitutionWorkflow $institutionWorkflow,
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
        $person = Person::create([
            'name' => (string) ($payload['name'] ?? 'Person'),
            'middle_name' => $payload['middle_name'] ?? null,
            'family_name' => $payload['family_name'] ?? null,
            'gender' => $this->normalizeGender($payload['gender'] ?? null),
            'bio' => $payload['bio'] ?? null,
            'slug' => $this->generatePersonSlugAction->handle(
                (string) ($payload['name'] ?? 'Person'),
                $payload,
            ),
            'status' => 'pending',
            'allow_public_event_submission' => true,
        ]);

        $this->addMemberAction->handle($person, $proposer, MemberRole::Owner);

        $this->syncPersonRelations($person, $payload);
        $this->generatePersonSlugAction->syncPersonSlug($person);

        return $person;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createReference(array $payload, User $proposer): Reference
    {
        $reference = new Reference;
        $reference->status = 'pending';

        $this->applyReference($reference, $payload);

        return $reference;
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
            $entity instanceof Reference => $this->applyReference($entity, $payload),
            $entity instanceof Event => $this->applyEvent($entity, $payload),
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
        $person->fill([
            'name' => $payload['name'] ?? $person->name,
            'middle_name' => array_key_exists('middle_name', $payload) ? $payload['middle_name'] : $person->middle_name,
            'family_name' => array_key_exists('family_name', $payload) ? $payload['family_name'] : $person->family_name,
            'gender' => array_key_exists('gender', $payload)
                ? $this->normalizeGender($payload['gender'])
                : $person->gender,
            'bio' => array_key_exists('bio', $payload) ? $payload['bio'] : $person->bio,
        ]);

        $dirty = $person->getDirty();
        $person->save();

        $this->syncPersonRelations($person, $payload);
        $this->generatePersonSlugAction->syncPersonSlug($person);

        return $dirty;
    }

    private function normalizeGender(mixed $value): string
    {
        if ($value instanceof Gender) {
            return $value->value;
        }

        if (is_string($value) && Gender::tryFrom($value) instanceof Gender) {
            return $value;
        }

        return Gender::Male->value;
    }

    private function normalizeReferenceType(mixed $value): string
    {
        if ($value instanceof ReferenceType) {
            return $value->value;
        }

        if (is_string($value) && ReferenceType::tryFrom($value) instanceof ReferenceType) {
            return $value;
        }

        return ReferenceType::Book->value;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeMetaString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function eventPrayerDate(Event $event): ?string
    {
        $meta = $event->timeExpressions->firstWhere('anchor_type', 'prayer')?->metadata['prayer'] ?? null;

        return is_array($meta)
            ? AdminEventTimeMapper::normalizePrayerDateString($meta['prayer_date'] ?? null)
            : null;
    }

    private function resolveProvenanceFloat(mixed $payloadValue, mixed $metaValue, bool $payloadHasKey): ?float
    {
        if ($payloadHasKey) {
            return is_numeric($payloadValue) ? (float) $payloadValue : null;
        }

        return is_numeric($metaValue) ? (float) $metaValue : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyReference(Reference $reference, array $payload): array
    {
        $reference->fill([
            'title' => $payload['title'] ?? $reference->title,
            'type' => array_key_exists('type', $payload) ? $this->normalizeReferenceType($payload['type']) : $reference->type,
            'parent_id' => array_key_exists('parent_id', $payload) ? $this->normalizeOptionalString($payload['parent_id']) : $reference->parent_id,
            'record_kind' => $payload['record_kind'] ?? $reference->record_kind,
            'edition_number' => array_key_exists('edition_number', $payload) ? $payload['edition_number'] : $reference->edition_number,
            'edition_label' => array_key_exists('edition_label', $payload) ? $this->normalizeOptionalString($payload['edition_label']) : $reference->edition_label,
            'isbn' => array_key_exists('isbn', $payload) ? $this->normalizeOptionalString($payload['isbn']) : $reference->isbn,
            'language' => array_key_exists('language', $payload) ? $this->normalizeOptionalString($payload['language']) : $reference->language,

            'part_type' => array_key_exists('part_type', $payload) ? $this->normalizeOptionalString($payload['part_type']) : $reference->partTypeValue(),
            'part_number' => array_key_exists('part_number', $payload) ? $this->normalizeOptionalString($payload['part_number']) : $reference->partNumberValue(),
            'part_label' => array_key_exists('part_label', $payload) ? $this->normalizeOptionalString($payload['part_label']) : $reference->partLabelValue(),
            'year' => array_key_exists('publication_year', $payload) ? (filled($payload['publication_year']) ? (int) $payload['publication_year'] : null) : $reference->year,
            'publisher' => array_key_exists('publisher', $payload) ? $this->normalizeOptionalString($payload['publisher']) : $reference->publisher,
            'description' => array_key_exists('description', $payload) ? $payload['description'] : $reference->description,
            'url' => array_key_exists('url', $payload) ? $this->normalizeOptionalString($payload['url']) : $reference->url,
        ]);

        $dirty = $reference->getDirty();
        $reference->save();

        $this->syncReferenceRelations($reference, array_intersect_key($payload, ['social_media' => true, 'author_ids' => true]));

        return $dirty;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyEvent(Event $event, array $payload): array
    {
        $event->loadMissing(['primaryOccurrence', 'timeExpressions', 'locations']);
        $currentOccurrence = $event->primaryOccurrence;
        $currentExpression = $event->timeExpressions->firstWhere('anchor_type', 'prayer');

        $event->forceFill([
            'title' => $payload['title'] ?? $event->title,
            'description' => array_key_exists('description', $payload) ? $payload['description'] : $event->description,
            'timezone' => array_key_exists('timezone', $payload) ? $payload['timezone'] : $event->timezone,
            'gender' => array_key_exists('gender', $payload) ? $payload['gender'] : $event->gender,
            'age_group' => array_key_exists('age_group', $payload) ? $this->normalizeStringArray($payload['age_group']) : $event->age_group,
            'children_allowed' => array_key_exists('children_allowed', $payload) ? (bool) $payload['children_allowed'] : $event->children_allowed,
            'is_muslim_only' => array_key_exists('is_muslim_only', $payload) ? (bool) $payload['is_muslim_only'] : $event->is_muslim_only,
            'delivery_mode' => array_key_exists('event_format', $payload) ? $payload['event_format'] : $event->delivery_mode,
            'visibility' => array_key_exists('visibility', $payload) ? $payload['visibility'] : $event->visibility,
            'event_url' => array_key_exists('event_url', $payload) ? $this->normalizeOptionalString($payload['event_url']) : $event->event_url,
            'live_url' => array_key_exists('live_url', $payload) ? $this->normalizeOptionalString($payload['live_url']) : $event->live_url,
            'recording_url' => array_key_exists('recording_url', $payload) ? $this->normalizeOptionalString($payload['recording_url']) : $event->recording_url,
            'institution_id' => array_key_exists('institution_id', $payload) ? $this->normalizeOptionalString($payload['institution_id']) : $event->institution_id,
            'default_venue_id' => array_key_exists('venue_id', $payload) ? $this->normalizeOptionalString($payload['venue_id']) : $event->default_venue_id,
        ]);

        $spaceIds = [];
        if (array_key_exists('space_ids', $payload)) {
            $spaceIds = is_array($payload['space_ids']) ? array_values(array_filter($payload['space_ids'], is_string(...))) : [];
        } else {
            $existing = $event->locations
                ->whereNull('event_occurrence_id')
                ->whereNull('event_session_id')
                ->sortBy('sort_order');
            $spaceIds = $existing->pluck('venue_space_id')->filter()->map(strval(...))->values()->all();
        }

        if ($spaceIds !== []) {
            if ($event->default_venue_id !== null) {
                $this->spaceEligibilityResolver->validateVenueSelection((string) $event->default_venue_id, $spaceIds);
            } elseif ($event->institution_id !== null) {
                $this->spaceEligibilityResolver->validateInstitutionSelection((string) $event->institution_id, $spaceIds);
            } else {
                throw ValidationException::withMessages([
                    'space_ids' => __('Ruang memerlukan institusi atau venue.'),
                ]);
            }
        }

        $event->syncLocation($event->default_venue_id, $spaceIds);

        $dirty = $event->getDirty();
        $event->save();

        $scheduleKind = $payload['schedule_kind'] ?? $event->schedule_kind;
        $scheduleKind = $scheduleKind instanceof ScheduleKind
            ? $scheduleKind
            : (ScheduleKind::tryFrom((string) $scheduleKind) ?? ScheduleKind::Single);
        $timingMode = $payload['timing_mode'] ?? ($currentExpression !== null ? TimingMode::PrayerRelative->value : TimingMode::Absolute->value);
        $timingMode = $timingMode instanceof TimingMode
            ? $timingMode
            : TimingMode::tryFrom((string) $timingMode);
        $currentSignedOffset = $currentExpression?->offset_minutes === null
            ? null
            : ($currentExpression->relation === 'before' ? -$currentExpression->offset_minutes : $currentExpression->offset_minutes);
        $prayerOffset = $payload['prayer_offset'] ?? $currentSignedOffset;
        $prayerOffset = $prayerOffset instanceof PrayerOffset
            ? $prayerOffset->minutes()
            : (is_numeric($prayerOffset)
                ? (int) $prayerOffset
                : PrayerOffset::tryFrom((string) $prayerOffset)?->minutes());

        $scheduleTimezone = is_string($event->timezone) && $event->timezone !== ''
            ? $event->timezone
            : 'UTC';
        // Persistence-state strings are UTC wall time: the contribution
        // mapper stringifies UTC Carbons, and the package writer stores
        // DateTime input as an absolute moment. Parsing them as schedule
        // local time double-shifts by the UTC offset.
        $startsAt = array_key_exists('starts_at', $payload) ? $payload['starts_at'] : $currentOccurrence?->starts_at;
        $startsAt = $startsAt instanceof CarbonInterface
            ? $startsAt
            : (is_string($startsAt) && $startsAt !== '' ? Carbon::parse($startsAt, 'UTC') : null);
        $endsAt = array_key_exists('ends_at', $payload) ? $payload['ends_at'] : $currentOccurrence?->ends_at;
        $endsAt = $endsAt instanceof CarbonInterface
            ? $endsAt
            : (is_string($endsAt) && $endsAt !== '' ? Carbon::parse($endsAt, 'UTC') : null);

        // An explicit null reference is a real change (Tarawih stores a null
        // anchor); only an omitted key falls back to the current anchor.
        $prayerReference = array_key_exists('prayer_reference', $payload)
            ? $payload['prayer_reference']
            : $currentExpression?->anchor_code;

        $currentPrayerMeta = $currentExpression?->metadata['prayer'] ?? null;
        $currentPrayerMeta = is_array($currentPrayerMeta) ? $currentPrayerMeta : [];

        app(SyncEventScheduleAction::class)->execute(
            event: $event,
            scheduleKind: $scheduleKind,
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $scheduleTimezone,
            timingMode: $timingMode,
            prayerReference: $prayerReference,
            prayerOffset: $prayerOffset,
            prayerDisplayText: array_key_exists('prayer_display_text', $payload)
                ? $this->normalizeOptionalString($payload['prayer_display_text'])
                : $currentExpression?->display_label,
            prayerSource: array_key_exists('prayer_source', $payload)
                ? $this->normalizeOptionalString($payload['prayer_source'])
                : $this->normalizeMetaString($currentPrayerMeta['source'] ?? null),
            prayerFetchedAt: array_key_exists('prayer_fetched_at', $payload)
                ? $this->normalizeOptionalString($payload['prayer_fetched_at'])
                : $this->normalizeMetaString($currentPrayerMeta['fetched_at'] ?? null),
            prayerZone: array_key_exists('prayer_zone', $payload)
                ? $this->normalizeOptionalString($payload['prayer_zone'])
                : $this->normalizeMetaString($currentPrayerMeta['zone'] ?? null),
            prayerDate: array_key_exists('prayer_date', $payload) && is_string($payload['prayer_date'])
                ? $payload['prayer_date']
                : $this->normalizeMetaString($currentPrayerMeta['prayer_date'] ?? null),
            prayerLat: $this->resolveProvenanceFloat($payload['prayer_lat'] ?? null, $currentPrayerMeta['lat'] ?? null, array_key_exists('prayer_lat', $payload)),
            prayerLng: $this->resolveProvenanceFloat($payload['prayer_lng'] ?? null, $currentPrayerMeta['lng'] ?? null, array_key_exists('prayer_lng', $payload)),
            prayerVenueId: array_key_exists('prayer_venue_id', $payload)
                ? $this->normalizeOptionalString($payload['prayer_venue_id'])
                : $this->normalizeMetaString($currentPrayerMeta['venue_id'] ?? null),
            prayerInstitutionId: array_key_exists('prayer_institution_id', $payload)
                ? $this->normalizeOptionalString($payload['prayer_institution_id'])
                : $this->normalizeMetaString($currentPrayerMeta['institution_id'] ?? null),
            prayerCountry: array_key_exists('prayer_country', $payload)
                ? $this->normalizeOptionalString($payload['prayer_country'])
                : $this->normalizeMetaString($currentPrayerMeta['country'] ?? null),
        );

        if (array_key_exists('primary_organizer_id', $payload)) {
            $organizerId = $this->normalizeOptionalString($payload['primary_organizer_id']);
            $organizer = $organizerId === null
                ? null
                : (Institution::query()->find($organizerId) ?? Person::query()->find($organizerId));

            $event->setPrimaryOrganizer($organizer);
        }

        if (array_key_exists('language_ids', $payload)) {
            $languageIds = $this->normalizeStringArray($payload['language_ids']);
            $event->auditSync('languages', $languageIds, true, ['languages.id', 'languages.name']);
        }

        if (array_key_exists('reference_ids', $payload)) {
            $referenceIds = $this->normalizeStringArray($payload['reference_ids']);
            $event->auditSync('references', $referenceIds, true, ['references.id', 'references.title']);
        }

        if (array_key_exists('series_ids', $payload)) {
            $seriesIds = $this->normalizeStringArray($payload['series_ids']);
            $series = new Series;
            $event->auditSync('series', $seriesIds, true, [$series->qualifyColumn('id'), $series->qualifyColumn('title')]);
        }

        if (
            array_key_exists('person_ids', $payload)
            || array_key_exists('other_key_people', $payload)
        ) {
            $this->eventKeyPersonSyncService->sync(
                $event,
                $this->normalizeStringArray($payload['person_ids'] ?? []),
                $this->normalizeKeyPeople($payload['other_key_people'] ?? []),
            );
        }

        if (
            array_key_exists('event_category_ids', $payload)
            || array_key_exists('domain_tags', $payload)
            || array_key_exists('discipline_tags', $payload)
            || array_key_exists('source_tags', $payload)
            || array_key_exists('issue_tags', $payload)
        ) {
            app(SyncEventClassificationsAction::class)->handle($event, [
                'event_category_ids' => $payload['event_category_ids'] ?? $event->event_category_ids,
                'domain_tags' => $payload['domain_tags'] ?? [],
                'discipline_tags' => $payload['discipline_tags'] ?? [],
                'source_tags' => $payload['source_tags'] ?? [],
                'issue_tags' => $payload['issue_tags'] ?? [],
            ]);
        }

        return $dirty;
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
            'names' => $person->names->map(fn (PersonName $name): array => [
                'id' => (string) $name->getKey(),
                'name_type' => $name->name_type instanceof PersonNameType ? $name->name_type->value : (string) $name->name_type,
                'full_name' => $name->full_name,
                'language_code' => $name->language_code,
                'is_primary' => (bool) $name->is_primary,
            ])->values()->all(),
            'title_assignments' => $person->titleAssignments->map(fn (TitleAssignment $assignment): array => [
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
                'position' => self::institutionPivotPosition($institution),
                'is_primary' => self::institutionPivotIsPrimary($institution),
            ])->values()->all(),
            'address' => $this->addressState($person->primaryAddress()),
            'contactMethods' => $this->contactMethodsState($person->contactMethods),
            'social_media' => $this->socialMediaState($person->socialProfiles),
        ];
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
        if (array_key_exists('names', $payload)) {
            $this->syncPersonNames($person, $payload['names']);
        }

        if (array_key_exists('institutions', $payload)) {
            $this->syncPersonAffiliations($person, $payload['institutions']);
        } elseif (array_key_exists('institution_id', $payload) || array_key_exists('institution_position', $payload)) {
            $this->syncPersonAffiliation($person, $payload);
        }

        if (array_key_exists('title_ids', $payload)) {
            $this->syncPersonTitleIds($person, $payload['title_ids']);
        } elseif (array_key_exists('title_assignments', $payload)) {
            $this->syncPersonTitleAssignments($person, $payload['title_assignments']);
        }

        $addressPayload = $this->personAddressPayload($payload);

        if (is_array($addressPayload)) {
            $addressPayload = $this->preserveHiddenPersonAddressFields($person, $addressPayload);
            $this->syncAddress($person, $addressPayload, allowCountryOnly: true);
        }

        if (array_key_exists('contactMethods', $payload)) {
            $this->syncContactMethods($person, $payload['contactMethods']);
        }

        if (array_key_exists('social_media', $payload)) {
            $this->syncSocialMedia($person, $payload['social_media']);
        }

        if (array_key_exists('language_ids', $payload)) {
            $person->syncLanguages($this->normalizeStringArray(
                is_iterable($payload['language_ids']) ? $payload['language_ids'] : [],
            ));
        }

        $this->syncPersonAffiliation($person, $payload);
    }

    public function syncPersonNames(Person $person, mixed $namesPayload): void
    {
        $existing = $person->names()->get()->keyBy(fn (PersonName $name): string => (string) $name->getKey());
        $retainedIds = [];
        $primarySelected = false;

        foreach (is_array($namesPayload) ? $namesPayload : [] as $namePayload) {
            if (! is_array($namePayload)) {
                continue;
            }

            $fullName = trim((string) ($namePayload['full_name'] ?? ''));
            if ($fullName === '') {
                continue;
            }

            $nameType = $namePayload['name_type'] ?? PersonNameType::Display->value;
            $nameType = $nameType instanceof PersonNameType
                ? $nameType->value
                : (string) $nameType;
            $isPrimary = (bool) ($namePayload['is_primary'] ?? false) && ! $primarySelected;

            if ($isPrimary) {
                $primarySelected = true;
            }

            $attributes = [
                'name_type' => $nameType,
                'full_name' => $fullName,
                'language_code' => trim((string) ($namePayload['language_code'] ?? 'ms')) ?: 'ms',
                'is_primary' => $isPrimary,
            ];
            $nameId = is_string($namePayload['id'] ?? null) ? $namePayload['id'] : null;
            $name = $nameId !== null ? $existing->get($nameId) : null;

            if ($name instanceof PersonName) {
                $name->update($attributes);
                $retainedIds[] = (string) $name->getKey();
            } else {
                $created = $person->names()->create($attributes);
                $retainedIds[] = (string) $created->getKey();
            }
        }

        $existing->reject(fn (PersonName $name): bool => in_array((string) $name->getKey(), $retainedIds, true))
            ->each->delete();
    }

    public function syncPersonAffiliations(Person $person, mixed $institutionsPayload): void
    {
        $beforeAffiliations = $this->personAffiliationAuditState($person);
        $current = $person->institutions()->get()->keyBy(fn (Institution $institution): string => (string) $institution->getKey());
        $currentIds = $current->keys()->all();
        $rows = is_array($institutionsPayload) ? $institutionsPayload : [];
        $requestedIds = collect($rows)
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): ?string => $this->normalizeOptionalString($row['institution_id'] ?? null))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $current = $current->union(
            Institution::query()->whereKey($requestedIds)->get()->keyBy(fn (Institution $institution): string => (string) $institution->getKey()),
        );
        $retainedIds = [];
        $primaryId = null;

        Affiliation::query()
            ->where('affiliatable_id', $person->getKey())
            ->where('affiliatable_type', $person->getMorphClass())
            ->update(['is_primary' => false, 'updated_at' => now()]);

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $institutionId = $this->normalizeOptionalString($row['institution_id'] ?? null);
            if ($institutionId === null || $current->get($institutionId) === null) {
                continue;
            }

            $retainedIds[] = $institutionId;
            if ($primaryId === null && (bool) ($row['is_primary'] ?? false)) {
                $primaryId = $institutionId;
            }

            $position = $this->normalizeOptionalString($row['position'] ?? null);

            if (in_array($institutionId, $currentIds, true)) {
                Affiliation::query()
                    ->where('affiliatable_id', $person->getKey())
                    ->where('affiliatable_type', $person->getMorphClass())
                    ->where('institution_id', $institutionId)
                    ->update(['position' => $position, 'updated_at' => now()]);
            } else {
                Affiliation::query()->create([
                    'affiliatable_type' => $person->getMorphClass(),
                    'affiliatable_id' => $person->getKey(),
                    'institution_id' => $institutionId,
                    'affiliation_type' => AffiliationType::Member,
                    'position' => $position,
                    'is_primary' => false,
                ]);
            }
        }

        if ($primaryId === null && $retainedIds !== []) {
            $primaryId = $retainedIds[0];
        }

        if ($primaryId !== null) {
            Affiliation::query()
                ->where('affiliatable_id', $person->getKey())
                ->where('affiliatable_type', $person->getMorphClass())
                ->where('institution_id', $primaryId)
                ->update(['is_primary' => true, 'updated_at' => now()]);
        }

        $delete = Affiliation::query()
            ->where('affiliatable_id', $person->getKey())
            ->where('affiliatable_type', $person->getMorphClass());

        if ($retainedIds === []) {
            $delete->delete();
        } else {
            $delete->whereNotIn('institution_id', $retainedIds)->delete();
        }

        $this->recordPersonAffiliationAudit($person, $beforeAffiliations);
    }

    public function syncPersonTitleAssignments(Person $person, mixed $assignmentsPayload): void
    {
        $existing = $person->titleAssignments()->get()->keyBy(fn (TitleAssignment $assignment): string => (string) $assignment->getKey());
        $retainedIds = [];

        foreach (is_array($assignmentsPayload) ? $assignmentsPayload : [] as $assignmentPayload) {
            if (! is_array($assignmentPayload)) {
                continue;
            }

            $titleId = is_string($assignmentPayload['title_id'] ?? null) ? $assignmentPayload['title_id'] : null;
            if ($titleId === null || $titleId === '') {
                continue;
            }

            $status = $assignmentPayload['status'] ?? AssignmentStatus::Active->value;
            $status = $status instanceof AssignmentStatus ? $status->value : (string) $status;
            $attributes = [
                'title_id' => $titleId,
                'date_awarded' => $assignmentPayload['date_awarded'] ?? null,
                'date_expired' => $assignmentPayload['date_expired'] ?? null,
                'status' => $status,
            ];
            $assignmentId = is_string($assignmentPayload['id'] ?? null) ? $assignmentPayload['id'] : null;
            $assignment = $assignmentId !== null ? $existing->get($assignmentId) : null;

            if ($assignment instanceof TitleAssignment) {
                $assignment->update($attributes);
                $retainedIds[] = (string) $assignment->getKey();
            } else {
                $created = $person->titleAssignments()->create($attributes);
                $retainedIds[] = (string) $created->getKey();
            }
        }

        $existing->reject(fn (TitleAssignment $assignment): bool => in_array((string) $assignment->getKey(), $retainedIds, true))
            ->each->delete();
    }

    /**
     * @param  list<mixed>|mixed  $titleIds
     */
    private function syncPersonTitleIds(Person $person, mixed $titleIds): void
    {
        $existingByTitleId = $person->titleAssignments()
            ->get()
            ->keyBy(fn (TitleAssignment $assignment): string => (string) $assignment->title_id);
        $assignments = is_array($titleIds)
            ? array_map(function (mixed $titleId) use ($existingByTitleId): array {
                $titleId = (string) $titleId;
                $assignment = $existingByTitleId->get($titleId);

                return [
                    'id' => $assignment instanceof TitleAssignment ? (string) $assignment->getKey() : null,
                    'title_id' => $titleId,
                ];
            }, $titleIds)
            : [];

        $this->syncPersonTitleAssignments($person, $assignments);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncPersonAffiliation(Person $person, array $payload): void
    {
        $institutionId = array_key_exists('institution_id', $payload)
            ? $this->normalizeOptionalString($payload['institution_id'])
            : null;
        $institutionPosition = array_key_exists('institution_position', $payload)
            ? $this->normalizeOptionalString($payload['institution_position'])
            : null;

        if ($institutionId === null && $institutionPosition === null) {
            return;
        }

        $beforeAffiliations = $this->personAffiliationAuditState($person);
        $currentAffiliation = $this->currentPersonAffiliation($person);
        $currentAffiliationId = $currentAffiliation?->getKey();

        $institutionId = array_key_exists('institution_id', $payload)
            ? $this->normalizeOptionalString($payload['institution_id'])
            : (is_string($currentAffiliationId) ? $currentAffiliationId : null);

        if ($institutionId === null) {
            if ($currentAffiliation instanceof Institution) {
                Affiliation::query()
                    ->where('affiliatable_id', $person->getKey())
                    ->where('affiliatable_type', $person->getMorphClass())
                    ->where('institution_id', $currentAffiliation->getKey())
                    ->delete();
            }

            $this->recordPersonAffiliationAudit($person, $beforeAffiliations);

            return;
        }

        $position = array_key_exists('institution_position', $payload)
            ? $this->normalizeOptionalString($payload['institution_position'])
            : self::institutionPivotPosition($currentAffiliation);

        if ($currentAffiliation instanceof Institution && (string) $currentAffiliation->getKey() !== $institutionId) {
            Affiliation::query()
                ->where('affiliatable_id', $person->getKey())
                ->where('affiliatable_type', $person->getMorphClass())
                ->where('institution_id', $currentAffiliation->getKey())
                ->delete();
        }

        Affiliation::query()
            ->where('affiliatable_id', $person->getKey())
            ->where('affiliatable_type', $person->getMorphClass())
            ->where('institution_id', '!=', $institutionId)
            ->update([
                'is_primary' => false,
                'updated_at' => now(),
            ]);

        $alreadyAttached = $person->institutions()
            ->where('institutions.id', $institutionId)
            ->exists();

        if ($alreadyAttached) {
            Affiliation::query()
                ->where('affiliatable_id', $person->getKey())
                ->where('affiliatable_type', $person->getMorphClass())
                ->where('institution_id', $institutionId)
                ->update([
                    'position' => $position,
                    'is_primary' => true,
                    'updated_at' => now(),
                ]);

            $this->recordPersonAffiliationAudit($person, $beforeAffiliations);

            return;
        }

        Affiliation::query()->create([
            'affiliatable_type' => $person->getMorphClass(),
            'affiliatable_id' => $person->getKey(),
            'institution_id' => $institutionId,
            'affiliation_type' => AffiliationType::Member,
            'position' => $position,
            'is_primary' => true,
        ]);

        $this->recordPersonAffiliationAudit($person, $beforeAffiliations);
    }

    private static function institutionPivotPosition(?Institution $institution): ?string
    {
        if (! $institution instanceof Institution) {
            return null;
        }

        $position = data_get($institution->getRelations(), 'pivot.position');

        return is_string($position) && $position !== '' ? $position : null;
    }

    private static function institutionPivotIsPrimary(Institution $institution): bool
    {
        return (bool) data_get($institution->getRelations(), 'pivot.is_primary', false);
    }

    private function currentPersonAffiliation(Person $person): ?Institution
    {
        /** @var Institution|null $institution */
        $institution = $person->institutions()
            ->orderByPivot('is_primary', 'desc')
            ->orderBy('institutions.name')
            ->first();

        return $institution;
    }

    /**
     * @return list<array{id: string, name: string, position: ?string, is_primary: bool}>
     */
    private function personAffiliationAuditState(Person $person): array
    {
        return $person->institutions()
            ->orderByPivot('is_primary', 'desc')
            ->orderBy('institutions.name')
            ->get()
            ->map(static fn (Institution $institution): array => [
                'id' => (string) $institution->getKey(),
                'name' => $institution->name,
                'position' => self::institutionPivotPosition($institution),
                'is_primary' => self::institutionPivotIsPrimary($institution),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id: string, name: string, position: ?string, is_primary: bool}>  $beforeAffiliations
     */
    private function recordPersonAffiliationAudit(Person $person, array $beforeAffiliations): void
    {
        $person->load('institutions');
        $afterAffiliations = $this->personAffiliationAuditState($person);

        if ($beforeAffiliations === $afterAffiliations) {
            return;
        }

        $person->recordCustomAuditDifferences(
            'sync',
            ['institutions' => $beforeAffiliations],
            ['institutions' => $afterAffiliations],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncReferenceRelations(Reference $reference, array $payload): void
    {
        if (array_key_exists('social_media', $payload)) {
            $this->syncSocialMedia($reference, $payload['social_media']);
        }

        if (array_key_exists('author_ids', $payload) && $reference->isRootReference()) {
            $authorIds = [];

            foreach (is_array($payload['author_ids']) ? $payload['author_ids'] : [] as $authorId) {
                $normalized = is_string($authorId) ? trim($authorId) : '';

                if ($normalized !== '' && ! in_array($normalized, $authorIds, true)) {
                    $authorIds[] = $normalized;
                }
            }

            $reference->syncContributors(ReferenceContributorRole::Author, (new Person)->getMorphClass(), $authorIds);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncVenueRelations(Venue $venue, array $payload): void
    {
        if (array_key_exists('address', $payload)) {
            $this->syncAddress($venue, $payload['address'], allowCountryOnly: true);
        }

        if (array_key_exists('contactMethods', $payload)) {
            $this->syncContactMethods($venue, $payload['contactMethods']);
        }

        if (array_key_exists('social_media', $payload)) {
            $this->syncSocialMedia($venue, $payload['social_media']);
        }
    }

    private function syncAddress(Model $model, mixed $addressPayload, bool $allowCountryOnly = false): void
    {
        $this->entityRelationSync->syncAddress($model, $addressPayload, $allowCountryOnly);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function personAddressPayload(array $payload): ?array
    {
        if (array_key_exists('address', $payload)) {
            return is_array($payload['address']) ? $payload['address'] : [];
        }

        $addressKeys = [
            'country_id',
            'country_code',
            'country_key',
            'area_assignments',
            'line1',
            'line2',
            'postcode',
            'latitude',
            'longitude',
            'google_maps_url',
            'google_place_id',
            'google_feature_id',
            'google_cid',
            'google_entity_id',
            'waze_url',
        ];

        if (! collect($addressKeys)->contains(fn (string $key): bool => array_key_exists($key, $payload))) {
            return null;
        }

        $address = [];

        foreach ($addressKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $address[$key] = $payload[$key];
            }
        }

        return $address;
    }

    /**
     * @param  array<string, mixed>  $addressPayload
     * @return array<string, mixed>
     */
    private function preserveHiddenPersonAddressFields(Person $person, array $addressPayload): array
    {
        $person->loadMissing('addresses');

        $existingAddress = $person->primaryAddress();

        if (! $existingAddress instanceof Address) {
            return $addressPayload;
        }

        foreach ([
            'line1',
            'line2',
            'postcode',
            'latitude',
            'longitude',
            'google_maps_url',
            'google_place_id',
            'google_feature_id',
            'google_cid',
            'google_entity_id',
            'waze_url',
        ] as $field) {
            if (! array_key_exists($field, $addressPayload) || $addressPayload[$field] === null) {
                $addressPayload[$field] = $existingAddress->{$field};
            }
        }

        return $addressPayload;
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

    private function syncContactMethods(Model $model, mixed $contactPayload): void
    {
        $this->entityRelationSync->syncContactMethods($model, $contactPayload);
    }

    private function syncSocialMedia(Model $model, mixed $socialMediaPayload): void
    {
        $this->entityRelationSync->syncSocialMedia($model, $socialMediaPayload);
    }

    /**
     * Stable identity for matching a submitted donation row to its persisted
     * channel, mirroring {@see sameDonationChannelIdentity()}.
     *
     * @param  array<string, mixed>  $row
     */
    public static function donationChannelFingerprint(array $row): ?string
    {
        $method = is_string($row['method'] ?? null) ? trim($row['method']) : null;

        if (! in_array($method, ['bank_account', 'duitnow', 'ewallet'], true)) {
            return null;
        }

        $parts = match ($method) {
            'bank_account' => [$row['bank_code'] ?? null, $row['bank_name'] ?? null, $row['account_number'] ?? null],
            'duitnow' => [$row['duitnow_type'] ?? null, $row['duitnow_value'] ?? null],
            default => [$row['ewallet_provider'] ?? null, $row['ewallet_handle'] ?? null, $row['ewallet_qr_payload'] ?? null],
        };

        $normalized = array_map(
            static fn (mixed $value): string => is_string($value) && trim($value) !== '' ? mb_strtolower(trim($value)) : '',
            $parts,
        );

        if (array_filter($normalized) === []) {
            return null;
        }

        return $method.'|'.implode('|', $normalized);
    }

    /**
     * Attach freshly uploaded donation QR images to their channels. Uploads
     * are keyed by submitted row so each file follows its row's identity,
     * even when invalid sibling rows were dropped during sync.
     *
     * @param  array<int|string, array<string, mixed>>  $submittedRows
     * @param  array<int|string, TemporaryUploadedFile>  $uploads
     */
    public function attachDonationQrUploads(Model $model, array $submittedRows, array $uploads): void
    {
        if (! method_exists($model, 'donationChannels') || $uploads === []) {
            return;
        }

        $failed = [];

        foreach ($uploads as $index => $file) {
            $row = $submittedRows[$index] ?? null;
            $fingerprint = is_array($row) ? self::donationChannelFingerprint($row) : null;
            $channel = $fingerprint === null ? null : $this->findDonationChannelByFingerprint($model, $fingerprint);

            try {
                $contents = $file->exists() ? $file->get() : null;
            } catch (\Throwable) {
                $contents = null;
            }

            if (! is_string($contents) || $contents === '' || ! $channel instanceof DonationChannel) {
                $failed["data.donation_channels.{$index}.qr_upload"] = __('The QR upload expired or is invalid. Please upload the QR image again.');

                continue;
            }

            $channel->addMediaFromString($contents)
                ->usingFileName($file->getClientOriginalName() !== '' ? $file->getClientOriginalName() : 'qr.png')
                ->toMediaCollection('qr');
        }

        if ($failed !== []) {
            throw ValidationException::withMessages($failed);
        }
    }

    /**
     * Move a staged visitor QR onto its approved channel. The staged copy is
     * always discarded so approvals never leak pending media.
     */
    public function attachStagedDonationQr(Model $entity, Media $staged): void
    {
        $fingerprint = $staged->getCustomProperty('donation_fingerprint');
        $channel = is_string($fingerprint) && $fingerprint !== '' && method_exists($entity, 'donationChannels')
            ? $this->findDonationChannelByFingerprint($entity, $fingerprint)
            : null;

        if ($channel instanceof DonationChannel) {
            $channel->addMediaFromDisk($staged->getPathRelativeToRoot(), $staged->disk)
                ->usingFileName($staged->file_name)
                ->toMediaCollection('qr');
        }

        $staged->delete();
    }

    private function findDonationChannelByFingerprint(Model $model, string $fingerprint): ?DonationChannel
    {
        if (! method_exists($model, 'donationChannels')) {
            return null;
        }

        return $model->donationChannels()->get()->first(
            fn (DonationChannel $channel): bool => self::donationChannelFingerprint($this->donationChannelRow($channel)) === $fingerprint
        );
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
     * @param  iterable<int, mixed>  $entries
     * @return list<array{role_code: string, involveable_type: string|null, involveable_id: string|null, display_name: string|null, visibility: string, notes: string|null}>
     */
    private function normalizeKeyPeople(iterable $entries): array
    {
        return collect($entries)
            ->map(function (mixed $entry): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $role = is_string($entry['role_code'] ?? null) ? trim($entry['role_code']) : null;
                $involveableType = is_string($entry['involveable_type'] ?? null) && $entry['involveable_type'] !== ''
                    ? MorphAlias::of($entry['involveable_type'])
                    : null;
                $involveableId = is_string($entry['involveable_id'] ?? null) && $entry['involveable_id'] !== '' ? $entry['involveable_id'] : null;
                $name = is_string($entry['display_name'] ?? null) ? trim($entry['display_name']) : null;
                $notes = is_string($entry['notes'] ?? null) ? trim($entry['notes']) : null;

                if ($role === null || $role === '' || ($involveableId === null && ($name === null || $name === ''))) {
                    return null;
                }

                return [
                    'role_code' => $role,
                    'involveable_type' => $involveableType,
                    'involveable_id' => $involveableId,
                    'display_name' => $name !== '' ? $name : null,
                    'visibility' => in_array($entry['visibility'] ?? 'public', ['public', 'private'], true) ? $entry['visibility'] : 'public',
                    'notes' => $notes !== '' ? $notes : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
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
