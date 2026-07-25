<?php

namespace App\Services;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\State;
use AIArmada\Addressing\Support\AddressAreaStateBridge;
use AIArmada\Addressing\Support\AddressCountryResolver;
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
use AIArmada\Persons\Enums\Gender;
use AIArmada\Persons\Models\Affiliation;
use App\Actions\Events\SyncEventClassificationsAction;
use App\Actions\Events\SyncEventScheduleAction;
use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Contracts\EventCategoryCatalog;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventTaxonomyCode;
use App\Enums\EventVisibility;
use App\Enums\InstitutionType;
use App\Enums\PrayerOffset;
use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Enums\TimingMode;
use App\Forms\SharedFormSchema;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\User;
use App\Models\Venue;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ContributionEntityMutationService
{
    public function __construct(
        private readonly EventKeyPersonSyncService $eventKeyPersonSyncService,
        private readonly AddMemberAction $addMemberAction,
        private readonly GenerateInstitutionSlugAction $generateInstitutionSlugAction,
        private readonly GeneratePersonSlugAction $generatePersonSlugAction,
        private readonly AddressCountryResolver $addressingCountryResolver,
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
                    $this->field('nickname', 'string', maxLength: 255),
                    $this->field('type', 'string', allowedValues: $this->enumValues(InstitutionType::class)),
                    $this->field('description', 'rich_text'),
                    $this->field('address', 'object'),
                    $this->field('address.country_id', 'uuid', catalog: route('api.client.catalogs.countries')),
                    $this->field('contactMethods', 'array<object>'),
                    $this->field('social_media', 'array<object>'),
                ],
                'conditional_rules' => [],
                'direct_edit_media_fields' => ['cover', 'gallery'],
            ],
            $entity instanceof Person => [
                'accepts_partial_updates' => true,
                'fields' => [
                    $this->field('name', 'string', maxLength: 255),
                    $this->field('gender', 'string', allowedValues: $this->enumValues(Gender::class)),
                    $this->field('bio', 'rich_text'),
                    $this->field('language_ids', 'array<int>', catalog: route('api.client.catalogs.languages')),
                    $this->field('institution_id', 'uuid', catalog: route('api.client.catalogs.submit-institutions')),
                    $this->field('institution_position', 'string', maxLength: 255),
                    $this->field('address', 'object'),
                    $this->field('address.country_id', 'uuid', catalog: route('api.client.catalogs.countries')),
                    $this->field('contactMethods', 'array<object>'),
                    $this->field('social_media', 'array<object>'),
                ],
                'conditional_rules' => [],
                'direct_edit_media_fields' => ['avatar', 'cover', 'gallery'],
            ],
            $entity instanceof Reference => [
                'accepts_partial_updates' => true,
                'fields' => [
                    $this->field('title', 'string', maxLength: 255),
                    $this->field('author', 'string', maxLength: 255),
                    $this->field('type', 'string', allowedValues: $this->enumValues(ReferenceType::class)),
                    $this->field('publication_year', 'string', maxLength: 255),
                    $this->field('publisher', 'string', maxLength: 255),
                    $this->field('description', 'string'),
                    $this->field('social_media', 'array<object>'),
                ],
                'conditional_rules' => [],
                'direct_edit_media_fields' => [],
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
                    $this->field('language_ids', 'array<int>', catalog: route('api.client.catalogs.languages')),
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
                'nickname' => ['nullable', 'string', 'max:255'],
                'type' => ['sometimes', Rule::in($this->enumValues(InstitutionType::class))],
                'description' => ['nullable'],
                'address' => ['sometimes', 'array'],
                'address.country_id' => ['sometimes', 'uuid', 'exists:'.config('addressing.tables.countries', 'countries').',id'],
                'address.admin_area_1_id' => ['nullable', 'uuid', 'exists:address_areas,id'],
                'address.admin_area_2_id' => ['nullable', 'uuid', 'exists:address_areas,id'],
                'address.line1' => ['nullable', 'string', 'max:255'],
                'address.line2' => ['nullable', 'string', 'max:255'],
                'address.postcode' => ['nullable', 'string', 'max:16'],
                'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'address.google_maps_url' => ['nullable', 'url', 'max:255'],
                'address.provider_place_id' => ['nullable', 'string', 'max:255'],
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
            ],
            $entity instanceof Person => [
                'name' => ['sometimes', 'string', 'max:255'],
                'gender' => ['sometimes', Rule::in($this->enumValues(Gender::class))],
                'bio' => ['nullable'],
                'institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],
                'institution_position' => ['nullable', 'string', 'max:255'],
                'address' => ['sometimes', 'array'],
                'address.country_id' => ['nullable', 'uuid', 'exists:'.config('addressing.tables.countries', 'countries').',id'],
                'address.admin_area_1_id' => ['nullable', 'uuid', 'exists:address_areas,id'],
                'address.admin_area_2_id' => ['nullable', 'uuid', 'exists:address_areas,id'],
                'address.line1' => ['prohibited'],
                'address.line2' => ['prohibited'],
                'address.postcode' => ['prohibited'],
                'address.latitude' => ['prohibited'],
                'address.longitude' => ['prohibited'],
                'address.google_maps_url' => ['prohibited'],
                'address.provider_place_id' => ['prohibited'],
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
                'author' => ['nullable', 'string', 'max:255'],
                'type' => ['sometimes', Rule::in($this->enumValues(ReferenceType::class))],
                'parent_reference_id' => ['nullable', 'uuid', Rule::exists('references', 'id')->whereNull('parent_id')->where('type', ReferenceType::Book->value)],
                'part_type' => ['nullable', Rule::in($this->enumValues(ReferencePartType::class))],
                'part_number' => ['nullable', 'string', 'max:255'],
                'part_label' => ['nullable', 'string', 'max:255'],
                'publication_year' => ['nullable', 'string', 'max:255'],
                'publisher' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
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
        $institution = Institution::create([
            'name' => (string) ($payload['name'] ?? 'Institution'),
            'nickname' => $this->normalizeOptionalString($payload['nickname'] ?? null),
            'slug' => $this->generateInstitutionSlugAction->handle(
                (string) ($payload['name'] ?? 'Institution'),
                is_array($payload['address'] ?? null) ? $payload['address'] : [],
            ),
            'type' => $this->normalizeInstitutionType($payload['type'] ?? null),
            'description' => $payload['description'] ?? null,
            'status' => 'pending',
            'allow_public_event_submission' => true,
        ]);

        $this->syncInstitutionRelations($institution, $payload);
        $this->generateInstitutionSlugAction->syncInstitutionSlug($institution);

        return $institution;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPerson(array $payload, User $proposer): Person
    {
        $person = Person::create([
            'name' => (string) ($payload['name'] ?? 'Person'),
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
        $institution->fill([
            'name' => $payload['name'] ?? $institution->name,
            'nickname' => array_key_exists('nickname', $payload)
                ? $this->normalizeOptionalString($payload['nickname'])
                : $institution->nickname,
            'type' => array_key_exists('type', $payload)
                ? $this->normalizeInstitutionType($payload['type'])
                : ($institution->type instanceof BackedEnum ? $institution->type->value : $institution->type),
            'description' => array_key_exists('description', $payload) ? $payload['description'] : $institution->description,
        ]);

        $dirty = $institution->getDirty();
        $institution->save();

        $this->syncInstitutionRelations($institution, $payload);
        $this->generateInstitutionSlugAction->syncInstitutionSlug($institution);

        return $dirty;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyPerson(Person $person, array $payload): array
    {
        $person->fill([
            'name' => $payload['name'] ?? $person->name,
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

    private function normalizeInstitutionType(mixed $value): string
    {
        if ($value instanceof InstitutionType) {
            return $value->value;
        }

        if (is_string($value) && InstitutionType::tryFrom($value) instanceof InstitutionType) {
            return $value;
        }

        return InstitutionType::Masjid->value;
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyReference(Reference $reference, array $payload): array
    {
        $reference->fill([
            'title' => $payload['title'] ?? $reference->title,
            'author' => array_key_exists('author', $payload) ? $this->normalizeOptionalString($payload['author']) : $reference->author,
            'type' => array_key_exists('type', $payload) ? $this->normalizeReferenceType($payload['type']) : $reference->type,
            'parent_id' => array_key_exists('parent_reference_id', $payload) ? $this->normalizeOptionalString($payload['parent_reference_id']) : $reference->parent_id,
            'part_type' => array_key_exists('part_type', $payload) ? $this->normalizeOptionalString($payload['part_type']) : $reference->part_type,
            'part_number' => array_key_exists('part_number', $payload) ? $this->normalizeOptionalString($payload['part_number']) : $reference->part_number,
            'part_label' => array_key_exists('part_label', $payload) ? $this->normalizeOptionalString($payload['part_label']) : $reference->part_label,
            'year' => array_key_exists('publication_year', $payload) ? $this->normalizeOptionalString($payload['publication_year']) : $reference->year,
            'publisher' => array_key_exists('publisher', $payload) ? $this->normalizeOptionalString($payload['publisher']) : $reference->publisher,
            'description' => array_key_exists('description', $payload) ? $payload['description'] : $reference->description,
        ]);

        $dirty = $reference->getDirty();
        $reference->save();

        if (array_key_exists('social_media', $payload)) {
            $this->syncSocialMedia($reference, $payload['social_media']);
        }

        return $dirty;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyEvent(Event $event, array $payload): array
    {
        $currentOccurrence = $event->primaryOccurrence;
        $currentExpression = $event->timeExpressions()
            ->where('anchor_type', 'prayer')
            ->first();

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
            $existing = $event->locations()
                ->whereNull('event_occurrence_id')
                ->whereNull('event_session_id')
                ->orderBy('sort_order')
                ->get();
            $spaceIds = $existing->pluck('venue_space_id')->filter()->map(strval(...))->values()->all();
        }

        $event->syncLocation($event->default_venue_id, $spaceIds);

        $dirty = $event->getDirty();
        $event->save();
        $event->syncLocation($event->default_venue_id, $spaceIds);

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
        $startsAt = array_key_exists('starts_at', $payload) ? $payload['starts_at'] : $currentOccurrence?->starts_at;
        $startsAt = $startsAt instanceof CarbonInterface
            ? $startsAt
            : (is_string($startsAt) && $startsAt !== '' ? Carbon::parse($startsAt, $scheduleTimezone) : null);
        $endsAt = array_key_exists('ends_at', $payload) ? $payload['ends_at'] : $currentOccurrence?->ends_at;
        $endsAt = $endsAt instanceof CarbonInterface
            ? $endsAt
            : (is_string($endsAt) && $endsAt !== '' ? Carbon::parse($endsAt, $scheduleTimezone) : null);

        app(SyncEventScheduleAction::class)->execute(
            event: $event,
            scheduleKind: $scheduleKind,
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $scheduleTimezone,
            timingMode: $timingMode,
            prayerReference: $payload['prayer_reference'] ?? $currentExpression?->anchor_code,
            prayerOffset: $prayerOffset,
            prayerDisplayText: array_key_exists('prayer_display_text', $payload)
                ? $this->normalizeOptionalString($payload['prayer_display_text'])
                : $currentExpression?->display_label,
        );

        if (array_key_exists('primary_organizer_id', $payload)) {
            $organizerId = $this->normalizeOptionalString($payload['primary_organizer_id']);
            $organizer = $organizerId === null
                ? null
                : (Institution::query()->find($organizerId) ?? Person::query()->find($organizerId));

            $event->setPrimaryOrganizer($organizer);
        }

        if (array_key_exists('language_ids', $payload)) {
            $languageIds = $this->normalizeIntegerArray($payload['language_ids']);
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
        $institution->loadMissing(['addresses', 'contactMethods', 'socialProfiles']);

        return [
            'name' => $institution->name,
            'nickname' => $institution->nickname,
            'type' => $institution->type instanceof BackedEnum ? $institution->type->value : (string) $institution->type,
            'description' => $institution->description,
            'address' => $this->addressState($institution->primaryAddress()),
            'contactMethods' => $this->contactMethodsState($institution->contactMethods),
            'social_media' => $this->socialMediaState($institution->socialProfiles),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personState(Person $person): array
    {
        $person->loadMissing(['addresses', 'contactMethods', 'socialProfiles', 'languages']);

        $affiliatedInstitution = $this->currentPersonAffiliation($person);

        return [
            'name' => $person->name,
            'gender' => (string) $person->gender,
            'bio' => $person->bio,
            'language_ids' => $person->languages->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'institution_id' => $affiliatedInstitution?->getKey(),
            'institution_position' => self::institutionPivotPosition($affiliatedInstitution),
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
            'author' => $reference->author,
            'type' => $reference->type,
            'parent_reference_id' => $reference->parent_id,
            'part_type' => $reference->part_type,
            'part_number' => $reference->part_number,
            'part_label' => $reference->part_label,
            'publication_year' => $reference->year,
            'publisher' => $reference->publisher,
            'description' => $reference->description,
            'social_media' => $this->socialMediaState($reference->socialProfiles),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventState(Event $event): array
    {
        $event->loadMissing(['references', 'series', 'classifications', 'keyPeople.person', 'languages:id,event_id']);

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
            'language_ids' => $event->languages->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
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
    public function syncInstitutionRelations(Institution $institution, array $payload): void
    {
        if (array_key_exists('address', $payload)) {
            $this->syncAddress($institution, $payload['address'], allowCountryOnly: true);
        }

        if (array_key_exists('contactMethods', $payload)) {
            $this->syncContactMethods($institution, $payload['contactMethods']);
        }

        if (array_key_exists('social_media', $payload)) {
            $this->syncSocialMedia($institution, $payload['social_media']);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncPersonRelations(Person $person, array $payload): void
    {
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
            $person->syncLanguages($this->normalizeIntegerArray(
                is_iterable($payload['language_ids']) ? $payload['language_ids'] : [],
            ));
        }

        $this->syncPersonAffiliation($person, $payload);
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
                $person->institutions()->detach($currentAffiliation->getKey());
            }

            $this->recordPersonAffiliationAudit($person, $beforeAffiliations);

            return;
        }

        $position = array_key_exists('institution_position', $payload)
            ? $this->normalizeOptionalString($payload['institution_position'])
            : self::institutionPivotPosition($currentAffiliation);

        if ($currentAffiliation instanceof Institution && (string) $currentAffiliation->getKey() !== $institutionId) {
            $person->institutions()->detach($currentAffiliation->getKey());
        }

        $person->institutions()
            ->newPivotStatement()
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
            $person->institutions()->updateExistingPivot($institutionId, [
                'position' => $position,
                'is_primary' => true,
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
        if (! method_exists($model, 'primaryAddress') || ! method_exists($model, 'attachAddress')) {
            return;
        }

        $payload = is_array($addressPayload) ? $addressPayload : [];
        $hasContent = collect([
            $payload['line1'] ?? null,
            $payload['line2'] ?? null,
            $payload['postcode'] ?? null,
            $payload['admin_area_1_id'] ?? null,
            $payload['admin_area_2_id'] ?? null,
            $payload['latitude'] ?? null,
            $payload['longitude'] ?? null,
            $payload['google_maps_url'] ?? null,
            $payload['provider_place_id'] ?? null,
            $payload['waze_url'] ?? null,
            $allowCountryOnly ? ($payload['country_id'] ?? $payload['country_code'] ?? $payload['country_key'] ?? null) : null,
        ])->contains(fn (mixed $value): bool => filled($value));

        /** @var Address|null $existingAddress */
        $existingAddress = $model->primaryAddress();

        if (! $hasContent) {
            if ($existingAddress instanceof Address && method_exists($model, 'addresses')) {
                $model->addresses()->detach($existingAddress->getKey());
                $existingAddress->delete();
            }

            return;
        }

        $countryProvided = SharedFormSchema::countrySelectionProvided($payload);
        $payload = SharedFormSchema::prepareAddressPersistenceData($payload);
        $countryId = $this->normalizeUuid($payload['country_id'] ?? null);

        if ($countryId === null && ! $countryProvided) {
            $countryId = $this->normalizeUuid($existingAddress?->country_id)
                ?? $this->addressingCountryResolver->resolveId($existingAddress?->country_code);
        }

        if ($countryId === null) {
            throw ValidationException::withMessages([
                'address.country_id' => $countryProvided
                    ? __('The selected country is invalid.')
                    : __('The address country is required.'),
            ]);
        }

        if ($existingAddress instanceof Address) {
            foreach ([
                'line1',
                'line2',
                'postcode',
                'state_id',
                'city_id',
                'admin_area_1_id',
                'admin_area_2_id',
                'admin_area_3_id',
                'admin_area_4_id',
                'state',
                'city',
                'latitude',
                'longitude',
                'google_maps_url',
                'provider_place_id',
                'waze_url',
            ] as $field) {
                if (! array_key_exists($field, $payload)) {
                    $payload[$field] = $existingAddress->{$field};
                }
            }
        }

        $adminArea1Id = $this->normalizeUuid($payload['admin_area_1_id'] ?? null);
        $adminArea2Id = $this->normalizeUuid($payload['admin_area_2_id'] ?? null);
        $adminArea3Id = $this->normalizeUuid($payload['admin_area_3_id'] ?? null);
        $adminArea4Id = $this->normalizeUuid($payload['admin_area_4_id'] ?? null);
        $latitude = $payload['latitude'] ?? null;
        $longitude = $payload['longitude'] ?? null;
        $providerPlaceId = $payload['provider_place_id'] ?? null;
        $addressMetadata = $this->resolveAddressMetadata(
            $countryId,
            $adminArea1Id,
            $adminArea2Id,
            $adminArea3Id,
            $adminArea4Id,
        );

        $attributes = [
            'country_id' => $countryId,
            'state_id' => $this->normalizeUuid($payload['state_id'] ?? null),
            'city_id' => $this->normalizeUuid($payload['city_id'] ?? null),
            'admin_area_1_id' => $adminArea1Id,
            'admin_area_2_id' => $adminArea2Id,
            'admin_area_3_id' => $adminArea3Id,
            'admin_area_4_id' => $adminArea4Id,
            'line1' => $payload['line1'] ?? null,
            'line2' => $payload['line2'] ?? null,
            'postcode' => $payload['postcode'] ?? null,
            'country' => $addressMetadata['country'],
            'country_code' => $addressMetadata['country_code'],
            'state' => $payload['state'] ?? $addressMetadata['state'],
            'city' => $payload['city'] ?? $addressMetadata['city'],
            'latitude' => $latitude !== null && $latitude !== '' ? (float) $latitude : null,
            'longitude' => $longitude !== null && $longitude !== '' ? (float) $longitude : null,
            'google_maps_url' => $payload['google_maps_url'] ?? null,
            'provider' => filled($providerPlaceId) ? 'google' : null,
            'provider_place_id' => $providerPlaceId,
            'waze_url' => $payload['waze_url'] ?? null,
        ];

        if ($existingAddress instanceof Address) {
            $existingAddress->fill($attributes)->save();

            return;
        }

        $address = Address::query()->create($attributes);

        $model->attachAddress($address, type: 'primary', isPrimary: true);
    }

    /**
     * @return array{country: ?string, country_code: ?string, state: ?string, city: ?string}
     */
    private function resolveAddressMetadata(
        ?string $countryId,
        ?string $adminArea1Id,
        ?string $adminArea2Id,
        ?string $adminArea3Id,
        ?string $adminArea4Id,
    ): array {
        $country = $countryId !== null
            ? AddressCountry::query()->find($countryId)
            : null;
        $areas = [];

        foreach ([$adminArea1Id, $adminArea2Id, $adminArea3Id, $adminArea4Id] as $areaId) {
            if ($areaId !== null) {
                $area = AddressArea::query()->find($areaId);

                if ($area instanceof AddressArea) {
                    $areas[] = $area;
                }
            }
        }

        $stateName = null;

        foreach (array_reverse($areas) as $area) {
            $stateId = AddressAreaStateBridge::stateIdForArea((string) $area->getKey());

            if ($stateId !== null) {
                $state = State::query()->find($stateId);
                $stateName = $state instanceof State ? $state->name : null;

                if ($stateName !== null && $stateName !== '') {
                    break;
                }
            }

            $parent = $area;

            while (is_string($parent->parent_id) && $parent->parent_id !== '') {
                $parent = AddressArea::query()->find($parent->parent_id);

                if (! $parent instanceof AddressArea) {
                    break;
                }

                if ((int) $parent->level === 1) {
                    $stateName = $parent->name;
                    break 2;
                }
            }
        }

        $locality = array_reverse($areas)[0] ?? null;

        return [
            'country' => $country?->name,
            'country_code' => $country?->iso2,
            'state' => is_string($stateName) && $stateName !== '' ? $stateName : null,
            'city' => $locality?->name,
        ];
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
            'admin_area_1_id',
            'admin_area_2_id',
            'line1',
            'line2',
            'postcode',
            'latitude',
            'longitude',
            'google_maps_url',
            'provider_place_id',
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
            'provider_place_id',
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

    private function normalizeUuid(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        return Str::isUuid($trimmed) || ctype_digit($trimmed) ? $trimmed : null;
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
        if (! method_exists($model, 'contactMethods')) {
            return;
        }

        $contacts = collect(is_array($contactPayload) ? $contactPayload : [])
            ->values()
            ->map(function (mixed $contact, int $index): ?array {
                if (! is_array($contact)) {
                    return null;
                }

                $type = $this->normalizeContactMethodType($contact['type'] ?? $contact['category'] ?? null);
                $value = match ($type) {
                    ContactMethodType::Phone, ContactMethodType::Whatsapp, ContactMethodType::Mobile => is_string($contact['phone_value'] ?? null)
                        ? trim($contact['phone_value'])
                        : (is_string($contact['value'] ?? null) ? trim($contact['value']) : null),
                    default => is_string($contact['value'] ?? null)
                        ? trim($contact['value'])
                        : null,
                };
                $purpose = $this->normalizeContactPurpose($contact['purpose'] ?? null);

                if (! $type instanceof ContactMethodType || $value === null || $value === '') {
                    return null;
                }

                return [
                    'type' => $type->value,
                    'purpose' => $purpose->value,
                    'value' => $value,
                    'is_public' => (bool) ($contact['is_public'] ?? true),
                    'sort_order' => is_numeric($contact['sort_order'] ?? $contact['order_column'] ?? null)
                        ? (int) ($contact['sort_order'] ?? $contact['order_column'])
                        : $index + 1,
                ];
            })
            ->filter()
            ->values();

        $model->contactMethods()->delete();

        $contacts->each(fn (array $contact): ContactMethod => $model->contactMethods()->create($contact));
    }

    private function normalizeContactMethodType(mixed $value): ?ContactMethodType
    {
        if ($value instanceof ContactMethodType) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return ContactMethodType::tryFrom(trim($value));
    }

    private function normalizeContactPurpose(mixed $value): ContactPurpose
    {
        if ($value instanceof ContactPurpose) {
            return $value;
        }

        if (! is_string($value)) {
            return ContactPurpose::General;
        }

        return ContactPurpose::tryFrom(trim($value)) ?? ContactPurpose::General;
    }

    private function syncSocialMedia(Model $model, mixed $socialMediaPayload): void
    {
        if (! method_exists($model, 'socialProfiles')) {
            return;
        }

        $entries = collect(is_array($socialMediaPayload) ? $socialMediaPayload : [])
            ->values()
            ->map(function (mixed $entry, int $index): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $platform = is_string($entry['platform'] ?? null) ? trim($entry['platform']) : null;
                $handle = is_string($entry['handle'] ?? $entry['username'] ?? null) ? trim($entry['handle'] ?? $entry['username']) : null;
                $url = is_string($entry['url'] ?? null) ? trim($entry['url']) : null;

                if ($platform === null || $platform === '' || ($handle === null || $handle === '') && ($url === null || $url === '')) {
                    return null;
                }

                return [
                    'platform' => $platform,
                    'purpose' => ContactPurpose::General->value,
                    'handle' => $handle !== '' ? $handle : null,
                    'url' => $url !== '' ? $url : null,
                    'sort_order' => is_numeric($entry['sort_order'] ?? $entry['order_column'] ?? null)
                        ? (int) ($entry['sort_order'] ?? $entry['order_column'])
                        : $index + 1,
                ];
            })
            ->filter()
            ->values();

        $model->socialProfiles()->delete();

        $entries->each(fn (array $entry): SocialProfile => $model->socialProfiles()->create($entry));
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
     * @param  iterable<int, mixed>  $values
     * @return list<int>
     */
    private function normalizeIntegerArray(iterable $values): array
    {
        return collect($values)
            ->map(fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null)
            ->filter(static fn (?int $value): bool => $value !== null)
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
                $involveableType = is_string($entry['involveable_type'] ?? null) && $entry['involveable_type'] !== '' ? $entry['involveable_type'] : null;
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
                'admin_area_1_id' => null,
                'admin_area_2_id' => null,
                'admin_area_3_id' => null,
                'admin_area_4_id' => null,
                'line1' => null,
                'line2' => null,
                'postcode' => null,
                'latitude' => null,
                'longitude' => null,
                'google_maps_url' => null,
                'provider_place_id' => null,
                'waze_url' => null,
            ]);
        }

        return SharedFormSchema::hydrateAddressFormState([
            'country_id' => $address->country_id ?? $this->addressingCountryResolver->resolveId($address->country_code),
            'admin_area_1_id' => $address->admin_area_1_id,
            'admin_area_2_id' => $address->admin_area_2_id,
            'admin_area_3_id' => $address->admin_area_3_id,
            'admin_area_4_id' => $address->admin_area_4_id,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'postcode' => $address->postcode,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
            'google_maps_url' => $address->google_maps_url,
            'provider_place_id' => $address->provider_place_id,
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
            ])
            ->values()
            ->all();
    }
}
