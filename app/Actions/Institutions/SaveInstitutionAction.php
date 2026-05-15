<?php

namespace App\Actions\Institutions;

use App\Actions\Membership\AddMemberToSubject;
use App\Forms\SharedFormSchema;
use App\Models\Institution;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
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
        private AddMemberToSubject $addMemberToSubject,
        private ContributionEntityMutationService $contributionEntityMutationService,
        private GenerateInstitutionSlugAction $generateInstitutionSlugAction,
        private ModelMediaSyncService $mediaSyncService,
        private PublicSubmissionLockService $publicSubmissionLockService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?Institution $institution = null, string $validationErrorKey = 'allow_public_event_submission'): Institution
    {
        $creating = ! $institution instanceof Institution;
        $institution ??= new Institution;

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

            if (! is_int($address['country_id'] ?? null)) {
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
            'nickname' => $this->normalizeOptionalString($data['nickname'] ?? $institution->nickname),
            'description' => $data['description'] ?? $institution->description,
            'status' => (string) ($data['status'] ?? $institution->status ?? ''),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($creating ? true : (bool) $institution->is_active),
        ];

        if ($creating) {
            $attributes['slug'] = $this->generateInstitutionSlugAction->handle($attributes['name'], $address);
            $attributes['allow_public_event_submission'] = true;

            $institution = Institution::create($attributes);
            $this->addMemberToSubject->handle($institution, $actor);
        } else {
            $institution->fill($attributes);
            $institution->save();
        }

        $this->contributionEntityMutationService->syncInstitutionRelations($institution, Arr::only($data, ['address', 'contacts', 'social_media']));
        $this->syncMedia($institution, $data);

        if (! $creating) {
            $this->syncPublicSubmissionToggle($institution, $actor, $currentPublicSubmission, $requestedPublicSubmission, $validationErrorKey);
        }

        return $institution->fresh([
            'address',
            'contacts',
            'socialMedia',
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

    private function normalizeOptionalString(mixed $value): ?string
    {
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
