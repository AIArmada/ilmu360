<?php

namespace App\Actions\Institutions;

use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Enums\InstitutionNameType;
use App\Enums\InstitutionStatus;
use App\Forms\SharedFormSchema;
use App\Models\Institution;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use App\Services\Signals\ProductSignalsService;
use App\Support\Institutions\InstitutionFacilities;
use App\Support\Media\ModelMediaSyncService;
use App\Support\Submission\PublicSubmissionLockService;
use BackedEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SaveInstitutionAction
{
    use AsAction;

    public function __construct(
        private AddMemberAction $addMemberAction,
        private ContributionEntityMutationService $contributionEntityMutationService,
        private GenerateInstitutionSlugAction $generateInstitutionSlugAction,
        private ModelMediaSyncService $mediaSyncService,
        private ProductSignalsService $productSignalsService,
        private PublicSubmissionLockService $publicSubmissionLockService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?Institution $institution = null, string $validationErrorKey = 'allow_public_event_submission'): Institution
    {
        $creating = ! $institution instanceof Institution;
        $institution ??= new Institution;
        $previousFacilities = $creating ? null : $institution->own_facilities;

        $address = is_array($data['address'] ?? null) ? $data['address'] : [];
        $addressProvided = array_key_exists('address', $data) && is_array($data['address'] ?? null);

        if ($addressProvided) {
            $countryProvided = SharedFormSchema::countrySelectionProvided($address);
            $address = SharedFormSchema::prepareAddressPersistenceData($address);

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

        $currentPublicSubmission = $creating ? true : (bool) $institution->allow_public_event_submission;
        $requestedPublicSubmission = $creating
            ? true
            : (array_key_exists('allow_public_event_submission', $data) ? (bool) $data['allow_public_event_submission'] : $currentPublicSubmission);

        $attributes = [
            'type' => $this->institutionTypeValue($data['type'] ?? null) ?: $this->institutionTypeValue($institution),
            'name' => $this->normalizeRequiredString($data['name'] ?? $institution->name, 'Institution'),
            'description' => $data['description'] ?? $institution->description,
            'status' => $this->normalizeStatus($data['status'] ?? null, $institution, $creating),
        ];

        if (array_key_exists('facilities', $data)) {
            $attributes['facilities'] = $this->normalizeFacilities($data['facilities']);
        }

        $explicitSlug = null;

        if ($creating) {
            $attributes['slug'] = $this->generateInstitutionSlugAction->handle($attributes['name'], $address);
            $attributes['allow_public_event_submission'] = true;

            $institution = Institution::create($attributes);
            $this->addMemberAction->handle($institution, $actor, MemberRole::Owner);
        } else {
            $explicitSlug = array_key_exists('slug', $data) ? $this->normalizeOptionalString($data['slug']) : null;

            if ($explicitSlug !== null && $explicitSlug !== (string) $institution->slug) {
                $this->assertSlugAvailable($explicitSlug, $institution);
                $attributes['slug'] = $explicitSlug;
            }

            $institution->fill($attributes);
            $institution->save();
        }

        if (array_key_exists('names', $data) && is_array($data['names'])) {
            $this->syncNames($institution, $data['names']);
        }

        $this->contributionEntityMutationService->syncInstitutionRelations($institution, Arr::only($data, ['address', 'contactMethods', 'social_media']));
        $this->syncMedia($institution, $data);

        if (! $creating) {
            $this->syncPublicSubmissionToggle($institution, $actor, $currentPublicSubmission, $requestedPublicSubmission, $validationErrorKey);
            $this->preserveExplicitSlug($institution, $explicitSlug);
        }

        // The entire save succeeded at this point: any validation failure
        // above threw before registering. Ingestion evaluates alerts and
        // dispatches jobs immediately, so the signal must wait for the
        // save's transaction to commit: a rolled-back row alone cannot
        // retract dispatched work.
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
        Institution $institution,
        User $actor,
        bool $currentPublicSubmission,
        bool $requestedPublicSubmission,
        string $validationErrorKey,
    ): void {
        if ($requestedPublicSubmission === $currentPublicSubmission) {
            return;
        }

        if ($requestedPublicSubmission) {
            $this->publicSubmissionLockService->unlockInstitution($institution, $actor);

            return;
        }

        $eligibility = $this->publicSubmissionLockService->institutionEligibility($institution);

        if (! $eligibility->eligible) {
            throw ValidationException::withMessages([
                $validationErrorKey => $eligibility->reasons,
            ]);
        }

        $this->publicSubmissionLockService->lockInstitution($institution, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMedia(Institution $institution, array $data): void
    {
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

    /**
     * @param  list<array{full_name: string, name_type?: string, language_code?: string, is_primary?: bool}>  $names
     */
    private function syncNames(Institution $institution, array $names): void
    {
        $institution->names()->delete();

        $primarySelected = false;

        foreach ($names as $i => $name) {
            $isPrimary = (bool) ($name['is_primary'] ?? $i === 0) && ! $primarySelected;

            if ($isPrimary) {
                $primarySelected = true;
            }

            $institution->names()->create([
                'name_type' => $name['name_type'] ?? InstitutionNameType::Nickname,
                'full_name' => trim((string) ($name['full_name'] ?? '')),
                'language_code' => $name['language_code'] ?? 'ms',
                'is_primary' => $isPrimary,
            ]);
        }

        $institution->unsetRelation('names');
    }

    private function normalizeStatus(mixed $value, Institution $institution, bool $creating): InstitutionStatus
    {
        if ($value instanceof InstitutionStatus) {
            return $value;
        }

        if ($value === null) {
            // Persisted status is always an enum; creation starts pending.
            return $creating ? InstitutionStatus::Pending : $institution->status;
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

    private function assertSlugAvailable(string $slug, Institution $institution): void
    {
        $taken = Institution::query()
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
     * Register the curated facilities outcome once, after the save's
     * transaction commits. Map snapshots and request context are captured
     * eagerly in the closure; nothing is held in singleton or static state.
     * The central service re-checks the diff, so this gate only avoids
     * registering no-op callbacks.
     *
     * @param  array<string, bool>|null  $previousFacilities
     */
    private function recordFacilitiesUpdatedAfterCommit(Institution $institution, ?array $previousFacilities, User $actor): void
    {
        $currentFacilities = $institution->own_facilities;

        if (! self::facilitiesChanged($previousFacilities, $currentFacilities)) {
            return;
        }

        $request = request();

        $institution->getConnection()->afterCommit(function () use ($institution, $previousFacilities, $currentFacilities, $actor, $request): void {
            $this->productSignalsService->recordInstitutionFacilitiesUpdated(
                $institution,
                $previousFacilities,
                $currentFacilities,
                $actor,
                $request,
            );
        });
    }

    /**
     * @param  array<string, bool>|null  $previousFacilities
     * @param  array<string, bool>|null  $currentFacilities
     */
    private static function facilitiesChanged(?array $previousFacilities, ?array $currentFacilities): bool
    {
        $previous = $previousFacilities ?? [];
        $current = $currentFacilities ?? [];

        foreach (array_unique([...array_keys($previous), ...array_keys($current)]) as $code) {
            if (($previous[$code] ?? null) !== ($current[$code] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Re-assert an explicitly requested slug after the relation syncs: name
     * and address observers regenerate slugs for manual institutions, which
     * would otherwise silently discard the requested value. Registered
     * after-commit so deferred observer regenerations run first; without an
     * open transaction it runs immediately.
     */
    private function preserveExplicitSlug(Institution $institution, ?string $explicitSlug): void
    {
        if ($explicitSlug === null) {
            return;
        }

        $institutionId = (string) $institution->getKey();

        $institution->getConnection()->afterCommit(function () use ($institutionId, $explicitSlug): void {
            if (Institution::query()->whereKey($institutionId)->value('slug') === $explicitSlug) {
                return;
            }

            $fresh = Institution::query()->whereKey($institutionId)->first();

            if (! $fresh instanceof Institution) {
                return;
            }

            $this->assertSlugAvailable($explicitSlug, $fresh);

            $fresh->forceFill(['slug' => $explicitSlug])->save();
        });
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
        if ($value instanceof Institution) {
            $value = $value->type;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : '';
    }
}
