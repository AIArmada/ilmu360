<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Persons\Enums\Gender;
use App\Features\Directory\Api\PersonSubmissionAccess;
use App\Features\Directory\Support\DirectoryModel;
use App\Platform\Addressing\AddressFormState;
use App\Shared\Auth\AccountActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Save a person through the admin/API form path.
 */
final readonly class SavePersonAction
{
    use AsAction;

    public function __construct(
        private AddMemberAction $addMemberAction,
        private GeneratePersonSlugAction $generatePersonSlugAction,
        private SyncPersonMediaAction $syncPersonMedia,
        private SyncPersonRelationsAction $syncPersonRelations,
        private PersonSubmissionAccess $submissionAccess,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(
        array $data,
        Model&AccountActor $actor,
        ?Model $person = null,
        string $validationErrorKey = 'allow_public_event_submission',
        bool $assignOwner = true,
    ): Model {
        $creating = ! $person instanceof Model;
        $person ??= DirectoryModel::new('person');

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

        $currentPublicSubmission = $creating ? true : (bool) $person->getAttribute('allow_public_event_submission');
        $requestedPublicSubmission = $creating
            ? true
            : (array_key_exists('allow_public_event_submission', $data) ? (bool) $data['allow_public_event_submission'] : $currentPublicSubmission);
        $attributes = [
            'name' => $this->normalizeRequiredString($data['name'] ?? $person->getAttribute('name'), 'Person'),
            'middle_name' => $this->normalizeOptionalString($data['middle_name'] ?? $person->getAttribute('middle_name')),
            'family_name' => $this->normalizeOptionalString($data['family_name'] ?? $person->getAttribute('family_name')),
            'gender' => $this->normalizeGender($data['gender'] ?? $person->getAttribute('gender') ?? null),
            'bio' => array_key_exists('bio', $data) ? $data['bio'] : $person->getAttribute('bio'),
            'status' => array_key_exists('status', $data)
                ? (string) $data['status']
                : ($creating ? 'pending' : (string) $person->getAttribute('status')),
        ];

        if (array_key_exists('is_featured', $data)) {
            $attributes['is_featured'] = (bool) $data['is_featured'];
        }

        if (array_key_exists('speaker_status', $data)) {
            $attributes['speaker_status'] = $data['speaker_status'];
        }

        if ($creating) {
            $attributes['slug'] = $this->generatePersonSlugAction->handle($attributes['name'], array_merge($data, [
                'address' => $address,
            ]));
            $attributes['allow_public_event_submission'] = true;

            $person = DirectoryModel::query('person')->create($attributes);

            if ($assignOwner) {
                $this->addMemberAction->handle($person, $actor, MemberRole::Owner);
            }
        } else {
            $person->fill($attributes);
            $person->save();
        }

        $this->syncPersonRelations->handle($person, Arr::only($data, [
            'address',
            'contactMethods',
            'social_media',
            'language_ids',
            'names',
            'institutions',
        ]));
        $this->syncPersonMedia->handle($person, $data);

        if (! $creating) {
            $this->syncPublicSubmissionToggle($person, $actor, $currentPublicSubmission, $requestedPublicSubmission, $validationErrorKey);
        }

        return $person->fresh([
            'addresses',
            'contactMethods',
            'socialProfiles',
            'languages',
            'names',
            'institutions',
            'media',
        ]) ?? $person;
    }

    private function syncPublicSubmissionToggle(
        Model $person,
        Model&AccountActor $actor,
        bool $currentPublicSubmission,
        bool $requestedPublicSubmission,
        string $validationErrorKey,
    ): void {
        if ($requestedPublicSubmission === $currentPublicSubmission) {
            return;
        }

        $personId = (string) $person->getKey();
        $actorId = (string) $actor->getKey();

        if ($requestedPublicSubmission) {
            $this->submissionAccess->unlock($personId, $actorId);

            return;
        }

        $eligibility = $this->submissionAccess->eligibility($personId);

        if (! $eligibility->eligible) {
            throw ValidationException::withMessages([
                $validationErrorKey => $eligibility->reasons,
            ]);
        }

        $this->submissionAccess->lock($personId, $actorId);
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
