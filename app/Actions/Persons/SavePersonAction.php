<?php

namespace App\Actions\Persons;

use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Persons\Enums\Gender;
use App\Forms\SharedFormSchema;
use App\Models\Person;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use App\Support\Media\ModelMediaSyncService;
use App\Support\Submission\PublicSubmissionLockService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SavePersonAction
{
    use AsAction;

    public function __construct(
        private AddMemberAction $addMemberAction,
        private ContributionEntityMutationService $contributionEntityMutationService,
        private GeneratePersonSlugAction $generatePersonSlugAction,
        private ModelMediaSyncService $mediaSyncService,
        private PublicSubmissionLockService $publicSubmissionLockService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?Person $person = null, string $validationErrorKey = 'allow_public_event_submission'): Person
    {
        $creating = ! $person instanceof Person;
        $person ??= new Person;

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

        $currentPublicSubmission = $creating ? true : (bool) $person->allow_public_event_submission;
        $requestedPublicSubmission = $creating
            ? true
            : (array_key_exists('allow_public_event_submission', $data) ? (bool) $data['allow_public_event_submission'] : $currentPublicSubmission);
        $attributes = [
            'name' => $this->normalizeRequiredString($data['name'] ?? $person->name, 'Person'),
            'gender' => $this->normalizeGender($data['gender'] ?? $person->gender ?? null),
            'bio' => array_key_exists('bio', $data) ? $data['bio'] : $person->bio,
            'status' => array_key_exists('status', $data) ? (string) $data['status'] : ($creating ? 'pending' : (string) $person->status),
        ];

        if ($creating) {
            $attributes['slug'] = $this->generatePersonSlugAction->handle($attributes['name'], array_merge($data, [
                'address' => $address,
            ]));
            $attributes['allow_public_event_submission'] = true;

            $person = Person::create($attributes);
            $this->addMemberAction->handle($person, $actor, MemberRole::Owner);
        } else {
            $person->fill($attributes);
            $person->save();
        }

        $this->contributionEntityMutationService->syncPersonRelations($person, Arr::only($data, [
            'address',
            'contactMethods',
            'social_media',
            'language_ids',
        ]));
        $this->syncMedia($person, $data);

        if (! $creating) {
            $this->syncPublicSubmissionToggle($person, $actor, $currentPublicSubmission, $requestedPublicSubmission, $validationErrorKey);
        }

        return $person->fresh([
            'addresses',
            'contactMethods',
            'socialProfiles',
            'languages',
            'media',
        ]) ?? $person;
    }

    private function syncPublicSubmissionToggle(
        Person $person,
        User $actor,
        bool $currentPublicSubmission,
        bool $requestedPublicSubmission,
        string $validationErrorKey,
    ): void {
        if ($requestedPublicSubmission === $currentPublicSubmission) {
            return;
        }

        if ($requestedPublicSubmission) {
            $this->publicSubmissionLockService->unlockPerson($person, $actor);

            return;
        }

        $eligibility = $this->publicSubmissionLockService->personEligibility($person);

        if (! $eligibility->eligible) {
            throw ValidationException::withMessages([
                $validationErrorKey => $eligibility->reasons,
            ]);
        }

        $this->publicSubmissionLockService->lockPerson($person, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMedia(Person $person, array $data): void
    {
        if (($data['clear_avatar'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($person, 'avatar');
        }

        if (($data['clear_cover'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($person, 'cover');
        }

        if (($data['clear_main'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($person, 'main');
        }

        if (($data['clear_gallery'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($person, 'gallery');
        }

        $avatar = $data['avatar'] ?? null;
        $main = $data['main'] ?? null;
        $cover = $data['cover'] ?? null;
        $gallery = $data['gallery'] ?? null;

        $this->mediaSyncService->syncSingle(
            $person,
            $avatar instanceof UploadedFile ? $avatar : null,
            'avatar',
        );
        $this->mediaSyncService->syncSingle(
            $person,
            $main instanceof UploadedFile ? $main : null,
            'main',
        );
        $this->mediaSyncService->syncSingle(
            $person,
            $cover instanceof UploadedFile ? $cover : null,
            'cover',
        );
        $this->mediaSyncService->syncMultiple(
            $person,
            is_array($gallery) ? $gallery : null,
            'gallery',
            replace: is_array($gallery),
        );
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
}
