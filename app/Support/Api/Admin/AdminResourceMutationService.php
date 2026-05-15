<?php

namespace App\Support\Api\Admin;

use App\Actions\DonationChannels\SaveDonationChannelAction;
use App\Actions\Events\SaveAdminEventAction;
use App\Actions\Inspirations\SaveInspirationAction;
use App\Actions\Institutions\SaveInstitutionAction;
use App\Actions\References\SaveReferenceAction;
use App\Actions\Reports\ResolveReportCategoryOptionsAction;
use App\Actions\Reports\ResolveReportEntityMetadataAction;
use App\Actions\Reports\SaveReportAction;
use App\Actions\Series\SaveSeriesAction;
use App\Actions\Spaces\SaveSpaceAction;
use App\Actions\Speakers\SaveSpeakerAction;
use App\Actions\Subdistricts\SaveSubdistrictAction;
use App\Actions\Tags\SaveTagAction;
use App\Actions\Venues\SaveVenueAction;
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
use App\Enums\InspirationCategory;
use App\Enums\InstitutionType;
use App\Enums\PostNominal;
use App\Enums\PreNominal;
use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Enums\RegistrationMode;
use App\Enums\SocialMediaPlatform;
use App\Enums\TagType;
use App\Enums\VenueType;
use App\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Inspirations\InspirationResource;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\References\ReferenceResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Series\SeriesResource;
use App\Filament\Resources\Spaces\SpaceResource;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Filament\Resources\Subdistricts\SubdistrictResource;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Resources\Venues\VenueResource;
use App\Forms\SharedFormSchema;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\Inspiration;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Report;
use App\Models\Series;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\Subdistrict;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Services\ContributionEntityMutationService;
use App\Support\Location\FederalTerritoryLocation;
use App\Support\Location\PreferredCountryResolver;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;

class AdminResourceMutationService
{
    public function __construct(
        private readonly ContributionEntityMutationService $contributionEntityMutationService,
        private readonly ResolveReportCategoryOptionsAction $resolveReportCategoryOptionsAction,
        private readonly ResolveReportEntityMetadataAction $resolveReportEntityMetadataAction,
        private readonly SaveDonationChannelAction $saveDonationChannelAction,
        private readonly SaveAdminEventAction $saveAdminEventAction,
        private readonly SaveInspirationAction $saveInspirationAction,
        private readonly SaveInstitutionAction $saveInstitutionAction,
        private readonly SaveReportAction $saveReportAction,
        private readonly SaveReferenceAction $saveReferenceAction,
        private readonly SaveSeriesAction $saveSeriesAction,
        private readonly SaveSpeakerAction $saveSpeakerAction,
        private readonly SaveSpaceAction $saveSpaceAction,
        private readonly SaveSubdistrictAction $saveSubdistrictAction,
        private readonly SaveTagAction $saveTagAction,
        private readonly SaveVenueAction $saveVenueAction,
    ) {}

    /**
     * @param  class-string  $resourceClass
     */
    public function supports(string $resourceClass): bool
    {
        return in_array($resourceClass, [
            DonationChannelResource::class,
            EventResource::class,
            InspirationResource::class,
            InstitutionResource::class,
            ReferenceResource::class,
            ReportResource::class,
            SeriesResource::class,
            SpeakerResource::class,
            SpaceResource::class,
            SubdistrictResource::class,
            TagResource::class,
            VenueResource::class,
        ], true);
    }

    /**
     * @param  class-string  $resourceClass
     * @return array<string, mixed>
     */
    public function schema(string $resourceClass, string $resourceKey, string $operation = 'create', ?Model $record = null): array
    {
        $updating = $operation === 'update';
        $defaults = $updating && $record instanceof Model
            ? $this->defaultsForRecord($record)
            : $this->defaultsForCreate($resourceClass);

        return match ($resourceClass) {
            DonationChannelResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'multipart/form-data',
                'slug_behavior' => 'not_applicable',
                'defaults' => $defaults,
                'current_media' => $record instanceof DonationChannel ? $this->mediaState($record, ['qr']) : null,
                'fields' => $this->donationChannelFields(),
                'catalogs' => [],
                'conditional_rules' => [
                    ['field' => 'bank_name', 'required_when' => ['method' => ['bank_account']]],
                    ['field' => 'account_number', 'required_when' => ['method' => ['bank_account']]],
                    ['field' => 'duitnow_type', 'required_when' => ['method' => ['duitnow']]],
                    ['field' => 'duitnow_value', 'required_when' => ['method' => ['duitnow']]],
                    ['field' => 'ewallet_provider', 'required_when' => ['method' => ['ewallet']]],
                ],
            ],
            EventResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'multipart/form-data',
                'slug_behavior' => 'auto_managed',
                'defaults' => $defaults,
                'current_media' => $record instanceof Event ? $this->mediaState($record, ['cover', 'poster', 'gallery']) : null,
                'fields' => $this->eventFields($updating),
                'catalogs' => [],
                'conditional_rules' => [
                    ['field' => 'custom_time', 'required_when' => ['prayer_time' => [EventPrayerTime::LainWaktu->value]]],
                    ['field' => 'organizer_id', 'required_when' => ['organizer_type' => [Institution::class, Speaker::class]]],
                ],
            ],
            InspirationResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'multipart/form-data',
                'slug_behavior' => 'not_applicable',
                'defaults' => $defaults,
                'current_media' => $record instanceof Inspiration ? $this->mediaState($record, ['main']) : null,
                'fields' => $this->inspirationFields(),
                'catalogs' => [],
                'conditional_rules' => [],
            ],
            InstitutionResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'multipart/form-data',
                'slug_behavior' => 'auto_managed',
                'defaults' => $defaults,
                'current_media' => $record instanceof Institution ? $this->mediaState($record, ['logo', 'cover', 'gallery']) : null,
                'fields' => $this->institutionFields($updating),
                'catalogs' => $this->addressCatalogs('address'),
                'conditional_rules' => [],
            ],
            ReferenceResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'multipart/form-data',
                'slug_behavior' => 'auto_managed',
                'defaults' => $defaults,
                'current_media' => $record instanceof Reference ? $this->mediaState($record, ['front_cover', 'back_cover', 'gallery']) : null,
                'fields' => $this->referenceFields(),
                'catalogs' => [],
                'conditional_rules' => [],
            ],
            ReportResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'multipart/form-data',
                'slug_behavior' => 'not_applicable',
                'defaults' => $defaults,
                'current_media' => $record instanceof Report ? $this->mediaState($record, ['evidence']) : null,
                'fields' => $this->reportFields(),
                'catalogs' => [],
                'conditional_rules' => [
                    ['field' => 'description', 'required_when' => ['category' => ['other']]],
                ],
            ],
            SeriesResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'multipart/form-data',
                'slug_behavior' => 'user_provided',
                'defaults' => $defaults,
                'current_media' => $record instanceof Series ? $this->mediaState($record, ['cover', 'gallery']) : null,
                'fields' => $this->seriesFields(),
                'catalogs' => [],
                'conditional_rules' => [],
            ],
            SpeakerResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'multipart/form-data',
                'slug_behavior' => 'auto_managed',
                'defaults' => $defaults,
                'current_media' => $record instanceof Speaker ? $this->mediaState($record, ['avatar', 'cover', 'gallery']) : null,
                'fields' => $this->speakerFields($updating),
                'catalogs' => $this->addressCatalogs('address'),
                'conditional_rules' => [
                    ['field' => 'job_title', 'required_when' => ['is_freelance' => [true]]],
                ],
            ],
            SpaceResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'application/json',
                'slug_behavior' => 'user_provided',
                'defaults' => $defaults,
                'fields' => $this->spaceFields(),
                'catalogs' => [],
                'conditional_rules' => [],
            ],
            VenueResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'multipart/form-data',
                'slug_behavior' => 'auto_managed',
                'defaults' => $defaults,
                'current_media' => $record instanceof Venue ? $this->mediaState($record, ['cover', 'gallery']) : null,
                'fields' => $this->venueFields($updating),
                'catalogs' => $this->addressCatalogs('address'),
                'conditional_rules' => [],
            ],
            SubdistrictResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'application/json',
                'slug_behavior' => 'not_applicable',
                'defaults' => $defaults,
                'fields' => $this->subdistrictFields(),
                'catalogs' => $this->subdistrictCatalogs(),
                'conditional_rules' => [
                    ['field' => 'district_id', 'required_unless' => ['state_id' => $this->federalTerritoryStateIds()]],
                ],
            ],
            TagResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'application/json',
                'slug_behavior' => 'auto_managed',
                'defaults' => $defaults,
                'fields' => $this->tagFields(),
                'catalogs' => [],
                'conditional_rules' => [],
            ],
            default => throw new \RuntimeException('Unsupported admin write resource.'),
        };
    }

    /**
     * @param  class-string  $resourceClass
     * @return array<string, mixed>
     */
    public function rules(string $resourceClass, bool $updating = false): array
    {
        return match ($resourceClass) {
            DonationChannelResource::class => $this->donationChannelRules($updating),
            EventResource::class => $this->eventRules($updating),
            InspirationResource::class => $this->inspirationRules($updating),
            InstitutionResource::class => $this->institutionRules($updating),
            ReferenceResource::class => $this->referenceRules($updating),
            ReportResource::class => $this->reportRules($updating),
            SeriesResource::class => $this->seriesRules($updating),
            SpeakerResource::class => $this->speakerRules($updating),
            SpaceResource::class => $this->spaceRules($updating),
            SubdistrictResource::class => $this->subdistrictRules($updating),
            TagResource::class => $this->tagRules($updating),
            VenueResource::class => $this->venueRules($updating),
            default => [],
        };
    }

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeValidatedPayload(string $resourceClass, array $validated, ?Model $record = null): array
    {
        if ($resourceClass === DonationChannelResource::class) {
            return $this->normalizeDonationChannelPayload($validated, $record);
        }

        if ($resourceClass === ReportResource::class) {
            return $this->normalizeReportPayload($validated);
        }

        if (! in_array($resourceClass, [InstitutionResource::class, SpeakerResource::class], true)) {
            return $validated;
        }

        if (! is_array($validated['address'] ?? null)) {
            return $validated;
        }

        $countryProvided = SharedFormSchema::countrySelectionProvided($validated['address']);
        $validated['address'] = SharedFormSchema::prepareAddressPersistenceData($validated['address']);

        if (
            $resourceClass === InstitutionResource::class
            && ! $countryProvided
            && $record instanceof Institution
            && is_int($record->addressModel?->country_id)
        ) {
            $validated['address']['country_id'] = $record->addressModel->country_id;
            $countryProvided = true;
        }

        if (! $countryProvided) {
            throw ValidationException::withMessages([
                'address.country_id' => __('The address country is required.'),
            ]);
        }

        if (! is_int($validated['address']['country_id'] ?? null)) {
            throw ValidationException::withMessages([
                'address.country_id' => __('The selected country is invalid.'),
            ]);
        }

        return $validated;
    }

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $validated
     */
    public function store(string $resourceClass, array $validated, User $actor): Model
    {
        return match ($resourceClass) {
            DonationChannelResource::class => $this->saveDonationChannelAction->handle($validated),
            EventResource::class => $this->saveAdminEventAction->handle($validated, $actor),
            InspirationResource::class => $this->saveInspirationAction->handle($validated),
            InstitutionResource::class => $this->saveInstitutionAction->handle($validated, $actor),
            ReferenceResource::class => $this->saveReferenceAction->handle($validated),
            ReportResource::class => $this->saveReportAction->handle($validated),
            SeriesResource::class => $this->saveSeriesAction->handle($validated),
            SpeakerResource::class => $this->saveSpeakerAction->handle($validated, $actor),
            SpaceResource::class => $this->saveSpaceAction->handle($validated),
            SubdistrictResource::class => $this->saveSubdistrictAction->handle($validated),
            TagResource::class => $this->saveTagAction->handle($validated),
            VenueResource::class => $this->saveVenueAction->handle($validated),
            default => throw new \RuntimeException('Unsupported admin write resource.'),
        };
    }

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $validated
     */
    public function update(string $resourceClass, Model $record, array $validated, User $actor): Model
    {
        return match ($resourceClass) {
            DonationChannelResource::class => $record instanceof DonationChannel
                ? $this->saveDonationChannelAction->handle($validated, $record)
                : throw new \RuntimeException('Expected donation channel record.'),
            EventResource::class => $record instanceof Event
                ? $this->saveAdminEventAction->handle($validated, $actor, $record)
                : throw new \RuntimeException('Expected event record.'),
            InspirationResource::class => $record instanceof Inspiration
                ? $this->saveInspirationAction->handle($validated, $record)
                : throw new \RuntimeException('Expected inspiration record.'),
            InstitutionResource::class => $record instanceof Institution
                ? $this->saveInstitutionAction->handle($validated, $actor, $record)
                : throw new \RuntimeException('Expected institution record.'),
            ReferenceResource::class => $record instanceof Reference
                ? $this->saveReferenceAction->handle($validated, $record)
                : throw new \RuntimeException('Expected reference record.'),
            ReportResource::class => $record instanceof Report
                ? $this->saveReportAction->handle($validated, $record)
                : throw new \RuntimeException('Expected report record.'),
            SeriesResource::class => $record instanceof Series
                ? $this->saveSeriesAction->handle($validated, $record)
                : throw new \RuntimeException('Expected series record.'),
            SpeakerResource::class => $record instanceof Speaker
                ? $this->saveSpeakerAction->handle($validated, $actor, $record)
                : throw new \RuntimeException('Expected speaker record.'),
            SpaceResource::class => $record instanceof Space
                ? $this->saveSpaceAction->handle($validated, $record)
                : throw new \RuntimeException('Expected space record.'),
            SubdistrictResource::class => $record instanceof Subdistrict
                ? $this->saveSubdistrictAction->handle($validated, $record)
                : throw new \RuntimeException('Expected subdistrict record.'),
            TagResource::class => $record instanceof Tag
                ? $this->saveTagAction->handle($validated, $record)
                : throw new \RuntimeException('Expected tag record.'),
            VenueResource::class => $record instanceof Venue
                ? $this->saveVenueAction->handle($validated, $record)
                : throw new \RuntimeException('Expected venue record.'),
            default => throw new \RuntimeException('Unsupported admin write resource.'),
        };
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     * @return array{normalized_payload: array<string, mixed>, warnings: list<array<string, string>>, destructive_media_fields: list<string>}
     */
    public function previewNormalizedPayload(array $normalizedPayload): array
    {
        $destructiveMediaFields = $this->destructiveMediaFields($normalizedPayload);

        return [
            'normalized_payload' => $this->previewPayload($normalizedPayload),
            'warnings' => array_values(array_map(
                fn (string $field): array => [
                    'code' => 'destructive_media_clear',
                    'field' => $field,
                    'message' => $this->destructiveMediaWarning($field),
                ],
                $destructiveMediaFields,
            )),
            'destructive_media_fields' => $destructiveMediaFields,
        ];
    }

    /**
     * @param  class-string  $resourceClass
     * @return array<string, mixed>
     */
    private function defaultsForCreate(string $resourceClass): array
    {
        $defaultCountryId = app(PreferredCountryResolver::class)->resolveId();

        return match ($resourceClass) {
            DonationChannelResource::class => [
                'donatable_type' => (string) (new Institution)->getMorphClass(),
                'donatable_id' => null,
                'label' => null,
                'recipient' => '',
                'method' => 'bank_account',
                'bank_code' => null,
                'bank_name' => null,
                'account_number' => null,
                'duitnow_type' => null,
                'duitnow_value' => null,
                'ewallet_provider' => null,
                'ewallet_handle' => null,
                'ewallet_qr_payload' => null,
                'reference_note' => null,
                'status' => 'unverified',
                'is_default' => false,
                'clear_qr' => false,
            ],
            EventResource::class => $this->saveAdminEventAction->defaultsForCreate(),
            InspirationResource::class => [
                'category' => InspirationCategory::QuranQuote->value,
                'locale' => $this->defaultSupportedLocale(),
                'title' => '',
                'content' => Inspiration::plainTextToRichContent(''),
                'source' => null,
                'is_active' => true,
                'clear_main' => false,
            ],
            InstitutionResource::class => [
                'type' => InstitutionType::Masjid->value,
                'is_active' => true,
                'address' => [
                    'country_id' => $defaultCountryId,
                ],
                'clear_logo' => false,
                'clear_cover' => false,
                'clear_gallery' => false,
            ],
            ReferenceResource::class => [
                'type' => ReferenceType::Book->value,
                'is_canonical' => false,
                'status' => 'verified',
                'is_active' => true,
                'social_media' => [],
                'clear_front_cover' => false,
                'clear_back_cover' => false,
                'clear_gallery' => false,
            ],
            ReportResource::class => [
                'entity_type' => 'event',
                'entity_id' => null,
                'category' => 'wrong_info',
                'description' => null,
                'status' => 'open',
                'reporter_id' => null,
                'handled_by' => null,
                'resolution_note' => null,
                'clear_evidence' => false,
            ],
            SeriesResource::class => [
                'title' => '',
                'slug' => '',
                'description' => null,
                'visibility' => 'public',
                'is_active' => true,
                'languages' => [],
                'clear_cover' => false,
                'clear_gallery' => false,
            ],
            SpeakerResource::class => [
                'gender' => Gender::Male->value,
                'is_freelance' => false,
                'is_active' => true,
                'address' => [
                    'country_id' => $defaultCountryId,
                    'state_id' => null,
                    'district_id' => null,
                    'subdistrict_id' => null,
                ],
                'clear_avatar' => false,
                'clear_cover' => false,
                'clear_gallery' => false,
            ],
            VenueResource::class => [
                'type' => VenueType::Dewan->value,
                'status' => 'verified',
                'is_active' => true,
                'facilities' => [],
                'address' => [
                    'country_id' => 132,
                ],
                'clear_cover' => false,
                'clear_gallery' => false,
            ],
            SpaceResource::class => [
                'name' => '',
                'slug' => '',
                'capacity' => null,
                'is_active' => true,
                'institutions' => [],
            ],
            SubdistrictResource::class => [
                'district_id' => null,
            ],
            TagResource::class => [
                'name' => [
                    'ms' => '',
                    'en' => '',
                ],
                'type' => TagType::Domain->value,
                'status' => 'verified',
                'order_column' => null,
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultsForRecord(Model $record): array
    {
        $defaults = $record instanceof DonationChannel || $record instanceof Inspiration || $record instanceof Report || $record instanceof Subdistrict || $record instanceof Tag || $record instanceof Series || $record instanceof Space
            ? []
            : $this->contributionEntityMutationService->stateFor($record);

        if ($record instanceof Inspiration) {
            $category = $record->category;

            $defaults = [
                'category' => $category instanceof InspirationCategory
                    ? $category->value
                    : (is_string($category) && $category !== '' ? $category : InspirationCategory::QuranQuote->value),
                'locale' => (string) $record->locale,
                'title' => $record->title,
                'content' => $record->content,
                'source' => $record->source,
                'is_active' => (bool) $record->is_active,
                'clear_main' => false,
            ];
        }

        if ($record instanceof DonationChannel) {
            $defaults = [
                'donatable_type' => $this->normalizeDonationChannelOwnerType($record->donatable_type),
                'donatable_id' => (string) $record->donatable_id,
                'label' => $record->label,
                'recipient' => $record->recipient,
                'method' => (string) $record->method,
                'bank_code' => $record->bank_code,
                'bank_name' => $record->bank_name,
                'account_number' => $record->account_number,
                'duitnow_type' => $record->duitnow_type,
                'duitnow_value' => $record->duitnow_value,
                'ewallet_provider' => $record->ewallet_provider,
                'ewallet_handle' => $record->ewallet_handle,
                'ewallet_qr_payload' => $record->ewallet_qr_payload,
                'reference_note' => $record->reference_note,
                'status' => (string) $record->status,
                'is_default' => (bool) $record->is_default,
                'clear_qr' => false,
            ];
        }

        if ($record instanceof Report) {
            $defaults = [
                'entity_type' => (string) $record->entity_type,
                'entity_id' => (string) $record->entity_id,
                'category' => (string) $record->category,
                'description' => $record->description,
                'status' => (string) $record->status,
                'reporter_id' => $record->reporter_id !== null ? (string) $record->reporter_id : null,
                'handled_by' => $record->handled_by !== null ? (string) $record->handled_by : null,
                'resolution_note' => $record->resolution_note,
                'clear_evidence' => false,
            ];
        }

        if ($record instanceof Institution) {
            $defaults['status'] = $record->status;
            $defaults['is_active'] = (bool) $record->is_active;
            $defaults['allow_public_event_submission'] = (bool) $record->allow_public_event_submission;
            $defaults['clear_logo'] = false;
            $defaults['clear_cover'] = false;
            $defaults['clear_gallery'] = false;
        }

        if ($record instanceof Speaker) {
            if (is_array($defaults['address'] ?? null)) {
                unset(
                    $defaults['address']['line1'],
                    $defaults['address']['line2'],
                    $defaults['address']['postcode'],
                    $defaults['address']['lat'],
                    $defaults['address']['lng'],
                    $defaults['address']['google_maps_url'],
                    $defaults['address']['google_place_id'],
                    $defaults['address']['waze_url'],
                );
            }

            unset(
                $defaults['institution_id'],
                $defaults['institution_position'],
            );

            $defaults['status'] = $record->status;
            $defaults['is_active'] = (bool) $record->is_active;
            $defaults['allow_public_event_submission'] = (bool) $record->allow_public_event_submission;
            $defaults['clear_avatar'] = false;
            $defaults['clear_cover'] = false;
            $defaults['clear_gallery'] = false;
        }

        if ($record instanceof Reference) {
            $defaults['is_canonical'] = (bool) $record->is_canonical;
            $defaults['status'] = $record->status;
            $defaults['is_active'] = (bool) $record->is_active;
            $defaults['clear_front_cover'] = false;
            $defaults['clear_back_cover'] = false;
            $defaults['clear_gallery'] = false;
        }

        if ($record instanceof Series) {
            $defaults = [
                'title' => $record->title,
                'slug' => $record->slug,
                'description' => $record->description,
                'visibility' => (string) $record->visibility,
                'is_active' => (bool) $record->is_active,
                'languages' => $record->languages()->pluck('languages.id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
                'clear_cover' => false,
                'clear_gallery' => false,
            ];
        }

        if ($record instanceof Event) {
            $defaults = $this->saveAdminEventAction->formStateForRecord($record);
        }

        if ($record instanceof Venue) {
            $defaults['status'] = $record->status;
            $defaults['is_active'] = (bool) $record->is_active;
            $defaults['clear_cover'] = false;
            $defaults['clear_gallery'] = false;
        }

        if ($record instanceof Space) {
            $defaults = [
                'name' => $record->name,
                'slug' => $record->slug,
                'capacity' => $record->capacity,
                'is_active' => (bool) $record->is_active,
                'institutions' => $record->institutions()->pluck('institutions.id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            ];
        }

        if ($record instanceof Subdistrict) {
            $defaults = [
                'country_id' => (int) $record->country_id,
                'state_id' => (int) $record->state_id,
                'district_id' => $record->district_id !== null ? (int) $record->district_id : null,
                'name' => $record->name,
            ];
        }

        if ($record instanceof Tag) {
            $defaults = [
                'name' => [
                    'ms' => $record->getTranslation('name', 'ms', false) ?: $record->getTranslation('name', 'en', false) ?: '',
                    'en' => $record->getTranslation('name', 'en', false) ?: $record->getTranslation('name', 'ms', false) ?: '',
                ],
                'type' => (string) $record->type,
                'status' => (string) $record->status,
                'order_column' => $record->order_column,
            ];
        }

        return $defaults;
    }

    private function recordKey(Model $record): string
    {
        return (string) $record->getRouteKey();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function destructiveMediaFields(array $payload): array
    {
        $fields = [];

        foreach ($payload as $field => $value) {
            if (! is_string($field) || ! str_starts_with($field, 'clear_') || $value !== true) {
                continue;
            }

            $fields[] = $field;
        }

        return array_values(array_unique($fields));
    }

    private function destructiveMediaWarning(string $field): string
    {
        $label = ucwords(str_replace('_', ' ', substr($field, 6)));

        return __('The :field flag will clear the existing :label media collection before saving.', [
            'field' => $field,
            'label' => $label,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function previewPayload(array $payload): array
    {
        $preview = [];

        foreach ($payload as $key => $value) {
            $preview[$key] = $this->previewValue($value);
        }

        return $preview;
    }

    private function previewValue(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return [
                'file_name' => $value->getClientOriginalName(),
                'mime_type' => $value->getClientMimeType(),
                'size' => $value->getSize(),
            ];
        }

        if (is_array($value)) {
            return array_map($this->previewValue(...), $value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeReportPayload(array $validated): array
    {
        $entityType = is_string($validated['entity_type'] ?? null)
            ? trim($validated['entity_type'])
            : '';

        if (! in_array($entityType, $this->resolveReportEntityMetadataAction->validKeys(), true)) {
            throw ValidationException::withMessages([
                'entity_type' => __('The selected report entity type is invalid.'),
            ]);
        }

        $entityMetadata = $this->resolveReportEntityMetadataAction->handle($entityType);
        $entityModelClass = $entityMetadata['model_class'];
        $entityId = is_scalar($validated['entity_id'] ?? null)
            ? trim((string) $validated['entity_id'])
            : '';

        if ($entityId === '' || ! $entityModelClass::query()->whereKey($entityId)->exists()) {
            throw ValidationException::withMessages([
                'entity_id' => __('The selected report entity is invalid.'),
            ]);
        }

        $category = is_string($validated['category'] ?? null)
            ? trim($validated['category'])
            : '';

        if (! in_array($category, $this->resolveReportCategoryOptionsAction->validKeys($entityType), true)) {
            throw ValidationException::withMessages([
                'category' => __('The selected report category is invalid for this entity type.'),
            ]);
        }

        $validated['entity_type'] = $entityType;
        $validated['entity_id'] = $entityId;
        $validated['category'] = $category;

        foreach (['reporter_id', 'handled_by'] as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $validated[$field] = $this->normalizeOptionalUserKey($validated[$field], $field);
        }

        return $validated;
    }

    private function normalizeOptionalUserKey(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = is_scalar($value) ? trim((string) $value) : '';

        if ($normalized === '' || ! User::query()->whereKey($normalized)->exists()) {
            throw ValidationException::withMessages([
                $field => __('The selected user is invalid.'),
            ]);
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function imageMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp'];
    }

    /**
     * @return list<string>
     */
    private function evidenceMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeDonationChannelPayload(array $validated, ?Model $record = null): array
    {
        $ownerType = array_key_exists('donatable_type', $validated)
            ? $this->normalizeDonationChannelOwnerType($validated['donatable_type'])
            : ($record instanceof DonationChannel ? $this->normalizeDonationChannelOwnerType($record->donatable_type) : null);

        $ownerId = array_key_exists('donatable_id', $validated)
            ? trim((string) $validated['donatable_id'])
            : ($record instanceof DonationChannel ? (string) $record->donatable_id : '');

        if (! is_string($ownerType) || $ownerType === '') {
            throw ValidationException::withMessages([
                'donatable_type' => __('The selected donation channel owner type is invalid.'),
            ]);
        }

        if ($ownerId === '') {
            throw ValidationException::withMessages([
                'donatable_id' => __('The selected donation channel owner is invalid.'),
            ]);
        }

        $ownerModelClass = $this->donationChannelOwnerModelClass($ownerType);

        if (! $ownerModelClass::query()->whereKey($ownerId)->exists()) {
            throw ValidationException::withMessages([
                'donatable_id' => __('The selected donation channel owner is invalid.'),
            ]);
        }

        $validated['donatable_type'] = $ownerType;
        $validated['donatable_id'] = $ownerId;

        return $validated;
    }

    private function normalizeDonationChannelOwnerType(mixed $value): string
    {
        $normalized = is_scalar($value) ? trim((string) $value) : '';

        return match ($normalized) {
            'institution', 'institutions', Institution::class => (string) (new Institution)->getMorphClass(),
            'speaker', 'speakers', Speaker::class => (string) (new Speaker)->getMorphClass(),
            'event', 'events', Event::class => (string) (new Event)->getMorphClass(),
            default => throw ValidationException::withMessages([
                'donatable_type' => __('The selected donation channel owner type is invalid.'),
            ]),
        };
    }

    /**
     * @return class-string<Model>
     */
    private function donationChannelOwnerModelClass(string $ownerType): string
    {
        return match ($ownerType) {
            (string) (new Institution)->getMorphClass() => Institution::class,
            (string) (new Speaker)->getMorphClass() => Speaker::class,
            (string) (new Event)->getMorphClass() => Event::class,
            default => throw new \RuntimeException('Unsupported donation channel owner type.'),
        };
    }

    /**
     * @return list<string>
     */
    private function donationChannelOwnerTypeValues(): array
    {
        return [
            (string) (new Institution)->getMorphClass(),
            (string) (new Speaker)->getMorphClass(),
            (string) (new Event)->getMorphClass(),
        ];
    }

    /**
     * @return list<string>
     */
    private function donationChannelAcceptedOwnerTypes(): array
    {
        return array_values(array_unique([
            ...$this->donationChannelOwnerTypeValues(),
            Institution::class,
            Speaker::class,
            Event::class,
            'institutions',
            'speakers',
            'events',
        ]));
    }

    /**
     * @return list<string>
     */
    private function logoMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
    }

    private function maxUploadSizeKb(): int
    {
        return (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function institutionFields(bool $updating): array
    {
        $fields = [
            $this->field('name', 'string', required: true, maxLength: 255),
            $this->field('nickname', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta(
                explicitNull: 'preserve_existing',
            )),
            $this->field('type', 'string', required: true, default: InstitutionType::Masjid->value, allowedValues: $this->enumValues(InstitutionType::class)),
            $this->field('description', 'string', required: false),
            $this->field('status', 'string', required: true, allowedValues: ['unverified', 'pending', 'verified', 'rejected']),
            $this->field('is_active', 'boolean', required: false, default: true),
            $this->field('address', 'object', required: ! $updating, meta: [
                'mutation_semantics' => 'deep_merge_when_present',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'empty_object' => $updating
                        ? 'preserve_existing_when_record_has_address'
                        : 'invalid_without_country',
                    'explicit_null_children' => 'clear_supported_nullable_fields',
                ],
                'safe_client_strategy' => 'send_only_nested_fields_that_should_change',
                'nested_field_omission' => 'preserve_existing',
            ]),
            $this->field('address.country_id', 'integer', required: ! $updating, default: SharedFormSchema::preferredPublicCountryId(), meta: [
                'required_on_create' => true,
                'required_on_update' => false,
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted_on_update' => 'preserve_existing_if_available',
                    'explicit_null' => 'invalid_without_existing_country',
                ],
            ]),
            $this->field('contacts', 'array<object>', required: false, meta: $this->contactCollectionMeta()),
            $this->field('social_media', 'array<object>', required: false, meta: $this->socialMediaCollectionMeta()),
            $this->field('logo', 'file', required: false, acceptedMimeTypes: $this->logoMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('clear_logo', 'boolean', required: false, default: false),
            $this->field('clear_cover', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
        ];

        if ($updating) {
            $fields[] = $this->field('allow_public_event_submission', 'boolean', required: false);
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function contactCollectionMeta(): array
    {
        return [
            'collection_semantics' => $this->replaceCollectionSemantics(),
            'item_schema' => $this->contactItemSchema(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function socialMediaCollectionMeta(): array
    {
        return [
            'collection_semantics' => $this->replaceCollectionSemantics(),
            'input_normalization' => [
                'kind' => 'canonical_social_handle_storage',
                'handle_platforms' => $this->socialHandlePlatforms(),
                'platform_aliases' => [
                    'x' => [
                        'normalizes_to' => SocialMediaPlatform::Twitter->value,
                        'accepted_by_write_validation' => false,
                    ],
                ],
                'canonical_storage' => [
                    'identifier_field' => 'username',
                    'url_field' => 'url',
                    'handle_platform_url_storage' => 'may_be_null_after_normalization',
                ],
            ],
            'item_schema' => $this->socialMediaItemSchema(),
        ];
    }

    /**
     * @return list<string>
     */
    private function socialHandlePlatforms(): array
    {
        return [
            SocialMediaPlatform::Facebook->value,
            SocialMediaPlatform::Twitter->value,
            SocialMediaPlatform::Instagram->value,
            SocialMediaPlatform::YouTube->value,
            SocialMediaPlatform::TikTok->value,
            SocialMediaPlatform::Telegram->value,
            SocialMediaPlatform::WhatsApp->value,
            SocialMediaPlatform::LinkedIn->value,
            SocialMediaPlatform::Threads->value,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function donationChannelFields(): array
    {
        return [
            $this->field('donatable_type', 'string', required: true, default: (string) (new Institution)->getMorphClass(), allowedValues: $this->donationChannelOwnerTypeValues(), meta: [
                'mutation_semantics' => 'replace_scalar_with_owner_alias_normalization',
                'accepted_aliases' => [
                    'institutions' => (string) (new Institution)->getMorphClass(),
                    Institution::class => (string) (new Institution)->getMorphClass(),
                    'speakers' => (string) (new Speaker)->getMorphClass(),
                    Speaker::class => (string) (new Speaker)->getMorphClass(),
                    'events' => (string) (new Event)->getMorphClass(),
                    Event::class => (string) (new Event)->getMorphClass(),
                ],
                'paired_with' => 'donatable_id',
            ]),
            $this->field('donatable_id', 'string', required: true, meta: [
                'paired_with' => 'donatable_type',
                'relation_lookup' => 'resolved_from_normalized_owner_type',
            ]),
            $this->field('label', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('recipient', 'string', required: true, maxLength: 255),
            $this->field('method', 'string', required: true, default: 'bank_account', allowedValues: ['bank_account', 'duitnow', 'ewallet'], meta: [
                'mutation_semantics' => 'replace_scalar_with_method_partition_reset',
                'switch_clears_fields' => [
                    'bank_account' => ['duitnow_type', 'duitnow_value', 'ewallet_provider', 'ewallet_handle', 'ewallet_qr_payload'],
                    'duitnow' => ['bank_code', 'bank_name', 'account_number', 'ewallet_provider', 'ewallet_handle', 'ewallet_qr_payload'],
                    'ewallet' => ['bank_code', 'bank_name', 'account_number', 'duitnow_type', 'duitnow_value'],
                ],
            ]),
            $this->field('bank_code', 'string', required: false, maxLength: 32, meta: $this->trimmedStringMutationMeta()),
            $this->field('bank_name', 'string', required: false, maxLength: 255),
            $this->field('account_number', 'string', required: false, maxLength: 64),
            $this->field('duitnow_type', 'string', required: false, maxLength: 64),
            $this->field('duitnow_value', 'string', required: false, maxLength: 255),
            $this->field('ewallet_provider', 'string', required: false, maxLength: 64),
            $this->field('ewallet_handle', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('ewallet_qr_payload', 'string', required: false, meta: $this->trimmedStringMutationMeta()),
            $this->field('reference_note', 'string', required: false, meta: $this->trimmedStringMutationMeta()),
            $this->field('status', 'string', required: true, default: 'unverified', allowedValues: ['unverified', 'verified', 'rejected', 'inactive']),
            $this->field('is_default', 'boolean', required: false, default: false),
            $this->field('qr', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('clear_qr', 'boolean', required: false, default: false),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reportFields(): array
    {
        return [
            $this->field('entity_type', 'string', required: true, default: 'event', allowedValues: $this->resolveReportEntityMetadataAction->validKeys(), meta: [
                'mutation_semantics' => 'replace_scalar',
                'paired_with' => 'entity_id',
            ]),
            $this->field('entity_id', 'string', required: true, meta: [
                'mutation_semantics' => 'replace_scalar',
                'paired_with' => 'entity_type',
                'relation_lookup' => 'resolved_from_entity_type',
            ]),
            $this->field('category', 'string', required: true, default: 'wrong_info', allowedValues: $this->resolveReportCategoryOptionsAction->validKeys(), meta: [
                'mutation_semantics' => 'replace_scalar',
                'allowed_values_resolved_from' => 'entity_type',
            ]),
            $this->field('description', 'string', required: false, maxLength: 2000, meta: $this->trimmedStringMutationMeta()),
            $this->field('status', 'string', required: true, default: 'open', allowedValues: ['open', 'triaged', 'resolved', 'dismissed']),
            $this->field('reporter_id', 'string', required: false, meta: $this->nullableRelationScalarMeta('users')),
            $this->field('handled_by', 'string', required: false, meta: $this->nullableRelationScalarMeta('users')),
            $this->field('resolution_note', 'string', required: false, maxLength: 2000, meta: $this->trimmedStringMutationMeta()),
            $this->field('evidence', 'array<file>', required: false, acceptedMimeTypes: $this->evidenceMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), maxFiles: 8, meta: $this->multipleMediaFieldMutationMeta('clear_evidence', explicitNull: 'preserve_existing_collection')),
            $this->field('clear_evidence', 'boolean', required: false, default: false),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function speakerFields(bool $updating): array
    {
        $fields = [
            $this->field('name', 'string', required: true, maxLength: 255),
            $this->field('gender', 'string', required: true, default: Gender::Male->value, allowedValues: $this->enumValues(Gender::class)),
            $this->field('is_freelance', 'boolean', required: false, default: false),
            $this->field('job_title', 'string', required: false, maxLength: 255, meta: array_merge(
                $this->trimmedStringMutationMeta(),
                [
                    'coerced_when' => [
                        'is_freelance_false' => 'stored_as_null',
                    ],
                ],
            )),
            $this->field('honorific', 'array<string>', required: false, allowedValues: $this->enumValues(Honorific::class), meta: $this->simpleArrayCollectionMeta()),
            $this->field('pre_nominal', 'array<string>', required: false, allowedValues: $this->enumValues(PreNominal::class), meta: $this->simpleArrayCollectionMeta()),
            $this->field('post_nominal', 'array<string>', required: false, allowedValues: $this->enumValues(PostNominal::class), meta: $this->simpleArrayCollectionMeta()),
            $this->field('bio', 'rich_text', required: false),
            $this->field('qualifications', 'array<object>', required: false, meta: $this->qualificationCollectionMeta()),
            $this->field('language_ids', 'array<int>', required: false, meta: [
                'collection_semantics' => $this->replaceCollectionSemantics(
                    submittedArray: 'replace_relation_sync',
                    itemIdsPreserved: null,
                    ordering: null,
                ),
                'relation' => 'languages',
            ]),
            $this->field('status', 'string', required: true, allowedValues: ['pending', 'verified', 'rejected']),
            $this->field('is_active', 'boolean', required: false, default: true),
            $this->field('address', 'object', required: ! $updating, meta: [
                'mutation_semantics' => 'deep_merge_when_present_visible_fields_only',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'empty_object' => 'invalid_without_country',
                    'explicit_null_children' => 'clear_supported_nullable_fields',
                ],
                'nested_field_omission' => 'preserve_existing_visible_fields',
                'prohibited_nested_fields' => [
                    'line1',
                    'line2',
                    'postcode',
                    'lat',
                    'lng',
                    'google_maps_url',
                    'google_place_id',
                    'waze_url',
                ],
                'safe_client_strategy' => 'omit_address_to_preserve_or_resend_country_when_mutating',
            ]),
            $this->field('address.country_id', 'integer', required: ! $updating, default: SharedFormSchema::preferredPublicCountryId(), meta: [
                'required_on_create' => true,
                'required_on_update' => false,
                'required_when_parent_present_on_update' => true,
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted_on_update' => 'preserve_existing_if_address_omitted',
                    'explicit_null' => 'invalid_without_country',
                ],
            ]),
            $this->field('contacts', 'array<object>', required: false, meta: $this->contactCollectionMeta()),
            $this->field('social_media', 'array<object>', required: false, meta: $this->socialMediaCollectionMeta()),
            $this->field('avatar', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('clear_avatar', 'boolean', required: false, default: false),
            $this->field('clear_cover', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
        ];

        if ($updating) {
            $fields[] = $this->field('allow_public_event_submission', 'boolean', required: false);
        }

        return $fields;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function referenceFields(): array
    {
        return [
            $this->field('title', 'string', required: true, maxLength: 255),
            $this->field('author', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('type', 'string', required: true, default: ReferenceType::Book->value, allowedValues: $this->enumValues(ReferenceType::class)),
            $this->field('parent_reference_id', 'string', required: false, meta: [
                'relation' => 'references',
                'accepted_parent_scope' => 'root_book_references_only',
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'explicit_null' => 'convert_to_root_reference_and_clear_part_fields',
                ],
            ]),
            $this->field('part_type', 'string', required: false, default: ReferencePartType::Jilid->value, allowedValues: $this->enumValues(ReferencePartType::class), meta: [
                'used_when' => 'parent_reference_id_is_present_and_type_is_book',
            ]),
            $this->field('part_number', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('part_label', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('publication_year', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('publisher', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('description', 'string', required: false),
            $this->field('is_canonical', 'boolean', required: false, default: false),
            $this->field('status', 'string', required: true, default: 'verified', allowedValues: ['pending', 'verified']),
            $this->field('is_active', 'boolean', required: false, default: true),
            $this->field('social_media', 'array<object>', required: false, meta: $this->socialMediaCollectionMeta()),
            $this->field('front_cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('back_cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), maxFiles: 10),
            $this->field('clear_front_cover', 'boolean', required: false, default: false),
            $this->field('clear_back_cover', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function seriesFields(): array
    {
        return [
            $this->field('title', 'string', required: true, maxLength: 255),
            $this->field('slug', 'string', required: true, maxLength: 255, meta: [
                'mutation_semantics' => 'replace_scalar',
                'normalization' => ['trim' => true],
                'uniqueness_scope' => 'series.slug',
            ]),
            $this->field('description', 'string', required: false, maxLength: 5000, meta: $this->trimmedStringMutationMeta()),
            $this->field('visibility', 'string', required: true, default: 'public', allowedValues: ['public', 'unlisted', 'private']),
            $this->field('is_active', 'boolean', required: false, default: true),
            $this->field('languages', 'array<int>', required: false, meta: $this->relationCollectionMeta(
                'languages',
                submittedArray: 'replace_relation_sync',
                itemIdsPreserved: null,
                ordering: null,
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_relation_ids',
            )),
            $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('clear_cover', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inspirationFields(): array
    {
        return [
            $this->field('category', 'string', required: true, default: InspirationCategory::QuranQuote->value, allowedValues: $this->enumValues(InspirationCategory::class)),
            $this->field('locale', 'string', required: true, default: $this->defaultSupportedLocale(), allowedValues: $this->supportedLocaleValues()),
            $this->field('title', 'string', required: true, maxLength: 255),
            $this->field('content', 'rich_text', required: true, meta: [
                'input_normalization' => [
                    'kind' => 'rich_text_document',
                    'accepts_plain_string' => true,
                ],
            ]),
            $this->field('source', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('is_active', 'boolean', required: false, default: true),
            $this->field('main', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: $this->singleMediaFieldMutationMeta('clear_main')),
            $this->field('clear_main', 'boolean', required: false, default: false),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function spaceFields(): array
    {
        return [
            $this->field('name', 'string', required: true, maxLength: 255),
            $this->field('slug', 'string', required: true, maxLength: 255, meta: [
                'mutation_semantics' => 'replace_scalar',
                'normalization' => ['trim' => true],
                'uniqueness_scope' => 'spaces.slug',
            ]),
            $this->field('capacity', 'integer', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'explicit_null' => 'clear_to_null',
                ],
                'normalization' => [
                    'empty_string_at_mutation_layer' => 'null',
                    'integer_cast' => true,
                    'minimum' => 1,
                ],
            ]),
            $this->field('is_active', 'boolean', required: false, default: true),
            $this->field('institutions', 'array<string>', required: false, meta: $this->relationCollectionMeta(
                'institutions',
                submittedArray: 'replace_relation_sync',
                itemIdsPreserved: null,
                ordering: null,
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_institution_ids',
            )),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function venueFields(bool $updating): array
    {
        return [
            $this->field('name', 'string', required: ! $updating, maxLength: 255),
            $this->field('type', 'string', required: ! $updating, default: VenueType::Dewan->value, allowedValues: $this->enumValues(VenueType::class)),
            $this->field('status', 'string', required: ! $updating, default: 'verified', allowedValues: ['unverified', 'pending', 'verified', 'rejected']),
            $this->field('is_active', 'boolean', required: false, default: true),
            $this->field('facilities', 'array<string>', required: false, allowedValues: $this->venueFacilityValues(), meta: $this->facilitiesCollectionMeta()),
            $this->field('address', 'object', required: ! $updating, meta: [
                'mutation_semantics' => 'deep_merge_when_present',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'empty_object' => $updating ? 'delete_existing_address' : 'invalid_without_country',
                    'explicit_null_children' => 'clear_supported_nullable_fields',
                ],
                'safe_client_strategy' => 'fetch_current_record_before_editing_nested_address',
                'nested_field_omission' => 'preserve_existing',
            ]),
            $this->field('address.country_id', 'integer', required: ! $updating, default: SharedFormSchema::preferredPublicCountryId(), meta: [
                'required_on_create' => true,
                'required_on_update' => false,
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted_on_update' => 'preserve_existing_if_available',
                    'explicit_null' => 'invalid_country_selection',
                ],
            ]),
            $this->field('contacts', 'array<object>', required: false, meta: $this->contactCollectionMeta()),
            $this->field('social_media', 'array<object>', required: false, meta: $this->socialMediaCollectionMeta()),
            $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('clear_cover', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function subdistrictFields(): array
    {
        return [
            $this->field('country_id', 'integer', required: true, meta: [
                'mutation_semantics' => 'replace_scalar',
                'relation' => 'countries',
            ]),
            $this->field('state_id', 'integer', required: true, meta: [
                'mutation_semantics' => 'replace_scalar',
                'relation' => 'states',
                'must_match' => ['country_id'],
            ]),
            $this->field('district_id', 'integer', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'relation' => 'districts',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'explicit_null' => 'allowed_only_for_federal_territory_state',
                ],
                'required_unless' => [
                    'state_id' => $this->federalTerritoryStateIds(),
                ],
                'must_match' => ['country_id', 'state_id'],
            ]),
            $this->field('name', 'string', required: true, maxLength: 255, meta: [
                'mutation_semantics' => 'replace_scalar',
                'normalization' => ['trim' => true],
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tagFields(): array
    {
        return [
            $this->field('name', 'object', required: true, meta: [
                'mutation_semantics' => 'replace_translation_object',
                'translation_fallback' => [
                    'en' => 'name.ms',
                ],
            ]),
            $this->field('name.ms', 'string', required: true, maxLength: 255, meta: [
                'mutation_semantics' => 'replace_scalar',
                'normalization' => ['trim' => true],
            ]),
            $this->field('name.en', 'string', required: false, maxLength: 255, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'fallback_to_name.ms',
                    'explicit_null' => 'fallback_to_name.ms',
                ],
                'normalization' => [
                    'trim' => true,
                    'empty_string_at_mutation_layer' => 'fallback_to_name.ms',
                ],
            ]),
            $this->field('type', 'string', required: true, default: TagType::Domain->value, allowedValues: $this->enumValues(TagType::class), meta: [
                'mutation_semantics' => 'replace_scalar',
            ]),
            $this->field('status', 'string', required: true, default: 'verified', allowedValues: ['pending', 'verified']),
            $this->field('order_column', 'integer', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'explicit_null' => 'recompute_with_sortable_scope',
                ],
                'normalization' => [
                    'empty_string_at_mutation_layer' => 'recompute_with_sortable_scope',
                    'integer_cast' => true,
                ],
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function eventFields(bool $updating): array
    {
        $fields = [
            $this->field('title', 'string', required: ! $updating, maxLength: 255),
            $this->field('description', 'rich_text', required: false),
            $this->field('event_date', 'date', required: ! $updating),
            $this->field('prayer_time', 'string', required: ! $updating, default: EventPrayerTime::LainWaktu->value, allowedValues: $this->enumValues(EventPrayerTime::class)),
            $this->field('custom_time', 'string', required: false, maxLength: 32),
            $this->field('end_time', 'string', required: false, maxLength: 32),
            $this->field('timezone', 'string', required: ! $updating, default: 'Asia/Kuala_Lumpur', maxLength: 64),
            $this->field('event_format', 'string', required: ! $updating, default: EventFormat::Physical->value, allowedValues: $this->enumValues(EventFormat::class)),
            $this->field('visibility', 'string', required: ! $updating, default: EventVisibility::Public->value, allowedValues: $this->enumValues(EventVisibility::class)),
            $this->field('event_url', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta(omitted: 'preserve_existing_via_server_state_merge')),
            $this->field('live_url', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta(omitted: 'preserve_existing_via_server_state_merge')),
            $this->field('recording_url', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta(omitted: 'preserve_existing_via_server_state_merge')),
            $this->field('gender', 'string', required: ! $updating, default: EventGenderRestriction::All->value, allowedValues: $this->enumValues(EventGenderRestriction::class)),
            $this->field('age_group', 'array<string>', required: ! $updating, allowedValues: $this->enumValues(EventAgeGroup::class), meta: [
                'collection_semantics' => $this->replaceCollectionSemantics(
                    explicitNull: 'invalid_type',
                    emptyArray: 'invalid_minimum_size',
                    itemIdsPreserved: null,
                    ordering: null,
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_age_group_array',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
            ]),
            $this->field('children_allowed', 'boolean', required: false, default: false),
            $this->field('is_muslim_only', 'boolean', required: false, default: false),
            $this->field('languages', 'array<int>', required: false, meta: $this->relationCollectionMeta(
                'languages',
                submittedArray: 'replace_relation_sync',
                itemIdsPreserved: null,
                ordering: null,
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_relation_ids',
                omitted: 'preserve_existing_collection_via_server_state_merge',
            )),
            $this->field('event_type', 'array<string>', required: ! $updating, allowedValues: $this->enumValues(EventType::class), meta: [
                'collection_semantics' => $this->replaceCollectionSemantics(
                    explicitNull: 'invalid_type',
                    emptyArray: 'invalid_minimum_size',
                    itemIdsPreserved: null,
                    ordering: null,
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_event_type_array',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
            ]),
            $this->field('domain_tags', 'array<string>', required: false, meta: array_merge(
                $this->relationCollectionMeta(
                    'tags',
                    submittedArray: 'replace_relation_sync',
                    itemIdsPreserved: null,
                    ordering: null,
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_tag_ids',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                ['tag_type' => TagType::Domain->value],
            )),
            $this->field('discipline_tags', 'array<string>', required: false, meta: array_merge(
                $this->relationCollectionMeta(
                    'tags',
                    submittedArray: 'replace_relation_sync',
                    itemIdsPreserved: null,
                    ordering: null,
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_tag_ids',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                ['tag_type' => TagType::Discipline->value],
            )),
            $this->field('source_tags', 'array<string>', required: false, meta: array_merge(
                $this->relationCollectionMeta(
                    'tags',
                    submittedArray: 'replace_relation_sync',
                    itemIdsPreserved: null,
                    ordering: null,
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_tag_ids',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                ['tag_type' => TagType::Source->value],
            )),
            $this->field('issue_tags', 'array<string>', required: false, meta: array_merge(
                $this->relationCollectionMeta(
                    'tags',
                    submittedArray: 'replace_relation_sync',
                    itemIdsPreserved: null,
                    ordering: null,
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_tag_ids',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                ['tag_type' => TagType::Issue->value],
            )),
            $this->field('references', 'array<string>', required: false, meta: $this->relationCollectionMeta(
                'references',
                submittedArray: 'replace_relation_sync',
                itemIdsPreserved: null,
                ordering: null,
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_reference_ids',
                omitted: 'preserve_existing_collection_via_server_state_merge',
            )),
            $this->field('organizer_type', 'string', required: false, allowedValues: [Institution::class, Speaker::class], meta: [
                'mutation_semantics' => 'replace_scalar_with_alias_normalization',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'clear_to_null_when_organizer_id_is_null',
                ],
                'accepted_aliases' => [
                    'institution' => Institution::class,
                    'speaker' => Speaker::class,
                ],
                'paired_with' => 'organizer_id',
            ]),
            $this->field('organizer_id', 'string', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'clear_to_null_when_organizer_type_is_null',
                ],
                'paired_with' => 'organizer_type',
            ]),
            $this->field('series', 'array<string>', required: false, meta: $this->relationCollectionMeta(
                'series',
                submittedArray: 'replace_relation_sync',
                itemIdsPreserved: null,
                ordering: null,
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_series_ids',
                omitted: 'preserve_existing_collection_via_server_state_merge',
            )),
            $this->field('institution_id', 'string', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'clear_to_null',
                ],
                'exclusive_with' => ['venue_id'],
            ]),
            $this->field('venue_id', 'string', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'clear_to_null',
                ],
                'exclusive_with' => ['institution_id', 'space_id'],
            ]),
            $this->field('space_id', 'string', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'clear_to_null',
                ],
                'requires' => ['institution_id'],
                'prohibited_with' => ['venue_id'],
            ]),
            $this->field('speakers', 'array<string>', required: false, meta: [
                'relation' => 'key_people',
                'subset_scope' => 'speakers',
                'collection_semantics' => $this->replaceCollectionSemantics(
                    submittedArray: 'replace_speaker_subset_and_rebuild_key_people',
                    itemIdsPreserved: false,
                    ordering: 'payload_order_sets_order_column_before_other_key_people',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_speaker_list',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
            ]),
            $this->field('other_key_people', 'array<object>', required: false, meta: [
                'relation' => 'key_people',
                'subset_scope' => 'other_key_people',
                'collection_semantics' => $this->replaceCollectionSemantics(
                    submittedArray: 'replace_other_key_people_subset_and_rebuild_key_people',
                    itemIdsPreserved: false,
                    ordering: 'payload_order_sets_order_column_after_speakers',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_other_key_people_array',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                'item_schema' => $this->eventKeyPersonItemSchema(),
            ]),
            $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: [
                ...$this->singleMediaFieldMutationMeta('clear_cover'),
                'required_aspect_ratio' => '16:9',
                'media_role' => 'website_app_cover',
            ]),
            $this->field('poster', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: [
                ...$this->singleMediaFieldMutationMeta('clear_poster'),
                'required_aspect_ratio' => '4:5',
                'media_role' => 'external_distribution_poster',
            ]),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), maxFiles: 10),
            $this->field('clear_cover', 'boolean', required: false, default: false),
            $this->field('clear_poster', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
            $this->field('is_priority', 'boolean', required: false, default: false),
            $this->field('is_featured', 'boolean', required: false, default: false),
            $this->field('is_active', 'boolean', required: false, default: true),
            $this->field('escalated_at', 'datetime', required: false),
            $this->field('registration_required', 'boolean', required: false, default: false),
            $this->field('registration_mode', 'string', required: false, default: RegistrationMode::Event->value, allowedValues: $this->enumValues(RegistrationMode::class), meta: [
                'mutation_semantics' => 'replace_setting_with_runtime_lock',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                ],
                'lock_behavior' => [
                    'when_event_has_registrations' => 'retain_current_value',
                ],
            ]),
        ];

        if (! $updating) {
            $fields[] = $this->field('status', 'string', required: false, default: 'draft', allowedValues: ['draft', 'pending', 'approved']);
        }

        return $fields;
    }

    /**
     * @param  list<string|int>|null  $allowedValues
     * @param  list<string>|null  $acceptedMimeTypes
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function field(
        string $name,
        string $type,
        bool $required = false,
        mixed $default = null,
        ?int $maxLength = null,
        ?array $allowedValues = null,
        ?array $acceptedMimeTypes = null,
        ?int $maxFileSizeKb = null,
        ?int $maxFiles = null,
        array $meta = [],
    ): array {
        return array_merge(
            array_filter([
                'name' => $name,
                'type' => $type,
                'required' => $required,
                'default' => $default,
                'max_length' => $maxLength,
                'allowed_values' => $allowedValues,
                'accepted_mime_types' => $acceptedMimeTypes,
                'max_file_size_kb' => $maxFileSizeKb,
                'max_files' => $maxFiles,
            ], static fn (mixed $value): bool => $value !== null),
            array_filter($meta, static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function contactItemSchema(): array
    {
        return [
            'type' => 'object',
            'paired_required_fields' => [
                ['category', 'value'],
            ],
            'fields' => [
                $this->field('category', 'string', required: false, allowedValues: $this->enumValues(ContactCategory::class), meta: [
                    'required_with' => ['value'],
                ]),
                $this->field('value', 'string', required: false, maxLength: 255, meta: [
                    'required_with' => ['category'],
                    'used_for_categories' => [
                        ContactCategory::Phone->value,
                        ContactCategory::WhatsApp->value,
                        ContactCategory::Email->value,
                    ],
                ]),
                $this->field('type', 'string', required: false, default: ContactType::Main->value, allowedValues: $this->enumValues(ContactType::class)),
                $this->field('is_public', 'boolean', required: false, default: true),
                $this->field('order_column', 'integer', required: false, meta: [
                    'omitted' => 'assigned_from_payload_order_starting_at_1',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function socialMediaItemSchema(): array
    {
        return [
            'type' => 'object',
            'required_fields' => ['platform'],
            'at_least_one_of' => ['username', 'url'],
            'fields' => [
                $this->field('platform', 'string', required: false, allowedValues: $this->enumValues(SocialMediaPlatform::class), meta: [
                    'required_with' => ['username', 'url'],
                ]),
                $this->field('username', 'string', required: false, maxLength: 255, meta: [
                    'required_without' => ['url'],
                ]),
                $this->field('url', 'string', required: false, maxLength: 255, meta: [
                    'required_without' => ['username'],
                    'format' => 'url',
                ]),
                $this->field('order_column', 'integer', required: false, meta: [
                    'omitted' => 'assigned_from_payload_order_starting_at_1',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function qualificationCollectionMeta(): array
    {
        return [
            'collection_semantics' => $this->replaceCollectionSemantics(
                itemIdsPreserved: null,
                ordering: null,
            ),
            'item_schema' => [
                'type' => 'object',
                'paired_required_fields' => [
                    ['institution', 'degree'],
                ],
                'empty_entries_discarded' => true,
                'fields' => [
                    $this->field('institution', 'string', required: false, maxLength: 255, meta: [
                        'required_with' => ['degree'],
                    ]),
                    $this->field('degree', 'string', required: false, maxLength: 255, meta: [
                        'required_with' => ['institution'],
                    ]),
                    $this->field('field', 'string', required: false, maxLength: 255),
                    $this->field('year', 'string', required: false, maxLength: 4, meta: [
                        'format' => 'yyyy',
                    ]),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function facilitiesCollectionMeta(): array
    {
        return [
            'collection_semantics' => $this->replaceCollectionSemantics(
                itemIdsPreserved: null,
                ordering: 'not_applicable_set_semantics',
            ),
            'input_normalization' => [
                'kind' => 'facility_list_to_boolean_map',
                'storage_shape' => 'object<boolean>',
                'list_entries_become_true' => true,
                'explicit_false_values_preserved_when_keyed' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function simpleArrayCollectionMeta(): array
    {
        return [
            'collection_semantics' => $this->replaceCollectionSemantics(
                itemIdsPreserved: null,
                ordering: null,
                safeClientStrategy: 'send_full_array_when_editing',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function relationCollectionMeta(
        string $relation,
        string $submittedArray = 'replace_relation_sync',
        ?bool $itemIdsPreserved = null,
        ?string $ordering = null,
        string $safeClientStrategy = 'omit_field_to_preserve_or_send_full_relation_ids',
        string $omitted = 'preserve_existing_collection',
    ): array {
        return [
            'relation' => $relation,
            'collection_semantics' => $this->replaceCollectionSemantics(
                submittedArray: $submittedArray,
                itemIdsPreserved: $itemIdsPreserved,
                ordering: $ordering,
                safeClientStrategy: $safeClientStrategy,
                omitted: $omitted,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nullableRelationScalarMeta(string $relation): array
    {
        return [
            'relation' => $relation,
            'mutation_semantics' => 'replace_scalar',
            'clear_semantics' => [
                'omitted' => 'preserve_existing',
                'explicit_null' => 'clear_to_null',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function singleMediaFieldMutationMeta(string $rawHttpClearFlag): array
    {
        return [
            'mutation_semantics' => 'replace_single_media_collection',
            'clear_semantics' => [
                'omitted' => 'preserve_existing_collection',
                'explicit_null' => 'preserve_existing_collection',
                'submitted_file' => 'replace_collection',
            ],
            'raw_http_clear_flag' => $rawHttpClearFlag,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function multipleMediaFieldMutationMeta(
        string $rawHttpClearFlag,
        string $omitted = 'preserve_existing_collection',
        string $explicitNull = 'preserve_existing_collection',
        string $emptyArray = 'clear_collection',
        string $submittedArray = 'replace_collection',
    ): array {
        return [
            'collection_semantics' => [
                'omitted' => $omitted,
                'explicit_null' => $explicitNull,
                'empty_array' => $emptyArray,
                'submitted_array' => $submittedArray,
                'item_ids_preserved' => false,
                'ordering' => 'payload_order_sets_media_order',
                'safe_client_strategy' => 'send_full_media_array_when_replacing',
            ],
            'raw_http_clear_flag' => $rawHttpClearFlag,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventKeyPersonItemSchema(): array
    {
        return [
            'type' => 'object',
            'required_fields' => ['role'],
            'at_least_one_of' => ['speaker_id', 'name'],
            'empty_or_invalid_entries_discarded' => true,
            'fields' => [
                $this->field('role', 'string', required: false, allowedValues: $this->enumValues(EventKeyPersonRole::class), meta: [
                    'required_with' => ['speaker_id', 'name'],
                    'disallowed_values' => [EventKeyPersonRole::Speaker->value],
                ]),
                $this->field('speaker_id', 'string', required: false, meta: [
                    'required_without' => ['name'],
                ]),
                $this->field('name', 'string', required: false, maxLength: 255, meta: [
                    'required_without' => ['speaker_id'],
                ]),
                $this->field('is_public', 'boolean', required: false, default: true),
                $this->field('notes', 'string', required: false, maxLength: 500),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trimmedStringMutationMeta(
        string $omitted = 'preserve_existing',
        string $explicitNull = 'clear_to_null',
        ?string $emptyStringAtMutationLayer = 'null',
    ): array {
        return [
            'mutation_semantics' => 'replace_scalar',
            'clear_semantics' => [
                'omitted' => $omitted,
                'explicit_null' => $explicitNull,
            ],
            'normalization' => array_filter([
                'trim' => true,
                'empty_string_at_mutation_layer' => $emptyStringAtMutationLayer,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function replaceCollectionSemantics(
        string $submittedArray = 'replace_collection',
        string $explicitNull = 'clear_collection',
        string $emptyArray = 'clear_collection',
        ?bool $itemIdsPreserved = false,
        ?string $ordering = 'payload_order_sets_order_column_when_missing',
        string $safeClientStrategy = 'fetch_modify_resend_full_collection',
        string $omitted = 'preserve_existing_collection',
    ): array {
        return array_filter([
            'omitted' => $omitted,
            'explicit_null' => $explicitNull,
            'empty_array' => $emptyArray,
            'submitted_array' => $submittedArray,
            'item_ids_preserved' => $itemIdsPreserved,
            'ordering' => $ordering,
            'safe_client_strategy' => $safeClientStrategy,
        ], static fn (mixed $value): bool => $value !== null);
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
     * @return array<string, mixed>
     */
    private function institutionRules(bool $updating): array
    {
        $addressRule = $updating ? ['sometimes', 'array'] : ['required', 'array'];
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'name' => ['required', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(InstitutionType::class)],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['unverified', 'pending', 'verified', 'rejected'])],
            'is_active' => ['sometimes', 'boolean'],
            'allow_public_event_submission' => $updating ? ['sometimes', 'boolean'] : ['prohibited'],
            'address' => $addressRule,
            'address.country_id' => $updating ? ['nullable', 'integer', 'exists:countries,id'] : ['required', 'integer', 'exists:countries,id'],
            'address.state_id' => ['nullable', 'integer', 'exists:states,id'],
            'address.district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'address.subdistrict_id' => ['nullable', 'integer', 'exists:subdistricts,id'],
            'address.line1' => ['nullable', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.postcode' => ['nullable', 'string', 'max:16'],
            'address.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'address.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'address.google_maps_url' => ['nullable', 'url', 'max:2048'],
            'address.google_place_id' => ['nullable', 'string', 'max:255'],
            'address.waze_url' => ['nullable', 'url', 'max:255'],
            'contacts' => ['nullable', 'array'],
            'contacts.*.category' => ['required_with:contacts.*.value', Rule::enum(ContactCategory::class)],
            'contacts.*.value' => ['required_with:contacts.*.category', 'string', 'max:255'],
            'contacts.*.type' => ['nullable', Rule::enum(ContactType::class)],
            'contacts.*.is_public' => ['sometimes', 'boolean'],
            'social_media' => ['nullable', 'array'],
            'social_media.*.platform' => ['required_with:social_media.*.username,social_media.*.url', Rule::enum(SocialMediaPlatform::class)],
            'social_media.*.username' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
            'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.username'],
            'logo' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/svg+xml', $maxUploadSize],
            'cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_logo' => ['sometimes', 'boolean'],
            'clear_cover' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function donationChannelRules(bool $updating): array
    {
        $required = $updating ? 'required' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'donatable_type' => [$required, 'string', Rule::in($this->donationChannelAcceptedOwnerTypes())],
            'donatable_id' => [$required, 'uuid'],
            'label' => ['nullable', 'string', 'max:255'],
            'recipient' => [$required, 'string', 'max:255'],
            'method' => [$required, Rule::in(['bank_account', 'duitnow', 'ewallet'])],
            'bank_code' => ['nullable', 'string', 'max:32'],
            'bank_name' => ['nullable', 'string', 'max:255', 'required_if:method,bank_account'],
            'account_number' => ['nullable', 'string', 'max:64', 'required_if:method,bank_account'],
            'duitnow_type' => ['nullable', 'string', 'max:64', 'required_if:method,duitnow'],
            'duitnow_value' => ['nullable', 'string', 'max:255', 'required_if:method,duitnow'],
            'ewallet_provider' => ['nullable', 'string', 'max:64', 'required_if:method,ewallet'],
            'ewallet_handle' => ['nullable', 'string', 'max:255'],
            'ewallet_qr_payload' => ['nullable', 'string'],
            'reference_note' => ['nullable', 'string'],
            'status' => [$required, Rule::in(['unverified', 'verified', 'rejected', 'inactive'])],
            'is_default' => ['sometimes', 'boolean'],
            'qr' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_qr' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportRules(bool $updating): array
    {
        $required = $updating ? 'required' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'entity_type' => [$required, 'string', Rule::in($this->resolveReportEntityMetadataAction->validKeys())],
            'entity_id' => [$required, 'uuid'],
            'category' => [$required, 'string', Rule::in($this->resolveReportCategoryOptionsAction->validKeys())],
            'description' => ['nullable', 'string', 'max:2000', 'required_if:category,other'],
            'status' => [$required, Rule::in(['open', 'triaged', 'resolved', 'dismissed'])],
            'reporter_id' => ['nullable', 'uuid', 'exists:users,id'],
            'handled_by' => ['nullable', 'uuid', 'exists:users,id'],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:8'],
            'evidence.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', $maxUploadSize],
            'clear_evidence' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRules(bool $updating): array
    {
        $required = $updating ? 'sometimes' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        $rules = [
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable'],
            'event_date' => [$required, 'date'],
            'prayer_time' => [$required, Rule::enum(EventPrayerTime::class)],
            'custom_time' => ['nullable', 'string', 'max:32', 'required_if:prayer_time,'.EventPrayerTime::LainWaktu->value],
            'end_time' => ['nullable', 'string', 'max:32'],
            'timezone' => [$required, 'string', 'max:64'],
            'event_format' => [$required, Rule::enum(EventFormat::class)],
            'visibility' => [$required, Rule::enum(EventVisibility::class)],
            'event_url' => ['nullable', 'url', 'max:255'],
            'live_url' => ['nullable', 'url', 'max:255'],
            'recording_url' => ['nullable', 'url', 'max:255'],
            'gender' => [$required, Rule::enum(EventGenderRestriction::class)],
            'age_group' => [$required, 'array', 'min:1'],
            'age_group.*' => [Rule::enum(EventAgeGroup::class)],
            'children_allowed' => ['sometimes', 'boolean'],
            'is_muslim_only' => ['sometimes', 'boolean'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['integer', 'exists:languages,id'],
            'event_type' => [$required, 'array', 'min:1'],
            'event_type.*' => [Rule::enum(EventType::class)],
            'domain_tags' => ['nullable', 'array'],
            'domain_tags.*' => ['uuid', 'exists:tags,id'],
            'discipline_tags' => ['nullable', 'array'],
            'discipline_tags.*' => ['uuid', 'exists:tags,id'],
            'source_tags' => ['nullable', 'array'],
            'source_tags.*' => ['uuid', 'exists:tags,id'],
            'issue_tags' => ['nullable', 'array'],
            'issue_tags.*' => ['uuid', 'exists:tags,id'],
            'references' => ['nullable', 'array'],
            'references.*' => ['uuid', 'exists:references,id'],
            'organizer_type' => ['nullable', 'string', Rule::in([Institution::class, Speaker::class, 'institution', 'speaker'])],
            'organizer_id' => ['nullable', 'uuid'],
            'series' => ['nullable', 'array'],
            'series.*' => ['uuid', 'exists:series,id'],
            'institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],
            'venue_id' => ['nullable', 'uuid', 'exists:venues,id'],
            'space_id' => ['nullable', 'uuid', 'exists:spaces,id'],
            'speakers' => ['nullable', 'array'],
            'speakers.*' => ['uuid', 'exists:speakers,id'],
            'other_key_people' => ['nullable', 'array'],
            'other_key_people.*.role' => ['required_with:other_key_people.*.name,other_key_people.*.speaker_id', Rule::enum(EventKeyPersonRole::class)],
            'other_key_people.*.speaker_id' => ['nullable', 'uuid', 'exists:speakers,id', 'required_without:other_key_people.*.name'],
            'other_key_people.*.name' => ['nullable', 'string', 'max:255', 'required_without:other_key_people.*.speaker_id'],
            'other_key_people.*.is_public' => ['sometimes', 'boolean'],
            'other_key_people.*.notes' => ['nullable', 'string', 'max:500'],
            'cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'dimensions:ratio=16/9', $maxUploadSize],
            'poster' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'dimensions:ratio=4/5', $maxUploadSize],
            'gallery' => ['nullable', 'array', 'max:10'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_cover' => ['sometimes', 'boolean'],
            'clear_poster' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
            'is_priority' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'escalated_at' => ['nullable', 'date'],
            'registration_required' => ['sometimes', 'boolean'],
            'registration_mode' => ['sometimes', Rule::enum(RegistrationMode::class)],
        ];

        if (! $updating) {
            $rules['status'] = ['sometimes', Rule::in(['draft', 'pending', 'approved'])];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceRules(bool $updating): array
    {
        $required = $updating ? 'required' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'title' => [$required, 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'type' => [$required, Rule::enum(ReferenceType::class)],
            'parent_reference_id' => ['nullable', 'uuid', Rule::exists('references', 'id')->whereNull('parent_reference_id')->where('type', ReferenceType::Book->value)],
            'part_type' => ['nullable', Rule::enum(ReferencePartType::class)],
            'part_number' => ['nullable', 'string', 'max:255'],
            'part_label' => ['nullable', 'string', 'max:255'],
            'publication_year' => ['nullable', 'string', 'max:255'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_canonical' => ['sometimes', 'boolean'],
            'status' => [$required, Rule::in(['pending', 'verified'])],
            'is_active' => ['sometimes', 'boolean'],
            'social_media' => ['nullable', 'array'],
            'social_media.*.platform' => ['required_with:social_media.*.username,social_media.*.url', Rule::enum(SocialMediaPlatform::class)],
            'social_media.*.username' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
            'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.username'],
            'front_cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'back_cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'gallery' => ['nullable', 'array', 'max:10'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_front_cover' => ['sometimes', 'boolean'],
            'clear_back_cover' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesRules(bool $updating): array
    {
        $required = $updating ? 'required' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'title' => [$required, 'string', 'max:255'],
            'slug' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'visibility' => [$required, Rule::in(['public', 'unlisted', 'private'])],
            'is_active' => ['sometimes', 'boolean'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['integer', 'exists:languages,id'],
            'cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_cover' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspirationRules(bool $updating): array
    {
        $required = $updating ? 'required' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'category' => [$required, Rule::enum(InspirationCategory::class)],
            'locale' => [$required, 'string', Rule::in($this->supportedLocaleValues())],
            'title' => [$required, 'string', 'max:255'],
            'content' => [$required],
            'source' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'main' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_main' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function speakerRules(bool $updating): array
    {
        $addressRule = $updating ? ['sometimes', 'array'] : ['present', 'array'];
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'is_freelance' => ['sometimes', 'boolean'],
            'job_title' => ['nullable', 'string', 'max:255', 'required_if:is_freelance,true'],
            'honorific' => ['nullable', 'array'],
            'honorific.*' => [Rule::enum(Honorific::class)],
            'pre_nominal' => ['nullable', 'array'],
            'pre_nominal.*' => [Rule::enum(PreNominal::class)],
            'post_nominal' => ['nullable', 'array'],
            'post_nominal.*' => [Rule::enum(PostNominal::class)],
            'bio' => ['nullable', 'array'],
            'qualifications' => ['nullable', 'array'],
            'qualifications.*.institution' => ['required_with:qualifications.*.degree', 'string', 'max:255'],
            'qualifications.*.degree' => ['required_with:qualifications.*.institution', 'string', 'max:255'],
            'qualifications.*.field' => ['nullable', 'string', 'max:255'],
            'qualifications.*.year' => ['nullable', 'digits:4'],
            'language_ids' => ['nullable', 'array'],
            'language_ids.*' => ['integer', 'exists:languages,id'],
            'status' => ['required', Rule::in(['pending', 'verified', 'rejected'])],
            'is_active' => ['sometimes', 'boolean'],
            'allow_public_event_submission' => $updating ? ['sometimes', 'boolean'] : ['prohibited'],
            'address' => $addressRule,
            'address.country_id' => $updating ? ['nullable', 'integer', 'exists:countries,id'] : ['required', 'integer', 'exists:countries,id'],
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
            'contacts' => ['nullable', 'array'],
            'contacts.*.category' => ['required_with:contacts.*.value', Rule::enum(ContactCategory::class)],
            'contacts.*.value' => ['required_with:contacts.*.category', 'string', 'max:255'],
            'contacts.*.type' => ['nullable', Rule::enum(ContactType::class)],
            'contacts.*.is_public' => ['sometimes', 'boolean'],
            'social_media' => ['nullable', 'array'],
            'social_media.*.platform' => ['required_with:social_media.*.username,social_media.*.url', Rule::enum(SocialMediaPlatform::class)],
            'social_media.*.username' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
            'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.username'],
            'avatar' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_avatar' => ['sometimes', 'boolean'],
            'clear_cover' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function spaceRules(bool $updating): array
    {
        $required = $updating ? 'required' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'slug' => [$required, 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'institutions' => ['nullable', 'array'],
            'institutions.*' => ['uuid', 'exists:institutions,id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subdistrictRules(bool $updating): array
    {
        $required = $updating ? 'required' : 'required';

        return [
            'country_id' => [$required, 'integer', 'exists:countries,id'],
            'state_id' => [$required, 'integer', 'exists:states,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'name' => [$required, 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tagRules(bool $updating): array
    {
        $required = $updating ? 'required' : 'required';

        return [
            'name' => [$required, 'array'],
            'name.ms' => [$required, 'string', 'max:255'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'type' => [$required, Rule::enum(TagType::class)],
            'status' => [$required, Rule::in(['pending', 'verified'])],
            'order_column' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function venueRules(bool $updating): array
    {
        $addressRule = $updating ? ['sometimes', 'array'] : ['required', 'array'];
        $required = $updating ? 'sometimes' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::enum(VenueType::class)],
            'status' => [$required, Rule::in(['unverified', 'pending', 'verified', 'rejected'])],
            'is_active' => ['sometimes', 'boolean'],
            'facilities' => ['nullable', 'array'],
            'facilities.*' => ['string', Rule::in($this->venueFacilityValues())],
            'address' => $addressRule,
            'address.country_id' => [$updating ? 'sometimes' : 'required', 'integer', 'exists:countries,id'],
            'address.state_id' => ['nullable', 'integer', 'exists:states,id'],
            'address.district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'address.subdistrict_id' => ['nullable', 'integer', 'exists:subdistricts,id'],
            'address.line1' => ['nullable', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.postcode' => ['nullable', 'string', 'max:16'],
            'address.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'address.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'address.google_maps_url' => ['nullable', 'url', 'max:2048'],
            'address.google_place_id' => ['nullable', 'string', 'max:255'],
            'address.waze_url' => ['nullable', 'url', 'max:255'],
            'contacts' => ['nullable', 'array'],
            'contacts.*.category' => ['required_with:contacts.*.value', Rule::enum(ContactCategory::class)],
            'contacts.*.value' => ['required_with:contacts.*.category', 'string', 'max:255'],
            'contacts.*.type' => ['nullable', Rule::enum(ContactType::class)],
            'contacts.*.is_public' => ['sometimes', 'boolean'],
            'social_media' => ['nullable', 'array'],
            'social_media.*.platform' => ['required_with:social_media.*.username,social_media.*.url', Rule::enum(SocialMediaPlatform::class)],
            'social_media.*.username' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
            'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.username'],
            'cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_cover' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  Model&HasMedia  $record
     * @param  list<string>  $collections
     * @return array<string, mixed>
     */
    private function mediaState(Model $record, array $collections): array
    {
        $state = [];

        foreach ($collections as $collection) {
            $media = $record->getMedia($collection);

            $state[$collection] = $media
                ->map(fn ($item): array => [
                    'id' => (int) $item->getKey(),
                    'name' => $item->name,
                    'file_name' => $item->file_name,
                ])
                ->values()
                ->all();
        }

        return $state;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function addressCatalogs(string $prefix): array
    {
        return [
            $this->catalog($prefix.'.country_id', route('api.admin.catalogs.countries', [], false)),
            $this->catalog(
                $prefix.'.state_id',
                route('api.admin.catalogs.states', [], false),
                ['country_id' => '{'.$prefix.'.country_id}'],
            ),
            $this->catalog(
                $prefix.'.district_id',
                route('api.admin.catalogs.districts', [], false),
                ['state_id' => '{'.$prefix.'.state_id}'],
            ),
            $this->catalog(
                $prefix.'.subdistrict_id',
                route('api.admin.catalogs.subdistricts', [], false),
                [
                    'state_id' => '{'.$prefix.'.state_id}',
                    'district_id' => '{'.$prefix.'.district_id}',
                ],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subdistrictCatalogs(): array
    {
        return [
            $this->catalog('country_id', route('api.admin.catalogs.countries', [], false)),
            $this->catalog(
                'state_id',
                route('api.admin.catalogs.states', [], false),
                ['country_id' => '{country_id}'],
            ),
            $this->catalog(
                'district_id',
                route('api.admin.catalogs.districts', [], false),
                ['state_id' => '{state_id}'],
            ),
        ];
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function catalog(string $field, string $endpoint, array $query = []): array
    {
        return array_filter([
            'field' => $field,
            'endpoint' => $endpoint,
            'query' => $query,
        ], static fn (mixed $value): bool => $value !== []);
    }

    /**
     * @return list<int>
     */
    private function federalTerritoryStateIds(): array
    {
        return array_keys(FederalTerritoryLocation::stateIds());
    }

    /**
     * @return list<string>
     */
    private function venueFacilityValues(): array
    {
        return [
            'parking',
            'oku',
            'women_section',
            'ablution_area',
        ];
    }

    /**
     * @return list<string>
     */
    private function supportedLocaleValues(): array
    {
        $locales = config('app.supported_locales', []);

        if (! is_array($locales) || $locales === []) {
            return [app()->getLocale() ?: 'ms'];
        }

        if (array_is_list($locales)) {
            return array_values(array_filter($locales, static fn (mixed $locale): bool => is_string($locale) && $locale !== ''));
        }

        return array_values(array_filter(array_keys($locales), static fn (mixed $locale): bool => is_string($locale) && $locale !== ''));
    }

    private function defaultSupportedLocale(): string
    {
        $supportedLocales = $this->supportedLocaleValues();
        $appLocale = app()->getLocale();

        if (is_string($appLocale) && in_array($appLocale, $supportedLocales, true)) {
            return $appLocale;
        }

        return $supportedLocales[0] ?? 'ms';
    }
}
