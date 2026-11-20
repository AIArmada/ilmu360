<?php

declare(strict_types=1);

namespace App\Features\References\Queries;

use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\References\Enums\ReferenceRecordKind;
use AIArmada\References\Rules\Isbn;
use App\Features\References\Api\ContributionProjection;
use App\Features\References\Api\Enums\ReferencePartType;
use App\Features\References\Api\Enums\ReferenceType;
use App\Features\References\Models\Reference;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Validation\Rule;
use RuntimeException;

final readonly class ReferenceContributionProjection implements ContributionProjection
{
    use \App\Shared\Contributions\Support\BuildsContributionDescriptors;
    use \App\Shared\Contributions\Support\BuildsContributionSubjectState;

    /** @return array<string, mixed> */
    public function contractFor(string $subjectId): array
    {
        $this->resolve($subjectId);

        return [
            'accepts_partial_updates' => true,
            'fields' => [
                $this->field('title', 'string', maxLength: 255),
                $this->field('author_ids', 'array<string>', catalog: route('api.client.catalogs.reference-authors')),
                $this->field('type', 'string', allowedValues: $this->enumValues(ReferenceType::class)),
                $this->field('parent_id', 'uuid'),
                $this->field('record_kind', 'string', allowedValues: $this->enumValues(ReferenceRecordKind::class)),
                $this->field('edition_number', 'integer'),
                $this->field('edition_label', 'string', maxLength: 255),
                $this->field('isbn', 'string', maxLength: 20),
                $this->field('language', 'string', maxLength: 10),
                $this->field('part_type', 'string', allowedValues: $this->enumValues(ReferencePartType::class)),
                $this->field('part_number', 'string', maxLength: 255),
                $this->field('part_label', 'string', maxLength: 255),
                $this->field('publication_year', 'integer'),
                $this->field('publisher', 'string', maxLength: 255),
                $this->field('description', 'string'),
                $this->field('url', 'url'),
                $this->field('social_media', 'array<object>'),
            ],
            'conditional_rules' => [],
            'direct_edit_media_fields' => ['front_cover', 'back_cover', 'gallery'],
        ];
    }

    /** @return array<string, mixed> */
    public function stateFor(string $subjectId): array
    {
        return $this->referenceState($this->resolve($subjectId));
    }

    /** @return array<string, mixed> */
    public function updateRulesFor(string $subjectId): array
    {
        $this->resolve($subjectId);

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'author_ids' => ['sometimes', 'nullable', 'array'],
            'author_ids.*' => ['uuid', Rule::exists('persons', 'id')->whereIn('status', ['verified', 'pending'])],
            'type' => ['sometimes', Rule::in($this->enumValues(ReferenceType::class))],
            'parent_id' => ['nullable', 'uuid', Rule::exists('references', 'id')->where('type', ReferenceType::Book->value)->whereIn('record_kind', ['work', 'edition'])],
            'record_kind' => ['sometimes', Rule::enum(ReferenceRecordKind::class)],
            'edition_number' => ['nullable', 'integer', 'min:1'],
            'edition_label' => ['nullable', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:20', new Isbn],
            'language' => ['nullable', 'string', 'max:10', Rule::exists('languages', 'code')],
            'part_type' => ['nullable', Rule::in($this->enumValues(ReferencePartType::class))],
            'part_number' => ['nullable', 'string', 'max:255'],
            'part_label' => ['nullable', 'string', 'max:255'],
            'publication_year' => ['nullable', 'integer', 'min:-3000', 'max:'.((int) now()->year + 5)],
            'publisher' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'url' => ['nullable', 'url:http,https', 'max:255'],
            'social_media' => ['sometimes', 'array'],
            'social_media.*.platform' => ['required_with:social_media.*.handle,social_media.*.url', Rule::in($this->enumValues(SocialPlatform::class))],
            'social_media.*.handle' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
            'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.handle'],
        ];
    }

    private function resolve(string $subjectId): Reference
    {
        $modelClass = MorphModelResolver::resolve('reference');
        $model = $modelClass::query()->findOrFail($subjectId);

        if (! $model instanceof Reference) {
            throw new RuntimeException('Expected the resolved contribution subject to be a reference.');
        }

        return $model;
    }

    /** @return array<string, mixed> */
    private function referenceState(Reference $reference): array
    {
        $reference->loadMissing(['socialProfiles']);

        return [
            'title' => $reference->title,
            'author_ids' => $reference->authorIdsValue(),
            'type' => $reference->type,
            'parent_id' => $reference->parent_id,
            'record_kind' => $reference->record_kind,
            'edition_number' => $reference->edition_number,
            'edition_label' => $reference->edition_label,
            'isbn' => $reference->isbn,
            'language' => $reference->language,

            'part_type' => $reference->partTypeValue(),
            'part_number' => $reference->partNumberValue(),
            'part_label' => $reference->partLabelValue(),
            'publication_year' => $reference->year,
            'publisher' => $reference->publisher,
            'description' => $reference->description,
            'url' => $reference->url,
            'social_media' => $this->socialMediaState($reference->socialProfiles),
        ];
    }
}
