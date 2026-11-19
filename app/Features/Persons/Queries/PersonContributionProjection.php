<?php

declare(strict_types=1);

namespace App\Features\Persons\Queries;

use AIArmada\Addressing\Support\ModelResolver;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Enums\Gender;
use AIArmada\Persons\Enums\PersonNameType;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Platform\Models\MorphModelResolver;
use App\Platform\Validation\Rules\ValidAreaAssignmentRoles;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use RuntimeException;

final readonly class PersonContributionProjection implements \App\Shared\Contributions\ContributionProjection
{
    use \App\Shared\Contributions\Support\BuildsContributionDescriptors;
    use \App\Shared\Contributions\Support\BuildsContributionSubjectState;

    public function supports(string $entityType): bool
    {
        return $entityType === 'person';
    }

    /** @return array<string, mixed> */
    public function contractFor(string $subjectId): array
    {
        $this->resolve($subjectId);

        return [
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
                $this->field('institutions', 'array<object>', catalog: route('api.client.catalogs.submit-institutions'), meta: [
                    'item_schema' => [
                        'type' => 'object',
                        'fields' => [
                            ['name' => 'institution_id', 'type' => 'uuid', 'required' => true],
                            ['name' => 'position', 'type' => 'string', 'required' => false, 'max_length' => 255],
                            ['name' => 'is_primary', 'type' => 'boolean', 'required' => false],
                        ],
                    ],
                ]),
                $this->field('address', 'object'),
                $this->field('address.country_id', 'uuid', catalog: route('api.client.catalogs.countries')),
                $this->field('contactMethods', 'array<object>'),
                $this->field('social_media', 'array<object>'),
            ],
            'conditional_rules' => [],
            'direct_edit_media_fields' => ['avatar', 'main', 'profile', 'cover', 'gallery'],
        ];
    }

    /** @return array<string, mixed> */
    public function stateFor(string $subjectId): array
    {
        return $this->personState($this->resolve($subjectId));
    }

    /** @return array<string, mixed> */
    public function updateRulesFor(string $subjectId): array
    {
        $this->resolve($subjectId);

        return [
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
        ];
    }

    private function resolve(string $subjectId): Person
    {
        $modelClass = MorphModelResolver::resolve('person');
        $model = $modelClass::query()->findOrFail($subjectId);

        if (! $model instanceof Person) {
            throw new RuntimeException('Expected the resolved contribution subject to be a person.');
        }

        return $model;
    }

    /** @return array<string, mixed> */
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
            'names' => $person->names->map(fn (Model $name): array => [
                'id' => (string) $name->getKey(),
                'name_type' => $name->name_type instanceof PersonNameType ? $name->name_type->value : (string) $name->name_type,
                'full_name' => $name->full_name,
                'language_code' => $name->language_code,
                'is_primary' => (bool) $name->is_primary,
            ])->values()->all(),
            'title_assignments' => $person->titleAssignments->map(fn (Model $assignment): array => [
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
                'position' => $this->institutionPivotPosition($institution),
                'is_primary' => $this->institutionPivotIsPrimary($institution),
            ])->values()->all(),
            'address' => $this->addressState($person->primaryAddress()),
            'contactMethods' => $this->contactMethodsState($person->contactMethods),
            'social_media' => $this->socialMediaState($person->socialProfiles),
        ];
    }

    private function institutionPivotPosition(?Institution $institution): ?string
    {
        if (! $institution instanceof Institution) {
            return null;
        }

        $position = data_get($institution->getRelations(), 'pivot.position');

        return is_string($position) && $position !== '' ? $position : null;
    }

    private function institutionPivotIsPrimary(Institution $institution): bool
    {
        return (bool) data_get($institution->getRelations(), 'pivot.is_primary', false);
    }
}
