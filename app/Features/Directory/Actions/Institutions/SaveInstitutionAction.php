<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Directory\Api\InstitutionSubmissionAccess;
use App\Features\Directory\Support\DirectoryModel;
use App\Platform\Addressing\AddressFormState;
use App\Platform\Media\MediaCollectionSync;
use App\Shared\Auth\AccountActor;
use App\Shared\Directory\Enums\InstitutionStatus;
use App\Shared\Directory\InstitutionFacilities;
use App\Shared\Signals\SignalRecorder;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;

final readonly class SaveInstitutionAction
{
    use AsAction;

    public function __construct(
        private AddMemberAction $addMemberAction,
        private GenerateInstitutionSlugAction $generateInstitutionSlugAction,
        private MediaCollectionSync $mediaSyncService,
        private SignalRecorder $signals,
        private SyncInstitutionNamesAction $syncInstitutionNames,
        private SyncInstitutionRelationsAction $syncInstitutionRelations,
        private InstitutionSubmissionAccess $submissionAccess,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, Model&AccountActor $actor, ?Model $institution = null, string $validationErrorKey = 'allow_public_event_submission'): Model
    {
        $creating = ! $institution instanceof Model;
        $institution ??= DirectoryModel::new('institution');
        $previousFacilities = $creating ? null : $this->facilitiesValue($institution->getAttribute('own_facilities'));

        $address = is_array($data['address'] ?? null) ? $data['address'] : [];
        $addressProvided = array_key_exists('address', $data) && is_array($data['address'] ?? null);

        if ($addressProvided) {
            $countryProvided = AddressFormState::countrySelectionProvided($address);
            $address = AddressFormState::prepareAddressPersistenceData($address);

            if (! $countryProvided) {
                throw ValidationException::withMessages([
                    'address.country_id' => __('The address country is required.'),
                ]);
            }

            if (! is_string($address['country_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'address.country_id' => __('The selected country is invalid.'),
                ]);
            }

            $data['address'] = $address;
        }

        $currentPublicSubmission = $creating ? true : (bool) $institution->getAttribute('allow_public_event_submission');
        $requestedPublicSubmission = $creating
            ? true
            : (array_key_exists('allow_public_event_submission', $data) ? (bool) $data['allow_public_event_submission'] : $currentPublicSubmission);

        $attributes = [
            'type' => $this->institutionTypeValue($data['type'] ?? null) ?: $this->institutionTypeValue($institution),
            'name' => $this->normalizeRequiredString($data['name'] ?? $institution->getAttribute('name'), 'Institution'),
            'description' => $data['description'] ?? $institution->getAttribute('description'),
            'status' => $this->normalizeStatus($data['status'] ?? null, $institution, $creating),
        ];

        if (array_key_exists('facilities', $data)) {
            $attributes['facilities'] = $this->normalizeFacilities($data['facilities']);
        }

        $explicitSlug = null;

        if ($creating) {
            $attributes['slug'] = $this->generateInstitutionSlugAction->handle($attributes['name'], $address);
            $attributes['allow_public_event_submission'] = true;

            $institution = DirectoryModel::query('institution')->create($attributes);
            $this->addMemberAction->handle($institution, $actor, MemberRole::Owner);
        } else {
            $explicitSlug = array_key_exists('slug', $data) ? $this->normalizeOptionalString($data['slug']) : null;

            if ($explicitSlug !== null && $explicitSlug !== (string) $institution->getAttribute('slug')) {
                $this->assertSlugAvailable($explicitSlug, $institution);
                $attributes['slug'] = $explicitSlug;
            }

            $institution->fill($attributes);
            $institution->save();
        }

        if (array_key_exists('names', $data) && is_array($data['names'])) {
            $this->syncInstitutionNames->handle($institution, $data['names']);
        }

        $this->syncInstitutionRelations->handle($institution, Arr::only($data, ['address', 'contactMethods', 'social_media', 'donation_channels']));
        $this->syncMedia($institution, $data);

        if (! $creating) {
            $this->syncPublicSubmissionToggle($institution, $actor, $currentPublicSubmission, $requestedPublicSubmission, $validationErrorKey);
            $this->preserveExplicitSlug($institution, $explicitSlug);
        }

        if (array_key_exists('facilities', $data)) {
            $this->recordFacilitiesUpdatedAfterCommit($institution, $previousFacilities, $actor);
        }

        return $institution->fresh([
            'addresses',
            'contactMethods',
            'socialProfiles',
            'media',
        ]) ?? $institution;
    }

    private function syncPublicSubmissionToggle(
        Model $institution,
        Model&AccountActor $actor,
        bool $currentPublicSubmission,
        bool $requestedPublicSubmission,
        string $validationErrorKey,
    ): void {
        if ($requestedPublicSubmission === $currentPublicSubmission) {
            return;
        }

        $institutionId = (string) $institution->getKey();
        $actorId = (string) $actor->getKey();

        if ($requestedPublicSubmission) {
            $this->submissionAccess->unlock($institutionId, $actorId);

            return;
        }

        $eligibility = $this->submissionAccess->eligibility($institutionId);

        if (! $eligibility->eligible) {
            throw ValidationException::withMessages([
                $validationErrorKey => $eligibility->reasons,
            ]);
        }

        $this->submissionAccess->lock($institutionId, $actorId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMedia(Model $institution, array $data): void
    {
        if (! $institution instanceof HasMedia) {
            throw new RuntimeException('Institutions must support media.');
        }

        if (($data['clear_logo'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($institution, 'logo');
        }

        if (($data['clear_cover'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($institution, 'cover');
        }

        if (($data['clear_gallery'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($institution, 'gallery');
        }

        $logo = $data['logo'] ?? null;
        $cover = $data['cover'] ?? null;
        $gallery = $data['gallery'] ?? null;

        $this->mediaSyncService->syncSingle(
            $institution,
            $logo instanceof UploadedFile ? $logo : null,
            'logo',
        );
        $this->mediaSyncService->syncSingle(
            $institution,
            $cover instanceof UploadedFile ? $cover : null,
            'cover',
        );
        $this->mediaSyncService->syncMultiple(
            $institution,
            is_array($gallery) ? $gallery : null,
            'gallery',
            replace: is_array($gallery),
        );
    }

    private function normalizeStatus(mixed $value, Model $institution, bool $creating): InstitutionStatus
    {
        if ($value instanceof InstitutionStatus) {
            return $value;
        }

        if ($value === null) {
            if ($creating) {
                return InstitutionStatus::Pending;
            }

            $current = $institution->getAttribute('status');

            return $current instanceof InstitutionStatus ? $current : InstitutionStatus::Pending;
        }

        $status = InstitutionStatus::tryFrom($this->normalizeOptionalString($value) ?? '');

        if (! $status instanceof InstitutionStatus) {
            throw ValidationException::withMessages([
                'status' => __('The selected institution status is invalid.'),
            ]);
        }

        return $status;
    }

    /**
     * @return array<string, bool>|null
     */
    private function normalizeFacilities(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $errors = InstitutionFacilities::validate($value);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $normalized = InstitutionFacilities::normalize($value);

        return $normalized === [] ? null : $normalized;
    }

    private function assertSlugAvailable(string $slug, Model $institution): void
    {
        $taken = DirectoryModel::query('institution')
            ->where('slug', $slug)
            ->whereKeyNot($institution->getKey())
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => __('The slug is already in use.'),
            ]);
        }
    }

    /**
     * @param  array<string, bool>|null  $previousFacilities
     */
    private function recordFacilitiesUpdatedAfterCommit(Model $institution, ?array $previousFacilities, Model&AccountActor $actor): void
    {
        $currentFacilities = $this->facilitiesValue($institution->getAttribute('own_facilities'));

        if (! $this->facilitiesChanged($previousFacilities, $currentFacilities)) {
            return;
        }

        $request = request();
        $institutionId = (string) $institution->getKey();
        $actorId = (string) $actor->getKey();

        $institution->getConnection()->afterCommit(function () use ($institutionId, $previousFacilities, $currentFacilities, $actorId, $request): void {
            $previous = $previousFacilities ?? [];
            $current = $currentFacilities ?? [];

            $changed = [];

            foreach (array_unique([...array_keys($previous), ...array_keys($current)]) as $code) {
                if (($previous[$code] ?? null) !== ($current[$code] ?? null)) {
                    $changed[] = $code;
                }
            }

            sort($changed);

            if ($changed === []) {
                return;
            }

            $enabledCount = count(array_filter($current));

            $this->signals->record(
                eventName: 'admin.institution_facilities.updated',
                eventCategory: 'admin',
                actorId: $actorId,
                properties: [
                    'institution_id' => $institutionId,
                    'changed_codes' => $changed,
                    'enabled_count' => $enabledCount,
                    'disabled_count' => count($current) - $enabledCount,
                    'cleared' => $currentFacilities === null,
                ],
                request: $request,
            );
        });
    }

    /**
     * @param  array<string, bool>|null  $previousFacilities
     * @param  array<string, bool>|null  $currentFacilities
     */
    private function facilitiesChanged(?array $previousFacilities, ?array $currentFacilities): bool
    {
        $previous = $previousFacilities ?? [];
        $current = $currentFacilities ?? [];

        return array_any(array_unique([...array_keys($previous), ...array_keys($current)]), fn ($code) => ($previous[$code] ?? null) !== ($current[$code] ?? null));
    }

    private function preserveExplicitSlug(Model $institution, ?string $explicitSlug): void
    {
        if ($explicitSlug === null) {
            return;
        }

        $institutionId = (string) $institution->getKey();

        $institution->getConnection()->afterCommit(function () use ($institutionId, $explicitSlug): void {
            if (DirectoryModel::query('institution')->whereKey($institutionId)->value('slug') === $explicitSlug) {
                return;
            }

            $fresh = DirectoryModel::query('institution')->whereKey($institutionId)->first();

            if (! $fresh instanceof Model) {
                return;
            }

            $this->assertSlugAvailable($explicitSlug, $fresh);

            $fresh->forceFill(['slug' => $explicitSlug])->save();
        });
    }

    /**
     * @return array<string, bool>|null
     */
    private function facilitiesValue(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_filter($value, is_bool(...));
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return is_string($value->value) ? $value->value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeRequiredString(mixed $value, string $fallback): string
    {
        $normalized = $this->normalizeOptionalString($value);

        return $normalized ?? $fallback;
    }

    private function institutionTypeValue(mixed $value): string
    {
        if ($value instanceof Model) {
            $value = $value->getAttribute('type');
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : '';
    }
}
