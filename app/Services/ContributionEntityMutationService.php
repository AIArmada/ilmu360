<?php

namespace App\Services;

use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Actions\Membership\AddMemberToSubject;
use App\Actions\Speakers\GenerateSpeakerSlugAction;
use App\Enums\ContactCategory;
use App\Enums\ContactType;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\Gender;
use App\Enums\Honorific;
use App\Enums\InstitutionType;
use App\Enums\PostNominal;
use App\Enums\PreNominal;
use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Enums\SocialMediaPlatform;
use App\Enums\TagType;
use App\Forms\SharedFormSchema;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\SocialMedia;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use BackedEnum;
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
        private readonly AddMemberToSubject $addMemberToSubject,
        private readonly GenerateInstitutionSlugAction $generateInstitutionSlugAction,
        private readonly GenerateSpeakerSlugAction $generateSpeakerSlugAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function stateFor(Model $entity): array
    {
        return match (true) {
            $entity instanceof Institution => $this->institutionState($entity),
            $entity instanceof Speaker => $this->speakerState($entity),
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
                    $this->field('address.country_id', 'integer', catalog: route('api.client.catalogs.countries')),
                    $this->field('contacts', 'array<object>'),
                    $this->field('social_media', 'array<object>'),
                ],
                'conditional_rules' => [],
                'direct_edit_media_fields' => ['cover', 'gallery'],
            ],
            $entity instanceof Speaker => [
                'accepts_partial_updates' => true,
                'fields' => [
                    $this->field('name', 'string', maxLength: 255),
                    $this->field('gender', 'string', allowedValues: $this->enumValues(Gender::class)),
                    $this->field('is_freelance', 'boolean'),
                    $this->field('job_title', 'string', maxLength: 255),
                    $this->field('honorific', 'array<string>', allowedValues: $this->enumValues(Honorific::class)),
                    $this->field('pre_nominal', 'array<string>', allowedValues: $this->enumValues(PreNominal::class)),
                    $this->field('post_nominal', 'array<string>', allowedValues: $this->enumValues(PostNominal::class)),
                    $this->field('bio', 'rich_text'),
                    $this->field('qualifications', 'array<object>'),
                    $this->field('language_ids', 'array<int>', catalog: route('api.client.catalogs.languages')),
                    $this->field('institution_id', 'uuid', catalog: route('api.client.catalogs.submit-institutions')),
                    $this->field('institution_position', 'string', maxLength: 255),
                    $this->field('address', 'object'),
                    $this->field('address.country_id', 'integer', catalog: route('api.client.catalogs.countries')),
                    $this->field('contacts', 'array<object>'),
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
                    $this->field('event_type', 'array<string>', allowedValues: $this->enumValues(EventType::class)),
                    $this->field('gender', 'string', allowedValues: $this->enumValues(EventGenderRestriction::class)),
                    $this->field('age_group', 'array<string>', allowedValues: $this->enumValues(EventAgeGroup::class)),
                    $this->field('children_allowed', 'boolean'),
                    $this->field('is_muslim_only', 'boolean'),
                    $this->field('event_format', 'string', allowedValues: $this->enumValues(EventFormat::class)),
                    $this->field('visibility', 'string', allowedValues: $this->enumValues(EventVisibility::class)),
                    $this->field('event_url', 'url'),
                    $this->field('live_url', 'url'),
                    $this->field('recording_url', 'url'),
                    $this->field('organizer_type', 'string', allowedValues: ['institution', 'speaker']),
                    $this->field('organizer_institution_id', 'uuid', catalog: route('api.client.catalogs.submit-institutions')),
                    $this->field('organizer_speaker_id', 'uuid', catalog: route('api.client.catalogs.submit-speakers')),
                    $this->field('location_same_as_institution', 'boolean'),
                    $this->field('location_type', 'string', allowedValues: ['institution', 'venue']),
                    $this->field('location_institution_id', 'uuid', catalog: route('api.client.catalogs.submit-institutions')),
                    $this->field('location_venue_id', 'uuid', catalog: route('api.client.catalogs.venues')),
                    $this->field('space_id', 'uuid', catalog: route('api.client.catalogs.spaces')),
                    $this->field('language_ids', 'array<int>', catalog: route('api.client.catalogs.languages')),
                    $this->field('domain_tags', 'array<string>', catalog: route('api.client.catalogs.tags', ['type' => TagType::Domain->value])),
                    $this->field('discipline_tags', 'array<string>', catalog: route('api.client.catalogs.tags', ['type' => TagType::Discipline->value])),
                    $this->field('source_tags', 'array<string>', catalog: route('api.client.catalogs.tags', ['type' => TagType::Source->value])),
                    $this->field('issue_tags', 'array<string>', catalog: route('api.client.catalogs.tags', ['type' => TagType::Issue->value])),
                    $this->field('reference_ids', 'array<string>', catalog: route('api.client.catalogs.references')),
                    $this->field('series_ids', 'array<string>'),
                    $this->field('speaker_ids', 'array<string>', catalog: route('api.client.catalogs.submit-speakers')),
                    $this->field('other_key_people', 'array<object>'),
                ],
                'conditional_rules' => [
                    ['field' => 'custom_time', 'required_when' => ['prayer_time' => [EventPrayerTime::LainWaktu->value]]],
                    ['field' => 'organizer_institution_id', 'required_when' => ['organizer_type' => ['institution']]],
                    ['field' => 'organizer_speaker_id', 'required_when' => ['organizer_type' => ['speaker']]],
                    ['field' => 'location_type', 'required_when' => ['organizer_type' => ['speaker']]],
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
                'address.country_id' => ['sometimes', 'integer', 'exists:countries,id'],
                'address.state_id' => ['nullable', 'integer', 'exists:states,id'],
                'address.district_id' => ['nullable', 'integer', 'exists:districts,id'],
                'address.subdistrict_id' => ['nullable', 'integer', 'exists:subdistricts,id'],
                'address.line1' => ['nullable', 'string', 'max:255'],
                'address.line2' => ['nullable', 'string', 'max:255'],
                'address.postcode' => ['nullable', 'string', 'max:16'],
                'address.lat' => ['nullable', 'numeric', 'between:-90,90'],
                'address.lng' => ['nullable', 'numeric', 'between:-180,180'],
                'address.google_maps_url' => ['nullable', 'url', 'max:255'],
                'address.google_place_id' => ['nullable', 'string', 'max:255'],
                'address.waze_url' => ['nullable', 'url', 'max:255'],
                'contacts' => ['sometimes', 'array'],
                'contacts.*.category' => ['required_with:contacts.*.value', Rule::in($this->enumValues(ContactCategory::class))],
                'contacts.*.value' => ['required_with:contacts.*.category', 'string', 'max:255'],
                'contacts.*.type' => ['nullable', Rule::in($this->enumValues(ContactType::class))],
                'contacts.*.is_public' => ['nullable', 'boolean'],
                'social_media' => ['sometimes', 'array'],
                'social_media.*.platform' => ['required_with:social_media.*.username,social_media.*.url', Rule::in($this->enumValues(SocialMediaPlatform::class))],
                'social_media.*.username' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
                'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.username'],
            ],
            $entity instanceof Speaker => [
                'name' => ['sometimes', 'string', 'max:255'],
                'gender' => ['sometimes', Rule::in($this->enumValues(Gender::class))],
                'is_freelance' => ['nullable', 'boolean'],
                'job_title' => ['nullable', 'string', 'max:255'],
                'honorific' => ['sometimes', 'array'],
                'honorific.*' => ['string', Rule::in($this->enumValues(Honorific::class))],
                'pre_nominal' => ['sometimes', 'array'],
                'pre_nominal.*' => ['string', Rule::in($this->enumValues(PreNominal::class))],
                'post_nominal' => ['sometimes', 'array'],
                'post_nominal.*' => ['string', Rule::in($this->enumValues(PostNominal::class))],
                'bio' => ['nullable'],
                'institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],
                'institution_position' => ['nullable', 'string', 'max:255'],
                'address' => ['sometimes', 'array'],
                'address.country_id' => ['nullable', 'integer', 'exists:countries,id'],
                'address.state_id' => ['nullable', 'integer', 'exists:states,id'],
                'address.district_id' => ['nullable', 'integer', 'exists:districts,id'],
                'address.subdistrict_id' => ['nullable', 'integer', 'exists:subdistricts,id'],
                'address.line1' => ['prohibited'],
                'address.line2' => ['prohibited'],
                'address.postcode' => ['prohibited'],
                'address.lat' => ['prohibited'],
                'address.lng' => ['prohibited'],
                'address.google_maps_url' => ['prohibited'],
                'address.google_place_id' => ['prohibited'],
                'address.waze_url' => ['prohibited'],
                'qualifications' => ['sometimes', 'array'],
                'qualifications.*.institution' => ['required_with:qualifications.*.degree', 'nullable', 'string', 'max:255'],
                'qualifications.*.degree' => ['required_with:qualifications.*.institution', 'nullable', 'string', 'max:255'],
                'qualifications.*.field' => ['nullable', 'string', 'max:255'],
                'qualifications.*.year' => ['nullable', 'digits:4'],
                'language_ids' => ['sometimes', 'array'],
                'language_ids.*' => ['integer', 'exists:languages,id'],
                'contacts' => ['sometimes', 'array'],
                'contacts.*.category' => ['required_with:contacts.*.value', Rule::in($this->enumValues(ContactCategory::class))],
                'contacts.*.value' => ['required_with:contacts.*.category', 'string', 'max:255'],
                'contacts.*.type' => ['nullable', Rule::in($this->enumValues(ContactType::class))],
                'contacts.*.is_public' => ['nullable', 'boolean'],
                'social_media' => ['sometimes', 'array'],
                'social_media.*.platform' => ['required_with:social_media.*.username,social_media.*.url', Rule::in($this->enumValues(SocialMediaPlatform::class))],
                'social_media.*.username' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
                'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.username'],
            ],
            $entity instanceof Reference => [
                'title' => ['sometimes', 'string', 'max:255'],
                'author' => ['nullable', 'string', 'max:255'],
                'type' => ['sometimes', Rule::in($this->enumValues(ReferenceType::class))],
                'parent_reference_id' => ['nullable', 'uuid', Rule::exists('references', 'id')->whereNull('parent_reference_id')->where('type', ReferenceType::Book->value)],
                'part_type' => ['nullable', Rule::in($this->enumValues(ReferencePartType::class))],
                'part_number' => ['nullable', 'string', 'max:255'],
                'part_label' => ['nullable', 'string', 'max:255'],
                'publication_year' => ['nullable', 'string', 'max:255'],
                'publisher' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'social_media' => ['sometimes', 'array'],
                'social_media.*.platform' => ['required_with:social_media.*.username,social_media.*.url', Rule::in($this->enumValues(SocialMediaPlatform::class))],
                'social_media.*.username' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
                'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.username'],
            ],
            $entity instanceof Event => [
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable'],
                'event_date' => ['sometimes', 'date'],
                'prayer_time' => ['sometimes', Rule::in($this->enumValues(EventPrayerTime::class))],
                'custom_time' => ['nullable', 'date_format:H:i'],
                'end_time' => ['nullable', 'date_format:H:i'],
                'timezone' => ['sometimes', 'timezone'],
                'event_type' => ['sometimes', 'array'],
                'event_type.*' => ['string', Rule::in($this->enumValues(EventType::class))],
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
                'organizer_type' => ['sometimes', Rule::in(['institution', 'speaker', Institution::class, Speaker::class])],
                'organizer_id' => ['nullable', 'uuid'],
                'organizer_institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],
                'organizer_speaker_id' => ['nullable', 'uuid', 'exists:speakers,id'],
                'location_same_as_institution' => ['sometimes', 'boolean'],
                'location_type' => ['sometimes', 'string', Rule::in(['institution', 'venue'])],
                'location_institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],
                'location_venue_id' => ['nullable', 'uuid', 'exists:venues,id'],
                'language_ids' => ['sometimes', 'array'],
                'language_ids.*' => ['integer', 'exists:languages,id'],
                'domain_tags' => ['sometimes', 'array'],
                'domain_tags.*' => ['uuid', Rule::exists('tags', 'id')->where('type', TagType::Domain->value)],
                'discipline_tags' => ['sometimes', 'array'],
                'discipline_tags.*' => ['uuid', Rule::exists('tags', 'id')->where('type', TagType::Discipline->value)],
                'source_tags' => ['sometimes', 'array'],
                'source_tags.*' => ['uuid', Rule::exists('tags', 'id')->where('type', TagType::Source->value)],
                'issue_tags' => ['sometimes', 'array'],
                'issue_tags.*' => ['uuid', Rule::exists('tags', 'id')->where('type', TagType::Issue->value)],
                'reference_ids' => ['sometimes', 'array'],
                'reference_ids.*' => ['uuid', 'exists:references,id'],
                'series_ids' => ['sometimes', 'array'],
                'series_ids.*' => ['uuid', 'exists:series,id'],
                'speaker_ids' => ['sometimes', 'array'],
                'speaker_ids.*' => ['uuid', 'exists:speakers,id'],
                'other_key_people' => ['sometimes', 'array'],
                'other_key_people.*.role' => ['required_with:other_key_people.*.name,other_key_people.*.speaker_id', Rule::in($this->enumValues(EventKeyPersonRole::class))],
                'other_key_people.*.speaker_id' => ['nullable', 'uuid', 'exists:speakers,id', 'required_without:other_key_people.*.name'],
                'other_key_people.*.name' => ['nullable', 'string', 'max:255', 'required_without:other_key_people.*.speaker_id'],
                'other_key_people.*.is_public' => ['nullable', 'boolean'],
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
            'is_active' => true,
            'allow_public_event_submission' => true,
        ]);

        $this->syncInstitutionRelations($institution, $payload);
        $this->generateInstitutionSlugAction->syncInstitutionSlug($institution);

        return $institution;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createSpeaker(array $payload, User $proposer): Speaker
    {
        $speaker = Speaker::create([
            'name' => (string) ($payload['name'] ?? 'Speaker'),
            'gender' => $this->normalizeGender($payload['gender'] ?? null),
            'honorific' => $this->normalizeStringArray($payload['honorific'] ?? []),
            'pre_nominal' => $this->normalizeStringArray($payload['pre_nominal'] ?? []),
            'post_nominal' => $this->normalizeStringArray($payload['post_nominal'] ?? []),
            'bio' => $payload['bio'] ?? null,
            'qualifications' => $this->normalizeQualificationEntries($payload['qualifications'] ?? []),
            'is_freelance' => (bool) ($payload['is_freelance'] ?? false),
            'job_title' => $payload['job_title'] ?? null,
            'slug' => $this->generateSpeakerSlugAction->handle(
                (string) ($payload['name'] ?? 'Speaker'),
                $payload,
            ),
            'status' => 'pending',
            'is_active' => true,
            'allow_public_event_submission' => true,
        ]);

        $this->addMemberToSubject->handle($speaker, $proposer);

        $this->syncSpeakerRelations($speaker, $payload);

        return $speaker;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(Model $entity, array $payload): array
    {
        return match (true) {
            $entity instanceof Institution => $this->applyInstitution($entity, $payload),
            $entity instanceof Speaker => $this->applySpeaker($entity, $payload),
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
    private function applySpeaker(Speaker $speaker, array $payload): array
    {
        $speaker->fill([
            'name' => $payload['name'] ?? $speaker->name,
            'gender' => array_key_exists('gender', $payload)
                ? $this->normalizeGender($payload['gender'])
                : $speaker->gender,
            'honorific' => array_key_exists('honorific', $payload) ? $this->normalizeStringArray($payload['honorific']) : $speaker->honorific,
            'pre_nominal' => array_key_exists('pre_nominal', $payload) ? $this->normalizeStringArray($payload['pre_nominal']) : $speaker->pre_nominal,
            'post_nominal' => array_key_exists('post_nominal', $payload) ? $this->normalizeStringArray($payload['post_nominal']) : $speaker->post_nominal,
            'bio' => array_key_exists('bio', $payload) ? $payload['bio'] : $speaker->bio,
            'qualifications' => array_key_exists('qualifications', $payload) ? $this->normalizeQualificationEntries($payload['qualifications']) : $speaker->qualifications,
            'is_freelance' => array_key_exists('is_freelance', $payload) ? (bool) $payload['is_freelance'] : $speaker->is_freelance,
            'job_title' => array_key_exists('job_title', $payload) ? $payload['job_title'] : $speaker->job_title,
        ]);

        $dirty = $speaker->getDirty();
        $speaker->save();

        $this->syncSpeakerRelations($speaker, $payload);

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

    private function normalizeEventOrganizerTypeForPublicApi(mixed $value): ?string
    {
        return match ($value) {
            Institution::class, 'institution' => 'institution',
            Speaker::class, 'speaker' => 'speaker',
            default => null,
        };
    }

    private function normalizeEventOrganizerTypeForPersistence(mixed $value): ?string
    {
        return match ($value) {
            Institution::class, 'institution' => Institution::class,
            Speaker::class, 'speaker' => Speaker::class,
            default => null,
        };
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
            'parent_reference_id' => array_key_exists('parent_reference_id', $payload) ? $this->normalizeOptionalString($payload['parent_reference_id']) : $reference->parent_reference_id,
            'part_type' => array_key_exists('part_type', $payload) ? $this->normalizeOptionalString($payload['part_type']) : $reference->part_type,
            'part_number' => array_key_exists('part_number', $payload) ? $this->normalizeOptionalString($payload['part_number']) : $reference->part_number,
            'part_label' => array_key_exists('part_label', $payload) ? $this->normalizeOptionalString($payload['part_label']) : $reference->part_label,
            'publication_year' => array_key_exists('publication_year', $payload) ? $this->normalizeOptionalString($payload['publication_year']) : $reference->publication_year,
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
        $event->fill([
            'title' => $payload['title'] ?? $event->title,
            'description' => array_key_exists('description', $payload) ? $payload['description'] : $event->description,
            'starts_at' => array_key_exists('starts_at', $payload) ? $payload['starts_at'] : $event->starts_at,
            'ends_at' => array_key_exists('ends_at', $payload) ? $payload['ends_at'] : $event->ends_at,
            'timezone' => array_key_exists('timezone', $payload) ? $payload['timezone'] : $event->timezone,
            'timing_mode' => array_key_exists('timing_mode', $payload) ? $payload['timing_mode'] : $event->timing_mode,
            'prayer_reference' => array_key_exists('prayer_reference', $payload) ? $payload['prayer_reference'] : $event->prayer_reference,
            'prayer_offset' => array_key_exists('prayer_offset', $payload) ? $payload['prayer_offset'] : $event->prayer_offset,
            'prayer_display_text' => array_key_exists('prayer_display_text', $payload)
                ? $this->normalizeOptionalString($payload['prayer_display_text'])
                : $event->prayer_display_text,
            'event_type' => array_key_exists('event_type', $payload) ? $this->normalizeStringArray($payload['event_type']) : $event->event_type,
            'gender' => array_key_exists('gender', $payload) ? $payload['gender'] : $event->gender,
            'age_group' => array_key_exists('age_group', $payload) ? $this->normalizeStringArray($payload['age_group']) : $event->age_group,
            'children_allowed' => array_key_exists('children_allowed', $payload) ? (bool) $payload['children_allowed'] : $event->children_allowed,
            'is_muslim_only' => array_key_exists('is_muslim_only', $payload) ? (bool) $payload['is_muslim_only'] : $event->is_muslim_only,
            'event_format' => array_key_exists('event_format', $payload) ? $payload['event_format'] : $event->event_format,
            'visibility' => array_key_exists('visibility', $payload) ? $payload['visibility'] : $event->visibility,
            'event_url' => array_key_exists('event_url', $payload) ? $this->normalizeOptionalString($payload['event_url']) : $event->event_url,
            'live_url' => array_key_exists('live_url', $payload) ? $this->normalizeOptionalString($payload['live_url']) : $event->live_url,
            'recording_url' => array_key_exists('recording_url', $payload) ? $this->normalizeOptionalString($payload['recording_url']) : $event->recording_url,
            'organizer_type' => array_key_exists('organizer_type', $payload)
                ? $this->normalizeEventOrganizerTypeForPersistence($payload['organizer_type'])
                : $event->organizer_type,
            'organizer_id' => array_key_exists('organizer_id', $payload) ? $this->normalizeOptionalString($payload['organizer_id']) : $event->organizer_id,
            'institution_id' => array_key_exists('institution_id', $payload) ? $this->normalizeOptionalString($payload['institution_id']) : $event->institution_id,
            'venue_id' => array_key_exists('venue_id', $payload) ? $this->normalizeOptionalString($payload['venue_id']) : $event->venue_id,
            'space_id' => array_key_exists('space_id', $payload) ? $this->normalizeOptionalString($payload['space_id']) : $event->space_id,
        ]);

        $dirty = $event->getDirty();
        $event->save();

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
            $event->auditSync('series', $seriesIds, true, ['series.id', 'series.title']);
        }

        if (
            array_key_exists('speaker_ids', $payload)
            || array_key_exists('other_key_people', $payload)
        ) {
            $this->eventKeyPersonSyncService->sync(
                $event,
                $this->normalizeStringArray($payload['speaker_ids'] ?? []),
                $this->normalizeKeyPeople($payload['other_key_people'] ?? []),
            );
        }

        if (
            array_key_exists('domain_tags', $payload)
            || array_key_exists('discipline_tags', $payload)
            || array_key_exists('source_tags', $payload)
            || array_key_exists('issue_tags', $payload)
        ) {
            $tags = $this->resolveEventTags($payload);
            $tagIds = array_map(
                static fn (Tag $tag): string => (string) $tag->getKey(),
                $tags,
            );

            $event->auditSync('tags', $tagIds, true, ['tags.id', 'tags.name', 'tags.type']);
        }

        return $dirty;
    }

    /**
     * @return array<string, mixed>
     */
    private function institutionState(Institution $institution): array
    {
        $institution->loadMissing(['address', 'contacts', 'socialMedia']);

        return [
            'name' => $institution->name,
            'nickname' => $institution->nickname,
            'type' => $institution->type instanceof BackedEnum ? $institution->type->value : (string) $institution->type,
            'description' => $institution->description,
            'address' => $this->addressState($institution->addressModel),
            'contacts' => $this->contactsState($institution->contacts),
            'social_media' => $this->socialMediaState($institution->socialMedia),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function speakerState(Speaker $speaker): array
    {
        $speaker->loadMissing(['address', 'contacts', 'socialMedia', 'languages']);

        $affiliatedInstitution = $this->currentSpeakerAffiliation($speaker);

        return [
            'name' => $speaker->name,
            'gender' => (string) $speaker->gender,
            'is_freelance' => (bool) $speaker->is_freelance,
            'job_title' => $speaker->job_title,
            'honorific' => $this->normalizeStringArray($speaker->honorific ?? []),
            'pre_nominal' => $this->normalizeStringArray($speaker->pre_nominal ?? []),
            'post_nominal' => $this->normalizeStringArray($speaker->post_nominal ?? []),
            'bio' => $speaker->bio,
            'qualifications' => $this->normalizeQualificationEntries($speaker->qualifications ?? []),
            'language_ids' => $speaker->languages->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'institution_id' => $affiliatedInstitution?->getKey(),
            'institution_position' => self::institutionPivotPosition($affiliatedInstitution),
            'address' => $this->addressState($speaker->addressModel),
            'contacts' => $this->contactsState($speaker->contacts),
            'social_media' => $this->socialMediaState($speaker->socialMedia),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceState(Reference $reference): array
    {
        $reference->loadMissing(['socialMedia']);

        return [
            'title' => $reference->title,
            'author' => $reference->author,
            'type' => $reference->type,
            'parent_reference_id' => $reference->parent_reference_id,
            'part_type' => $reference->part_type,
            'part_number' => $reference->part_number,
            'part_label' => $reference->part_label,
            'publication_year' => $reference->publication_year,
            'publisher' => $reference->publisher,
            'description' => $reference->description,
            'social_media' => $this->socialMediaState($reference->socialMedia),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventState(Event $event): array
    {
        $event->loadMissing(['references', 'series', 'tags', 'keyPeople', 'languages']);

        $tags = $event->tags->groupBy('type');

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
            'event_type' => $this->enumCollectionValues($event->event_type),
            'gender' => $event->gender instanceof BackedEnum ? $event->gender->value : (string) $event->gender,
            'age_group' => $this->enumCollectionValues($event->age_group),
            'children_allowed' => (bool) $event->children_allowed,
            'is_muslim_only' => (bool) $event->is_muslim_only,
            'event_format' => $event->event_format instanceof BackedEnum ? $event->event_format->value : (string) $event->event_format,
            'visibility' => $event->visibility instanceof BackedEnum ? $event->visibility->value : (string) $event->visibility,
            'event_url' => $event->event_url,
            'live_url' => $event->live_url,
            'recording_url' => $event->recording_url,
            'organizer_type' => $this->normalizeEventOrganizerTypeForPublicApi($event->organizer_type),
            'organizer_id' => $event->organizer_id,
            'institution_id' => $event->institution_id,
            'venue_id' => $event->venue_id,
            'space_id' => $event->space_id,
            'language_ids' => $event->languages->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'domain_tags' => $tags->get(TagType::Domain->value, collect())->pluck('id')->values()->all(),
            'discipline_tags' => $tags->get(TagType::Discipline->value, collect())->pluck('id')->values()->all(),
            'source_tags' => $tags->get(TagType::Source->value, collect())->pluck('id')->values()->all(),
            'issue_tags' => $tags->get(TagType::Issue->value, collect())->pluck('id')->values()->all(),
            'reference_ids' => $event->references->pluck('id')->values()->all(),
            'series_ids' => $event->series->pluck('id')->values()->all(),
            'speaker_ids' => $event->keyPeople
                ->where('role', EventKeyPersonRole::Speaker)
                ->pluck('speaker_id')
                ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
                ->values()
                ->all(),
            'other_key_people' => $event->keyPeople
                ->reject(fn ($keyPerson): bool => $keyPerson->role === EventKeyPersonRole::Speaker)
                ->map(fn ($keyPerson): array => [
                    'role' => $keyPerson->role instanceof BackedEnum ? $keyPerson->role->value : (string) $keyPerson->role,
                    'speaker_id' => $keyPerson->speaker_id,
                    'name' => $keyPerson->name,
                    'is_public' => (bool) $keyPerson->is_public,
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
        $venue->loadMissing(['address', 'contacts', 'socialMedia']);

        return [
            'name' => $venue->name,
            'type' => $venue->type instanceof BackedEnum ? $venue->type->value : (string) $venue->type,
            'facilities' => collect((array) $venue->facilities)
                ->filter(static fn (mixed $enabled): bool => (bool) $enabled)
                ->keys()
                ->map(static fn (mixed $key): string => (string) $key)
                ->values()
                ->all(),
            'address' => $this->addressState($venue->addressModel),
            'contacts' => $this->contactsState($venue->contacts),
            'social_media' => $this->socialMediaState($venue->socialMedia),
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

        if (array_key_exists('contacts', $payload)) {
            $this->syncContacts($institution, $payload['contacts']);
        }

        if (array_key_exists('social_media', $payload)) {
            $this->syncSocialMedia($institution, $payload['social_media']);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncSpeakerRelations(Speaker $speaker, array $payload): void
    {
        $addressPayload = $this->speakerAddressPayload($payload);

        if (is_array($addressPayload)) {
            $addressPayload = $this->preserveHiddenSpeakerAddressFields($speaker, $addressPayload);
            $this->syncAddress($speaker, $addressPayload, allowCountryOnly: true);
        }

        if (array_key_exists('contacts', $payload)) {
            $this->syncContacts($speaker, $payload['contacts']);
        }

        if (array_key_exists('social_media', $payload)) {
            $this->syncSocialMedia($speaker, $payload['social_media']);
        }

        if (array_key_exists('language_ids', $payload)) {
            $speaker->syncLanguages($this->normalizeIntegerArray(
                is_iterable($payload['language_ids']) ? $payload['language_ids'] : [],
            ));
        }

        $this->syncSpeakerAffiliation($speaker, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncSpeakerAffiliation(Speaker $speaker, array $payload): void
    {
        if (! array_key_exists('institution_id', $payload) && ! array_key_exists('institution_position', $payload)) {
            return;
        }

        $beforeAffiliations = $this->speakerAffiliationAuditState($speaker);
        $currentAffiliation = $this->currentSpeakerAffiliation($speaker);
        $currentAffiliationId = $currentAffiliation?->getKey();

        $institutionId = array_key_exists('institution_id', $payload)
            ? $this->normalizeOptionalString($payload['institution_id'])
            : (is_string($currentAffiliationId) ? $currentAffiliationId : null);

        if ($institutionId === null) {
            if ($currentAffiliation instanceof Institution) {
                $speaker->institutions()->detach($currentAffiliation->getKey());
            }

            $this->recordSpeakerAffiliationAudit($speaker, $beforeAffiliations);

            return;
        }

        $position = array_key_exists('institution_position', $payload)
            ? $this->normalizeOptionalString($payload['institution_position'])
            : self::institutionPivotPosition($currentAffiliation);

        if ($currentAffiliation instanceof Institution && (string) $currentAffiliation->getKey() !== $institutionId) {
            $speaker->institutions()->detach($currentAffiliation->getKey());
        }

        $speaker->institutions()
            ->newPivotStatement()
            ->where('speaker_id', $speaker->getKey())
            ->where('institution_id', '!=', $institutionId)
            ->update([
                'is_primary' => false,
                'updated_at' => now(),
            ]);

        $alreadyAttached = $speaker->institutions()
            ->where('institutions.id', $institutionId)
            ->exists();

        if ($alreadyAttached) {
            $speaker->institutions()->updateExistingPivot($institutionId, [
                'position' => $position,
                'is_primary' => true,
            ]);

            $this->recordSpeakerAffiliationAudit($speaker, $beforeAffiliations);

            return;
        }

        $speaker->institutions()->attach($institutionId, [
            'position' => $position,
            'is_primary' => true,
        ]);

        $this->recordSpeakerAffiliationAudit($speaker, $beforeAffiliations);
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

    private function currentSpeakerAffiliation(Speaker $speaker): ?Institution
    {
        /** @var Institution|null $institution */
        $institution = $speaker->institutions()
            ->orderByPivot('is_primary', 'desc')
            ->orderBy('institutions.name')
            ->first();

        return $institution;
    }

    /**
     * @return list<array{id: string, name: string, position: ?string, is_primary: bool}>
     */
    private function speakerAffiliationAuditState(Speaker $speaker): array
    {
        return $speaker->institutions()
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
    private function recordSpeakerAffiliationAudit(Speaker $speaker, array $beforeAffiliations): void
    {
        $speaker->load('institutions');
        $afterAffiliations = $this->speakerAffiliationAuditState($speaker);

        if ($beforeAffiliations === $afterAffiliations) {
            return;
        }

        $speaker->recordCustomAuditDifferences(
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

        if (array_key_exists('contacts', $payload)) {
            $this->syncContacts($venue, $payload['contacts']);
        }

        if (array_key_exists('social_media', $payload)) {
            $this->syncSocialMedia($venue, $payload['social_media']);
        }
    }

    private function syncAddress(Model $model, mixed $addressPayload, bool $allowCountryOnly = false): void
    {
        if (! method_exists($model, 'address')) {
            return;
        }

        $payload = is_array($addressPayload) ? $addressPayload : [];
        $hasContent = collect([
            $payload['line1'] ?? null,
            $payload['line2'] ?? null,
            $payload['postcode'] ?? null,
            $payload['state_id'] ?? null,
            $payload['district_id'] ?? null,
            $payload['subdistrict_id'] ?? null,
            $payload['lat'] ?? null,
            $payload['lng'] ?? null,
            $payload['google_maps_url'] ?? null,
            $payload['google_place_id'] ?? null,
            $payload['waze_url'] ?? null,
            $allowCountryOnly ? ($payload['country_id'] ?? $payload['country_code'] ?? $payload['country_key'] ?? null) : null,
        ])->contains(fn (mixed $value): bool => filled($value));

        /** @var Address|null $existingAddress */
        $existingAddress = $model->address()->first();

        if (! $hasContent) {
            $existingAddress?->delete();

            return;
        }

        $countryProvided = SharedFormSchema::countrySelectionProvided($payload);
        $payload = SharedFormSchema::prepareAddressPersistenceData($payload);
        $countryId = SharedFormSchema::normalizeLocationId($payload['country_id'] ?? null);

        if ($countryId === null && ! $countryProvided) {
            $countryId = is_numeric($existingAddress?->country_id)
                ? (int) $existingAddress->country_id
                : SharedFormSchema::preferredPublicCountryId();
        }

        if ($countryId === null) {
            throw ValidationException::withMessages([
                'address.country_id' => $countryProvided
                    ? __('The selected country is invalid.')
                    : __('The address country is required.'),
            ]);
        }

        $attributes = [
            'type' => 'main',
            'country_id' => $countryId,
            'state_id' => isset($payload['state_id']) && $payload['state_id'] !== '' ? (int) $payload['state_id'] : null,
            'district_id' => isset($payload['district_id']) && $payload['district_id'] !== '' ? (int) $payload['district_id'] : null,
            'subdistrict_id' => isset($payload['subdistrict_id']) && $payload['subdistrict_id'] !== '' ? (int) $payload['subdistrict_id'] : null,
            'line1' => $payload['line1'] ?? null,
            'line2' => $payload['line2'] ?? null,
            'postcode' => $payload['postcode'] ?? null,
            'lat' => isset($payload['lat']) && $payload['lat'] !== '' ? (float) $payload['lat'] : null,
            'lng' => isset($payload['lng']) && $payload['lng'] !== '' ? (float) $payload['lng'] : null,
            'google_maps_url' => $payload['google_maps_url'] ?? null,
            'google_place_id' => $payload['google_place_id'] ?? null,
            'waze_url' => $payload['waze_url'] ?? null,
        ];

        if ($existingAddress instanceof Address) {
            foreach ([
                'line1',
                'line2',
                'postcode',
                'state_id',
                'district_id',
                'subdistrict_id',
                'lat',
                'lng',
                'google_maps_url',
                'google_place_id',
                'waze_url',
            ] as $field) {
                if (! array_key_exists($field, $payload)) {
                    $attributes[$field] = $existingAddress->{$field};
                }
            }
        }

        if ($existingAddress instanceof Address) {
            $existingAddress->fill($attributes)->save();

            return;
        }

        $model->address()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function speakerAddressPayload(array $payload): ?array
    {
        if (array_key_exists('address', $payload)) {
            return is_array($payload['address']) ? $payload['address'] : [];
        }

        $addressKeys = [
            'country_id',
            'state_id',
            'district_id',
            'subdistrict_id',
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
    private function preserveHiddenSpeakerAddressFields(Speaker $speaker, array $addressPayload): array
    {
        $speaker->loadMissing('address');

        $existingAddress = $speaker->addressModel;

        if (! $existingAddress instanceof Address) {
            return $addressPayload;
        }

        foreach ([
            'line1',
            'line2',
            'postcode',
            'lat',
            'lng',
            'google_maps_url',
            'google_place_id',
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

    private function syncContacts(Model $model, mixed $contactPayload): void
    {
        if (! method_exists($model, 'contacts')) {
            return;
        }

        $contacts = collect(is_array($contactPayload) ? $contactPayload : [])
            ->values()
            ->map(function (mixed $contact, int $index): ?array {
                if (! is_array($contact)) {
                    return null;
                }

                $category = $this->normalizeContactCategory($contact['category'] ?? null);
                $value = match ($category) {
                    ContactCategory::Phone, ContactCategory::WhatsApp => is_string($contact['phone_value'] ?? null)
                        ? trim($contact['phone_value'])
                        : (is_string($contact['value'] ?? null) ? trim($contact['value']) : null),
                    default => is_string($contact['value'] ?? null)
                        ? trim($contact['value'])
                        : null,
                };
                $type = $this->normalizeContactType($contact['type'] ?? null);

                if ($category === null || $value === null || $value === '') {
                    return null;
                }

                return [
                    'category' => $category->value,
                    'value' => $value,
                    'type' => $type->value,
                    'is_public' => (bool) ($contact['is_public'] ?? true),
                    'order_column' => is_numeric($contact['order_column'] ?? null)
                        ? (int) $contact['order_column']
                        : $index + 1,
                ];
            })
            ->filter()
            ->values();

        $model->contacts()->delete();

        $contacts->each(fn (array $contact): Contact => $model->contacts()->create($contact));
    }

    private function normalizeContactCategory(mixed $value): ?ContactCategory
    {
        if ($value instanceof ContactCategory) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return ContactCategory::tryFrom(trim($value));
    }

    private function normalizeContactType(mixed $value): ContactType
    {
        if ($value instanceof ContactType) {
            return $value;
        }

        if (! is_string($value)) {
            return ContactType::Main;
        }

        return ContactType::tryFrom(trim($value)) ?? ContactType::Main;
    }

    private function syncSocialMedia(Model $model, mixed $socialMediaPayload): void
    {
        if (! method_exists($model, 'socialMedia')) {
            return;
        }

        $entries = collect(is_array($socialMediaPayload) ? $socialMediaPayload : [])
            ->values()
            ->map(function (mixed $entry, int $index): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $platform = is_string($entry['platform'] ?? null) ? trim($entry['platform']) : null;
                $username = is_string($entry['username'] ?? null) ? trim($entry['username']) : null;
                $url = is_string($entry['url'] ?? null) ? trim($entry['url']) : null;

                if ($platform === null || $platform === '' || ($username === null || $username === '') && ($url === null || $url === '')) {
                    return null;
                }

                return [
                    'platform' => $platform,
                    'username' => $username !== '' ? $username : null,
                    'url' => $url !== '' ? $url : null,
                    'order_column' => is_numeric($entry['order_column'] ?? null)
                        ? (int) $entry['order_column']
                        : $index + 1,
                ];
            })
            ->filter()
            ->values();

        $model->socialMedia()->delete();

        $entries->each(fn (array $entry): SocialMedia => $model->socialMedia()->create($entry));
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
     * @param  iterable<int, mixed>  $entries
     * @return list<array<string, mixed>>
     */
    private function normalizeQualificationEntries(iterable $entries): array
    {
        return collect($entries)
            ->map(function (mixed $entry): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $institution = is_string($entry['institution'] ?? null) ? trim($entry['institution']) : null;
                $degree = is_string($entry['degree'] ?? null) ? trim($entry['degree']) : null;
                $field = is_string($entry['field'] ?? null) ? trim($entry['field']) : null;
                $year = is_scalar($entry['year'] ?? null) ? trim((string) $entry['year']) : null;

                if (($institution === null || $institution === '') && ($degree === null || $degree === '')) {
                    return null;
                }

                return [
                    'institution' => $institution,
                    'degree' => $degree,
                    'field' => $field !== '' ? $field : null,
                    'year' => $year !== '' ? $year : null,
                ];
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
     * @return list<array{role: string, speaker_id: ?string, name: ?string, is_public: bool, notes: ?string}>
     */
    private function normalizeKeyPeople(iterable $entries): array
    {
        return collect($entries)
            ->map(function (mixed $entry): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $role = is_string($entry['role'] ?? null) ? trim($entry['role']) : null;
                $speakerId = is_string($entry['speaker_id'] ?? null) && $entry['speaker_id'] !== '' ? $entry['speaker_id'] : null;
                $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : null;
                $notes = is_string($entry['notes'] ?? null) ? trim($entry['notes']) : null;

                if ($role === null || $role === '' || ($speakerId === null && ($name === null || $name === ''))) {
                    return null;
                }

                return [
                    'role' => $role,
                    'speaker_id' => $speakerId,
                    'name' => $name !== '' ? $name : null,
                    'is_public' => (bool) ($entry['is_public'] ?? true),
                    'notes' => $notes !== '' ? $notes : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Tag>
     */
    private function resolveEventTags(array $payload): array
    {
        $tagFieldMap = [
            'domain_tags' => TagType::Domain,
            'discipline_tags' => TagType::Discipline,
            'source_tags' => TagType::Source,
            'issue_tags' => TagType::Issue,
        ];

        $tagIds = [];

        foreach ($tagFieldMap as $field => $type) {
            foreach ((array) ($payload[$field] ?? []) as $value) {
                if (is_string($value) && Str::isUuid($value)) {
                    $tagIds[] = $value;

                    continue;
                }

                if (! is_string($value) || trim($value) === '' || ! in_array($type, [TagType::Discipline, TagType::Issue], true)) {
                    continue;
                }

                $normalizedValue = trim($value);
                $tag = Tag::query()
                    ->where('type', $type->value)
                    ->whereRaw("LOWER(name->>'ms') = ?", [mb_strtolower($normalizedValue)])
                    ->first();

                if (! $tag instanceof Tag) {
                    $tag = Tag::create([
                        'name' => ['ms' => $normalizedValue, 'en' => $normalizedValue],
                        'type' => $type->value,
                        'status' => 'pending',
                    ]);
                }

                $tagIds[] = (string) $tag->getKey();
            }
        }

        return Tag::query()->whereIn('id', array_values(array_unique($tagIds)))->get()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function addressState(?Address $address): array
    {
        $defaultCountryId = SharedFormSchema::preferredPublicCountryId();

        if (! $address instanceof Address) {
            return SharedFormSchema::hydrateAddressFormState([
                'country_id' => $defaultCountryId,
                'state_id' => null,
                'district_id' => null,
                'subdistrict_id' => null,
                'line1' => null,
                'line2' => null,
                'postcode' => null,
                'lat' => null,
                'lng' => null,
                'google_maps_url' => null,
                'google_place_id' => null,
                'waze_url' => null,
            ]);
        }

        return SharedFormSchema::hydrateAddressFormState([
            'country_id' => $address->country_id ?? $defaultCountryId,
            'state_id' => $address->state_id,
            'district_id' => $address->district_id,
            'subdistrict_id' => $address->subdistrict_id,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'postcode' => $address->postcode,
            'lat' => $address->lat,
            'lng' => $address->lng,
            'google_maps_url' => $address->google_maps_url,
            'google_place_id' => $address->google_place_id,
            'waze_url' => $address->waze_url,
        ]);
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     * @return list<array<string, mixed>>
     */
    private function contactsState(Collection $contacts): array
    {
        return $contacts
            ->sortBy('created_at')
            ->map(fn (Contact $contact): array => [
                'category' => $contact->category instanceof BackedEnum ? $contact->category->value : (string) $contact->category,
                'value' => $contact->value,
                'type' => $contact->type instanceof BackedEnum ? $contact->type->value : (string) $contact->type,
                'is_public' => (bool) $contact->is_public,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, SocialMedia>  $entries
     * @return list<array<string, mixed>>
     */
    private function socialMediaState(Collection $entries): array
    {
        return $entries
            ->sortBy('created_at')
            ->map(fn (SocialMedia $entry): array => [
                'platform' => $entry->platform instanceof BackedEnum ? $entry->platform->value : (string) $entry->platform,
                'username' => $entry->username,
                'url' => $entry->url,
            ])
            ->values()
            ->all();
    }
}
