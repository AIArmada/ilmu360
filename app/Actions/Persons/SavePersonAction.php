<?php

namespace App\Actions\Persons;

use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Enums\Gender;
use App\Forms\SharedFormSchema;
use App\Models\Person;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use App\Support\Media\ModelMediaSyncService;
use App\Support\Submission\PublicSubmissionLockService;
use BackedEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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
    public function handle(array $data, User $actor, ?Person $speaker = null, string $validationErrorKey = 'allow_public_event_submission'): Person
    {
        $creating = ! $speaker instanceof Person;
        $speaker ??= new Person;

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

        $currentPublicSubmission = $creating ? true : (bool) $speaker->allow_public_event_submission;
        $requestedPublicSubmission = $creating
            ? true
            : (array_key_exists('allow_public_event_submission', $data) ? (bool) $data['allow_public_event_submission'] : $currentPublicSubmission);
        $isFreelance = array_key_exists('is_freelance', $data) ? (bool) $data['is_freelance'] : ($creating ? false : (bool) $speaker->is_freelance);

        $attributes = [
            'name' => $this->normalizeRequiredString($data['name'] ?? $speaker->name, 'Speaker'),
            'gender' => $this->normalizeGender($data['gender'] ?? $speaker->gender ?? null),
            'honorific' => array_key_exists('honorific', $data) ? $this->normalizeStringArray($data['honorific'] ?? []) : $speaker->honorific,
            'pre_nominal' => array_key_exists('pre_nominal', $data) ? $this->normalizeStringArray($data['pre_nominal'] ?? []) : $speaker->pre_nominal,
            'post_nominal' => array_key_exists('post_nominal', $data) ? $this->normalizeStringArray($data['post_nominal'] ?? []) : $speaker->post_nominal,
            'bio' => array_key_exists('bio', $data) ? $data['bio'] : $speaker->bio,
            'qualifications' => array_key_exists('qualifications', $data)
                ? $this->normalizeQualificationEntries($data['qualifications'] ?? [])
                : $speaker->qualifications,
            'is_freelance' => $isFreelance,
            'job_title' => $isFreelance
                ? $this->normalizeOptionalString($data['job_title'] ?? $speaker->job_title)
                : null,
            'status' => array_key_exists('status', $data) ? (string) $data['status'] : ($creating ? 'pending' : (string) $speaker->status),
        ];

        if ($creating) {
            $attributes['slug'] = $this->generatePersonSlugAction->handle($attributes['name'], array_merge($data, [
                'address' => $address,
            ]));
            $attributes['allow_public_event_submission'] = true;

            $speaker = Person::create($attributes);
            $this->addMemberAction->handle($speaker, $actor, MemberRole::Owner);
        } else {
            $speaker->fill($attributes);
            $speaker->save();
        }

        $this->contributionEntityMutationService->syncSpeakerRelations($speaker, Arr::only($data, [
            'address',
            'contactMethods',
            'social_media',
            'language_ids',
        ]));
        $this->syncMedia($speaker, $data);

        if (! $creating) {
            $this->syncPublicSubmissionToggle($speaker, $actor, $currentPublicSubmission, $requestedPublicSubmission, $validationErrorKey);
        }

        return $speaker->fresh([
            'addresses',
            'contactMethods',
            'socialProfiles',
            'languages',
            'media',
        ]) ?? $speaker;
    }

    private function syncPublicSubmissionToggle(
        Person $speaker,
        User $actor,
        bool $currentPublicSubmission,
        bool $requestedPublicSubmission,
        string $validationErrorKey,
    ): void {
        if ($requestedPublicSubmission === $currentPublicSubmission) {
            return;
        }

        if ($requestedPublicSubmission) {
            $this->publicSubmissionLockService->unlockSpeaker($speaker, $actor);

            return;
        }

        $eligibility = $this->publicSubmissionLockService->speakerEligibility($speaker);

        if (! $eligibility->eligible) {
            throw ValidationException::withMessages([
                $validationErrorKey => $eligibility->reasons,
            ]);
        }

        $this->publicSubmissionLockService->lockSpeaker($speaker, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMedia(Person $speaker, array $data): void
    {
        if (($data['clear_avatar'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($speaker, 'avatar');
        }

        if (($data['clear_cover'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($speaker, 'cover');
        }

        if (($data['clear_main'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($speaker, 'main');
        }

        if (($data['clear_gallery'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($speaker, 'gallery');
        }

        $avatar = $data['avatar'] ?? null;
        $main = $data['main'] ?? null;
        $cover = $data['cover'] ?? null;
        $gallery = $data['gallery'] ?? null;

        $this->mediaSyncService->syncSingle(
            $speaker,
            $avatar instanceof UploadedFile ? $avatar : null,
            'avatar',
        );
        $this->mediaSyncService->syncSingle(
            $speaker,
            $main instanceof UploadedFile ? $main : null,
            'main',
        );
        $this->mediaSyncService->syncSingle(
            $speaker,
            $cover instanceof UploadedFile ? $cover : null,
            'cover',
        );
        $this->mediaSyncService->syncMultiple(
            $speaker,
            is_array($gallery) ? $gallery : null,
            'gallery',
            replace: is_array($gallery),
        );
    }

    /**
     * @param  iterable<int, mixed>  $entries
     * @return list<array{institution: string, degree: string, field: string|null, year: string|null}>
     */
    private function normalizeQualificationEntries(iterable $entries): array
    {
        return collect($entries)
            ->map(function (mixed $entry): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $institution = $this->normalizeOptionalString($entry['institution'] ?? null);
                $degree = $this->normalizeOptionalString($entry['degree'] ?? null);
                $field = $this->normalizeOptionalString($entry['field'] ?? null);
                $year = is_scalar($entry['year'] ?? null) ? trim((string) $entry['year']) : null;

                if ($institution === null || $degree === null) {
                    return null;
                }

                return [
                    'institution' => $institution,
                    'degree' => $degree,
                    'field' => $field,
                    'year' => $year !== '' ? $year : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
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

    /**
     * @param  iterable<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeStringArray(iterable $values): array
    {
        return Collection::make($values)
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
}
