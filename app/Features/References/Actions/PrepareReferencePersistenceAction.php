<?php

declare(strict_types=1);

namespace App\Features\References\Actions;

use App\Features\References\Api\Enums\ReferencePartType;
use App\Features\References\Api\Enums\ReferenceType;
use App\Features\References\Api\ReferenceWorkflow;
use App\Features\References\Models\Reference;
use App\Features\References\Support\ReferenceInputValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Prepare reference hierarchy, part fields, language and status before persistence. */
final readonly class PrepareReferencePersistenceAction
{
    public function __construct(private ReferenceWorkflow $workflow) {}

    public function handle(Reference $reference): void
    {
        $this->normalizeReferencePartFields($reference);

        if (blank($reference->slug)) {
            $reference->slug = $this->workflow->buildSlug($reference->displayTitle(), (string) $reference->getKey());
        }

        if ($reference->isDirty('status')) {
            $now = now();
            $reference->setAttribute('last_state_change_at', $now);
            $status = (string) $reference->status;

            match ($status) {
                'verified' => $reference->getAttribute('verified_at') ?? $reference->setAttribute('verified_at', $now),
                'published' => $reference->getAttribute('published_at') ?? $reference->setAttribute('published_at', $now),
                'rejected' => $reference->getAttribute('rejected_at') ?? $reference->setAttribute('rejected_at', $now),
                default => null,
            };

            if (in_array($status, ['verified', 'pending'], true)
                && ! $reference->isDirty('published_at')
                && ($reference->exists || ! array_key_exists('published_at', $reference->getAttributes()))) {
                $reference->setAttribute('published_at', $now);
            }

            if ($status === 'verified' && $reference->getAttribute('verified_by') === null) {
                $reference->setAttribute('verified_by', auth()->id());
            }
        }
    }

    private function normalizeReferencePartFields(Reference $reference): void
    {
        if (! $reference->isRootReference() && $reference->typeValue() !== ReferenceType::Book->value) {
            throw ValidationException::withMessages(['type' => __('Only books can have editions or parts.')]);
        }

        if ($reference->isRootReference() && $reference->exists && $reference->typeValue() !== ReferenceType::Book->value && $reference->childReferences()->exists()) {
            throw ValidationException::withMessages(['type' => __('A book with editions or parts must remain a book.')]);
        }

        if ($reference->isDirty('parent_id')) {
            $reference->unsetRelation('parentReference');
        }

        if ($reference->parentIdValue() !== null) {
            $parent = Reference::query()->find($reference->parentIdValue());
            if ($parent instanceof Reference && $parent->typeValue() !== ReferenceType::Book->value) {
                throw ValidationException::withMessages(['parent_id' => __('Select a book work or edition.')]);
            }
        }

        if ($reference->isPart()) {
            if (array_key_exists('part_type', $reference->getAttributes())
                || array_key_exists('part_number', $reference->getAttributes())
                || array_key_exists('part_label', $reference->getAttributes())) {
                $type = ReferencePartType::tryFrom((string) ReferenceInputValues::optionalStringAttribute($reference->getAttributes(), 'part_type'));
                if ($type === null) {
                    throw ValidationException::withMessages(['part_type' => __('Select a valid part type.')]);
                }
                $reference->reference_parts = [[
                    'type' => $type->value,
                    'value' => ReferenceInputValues::nullableTrimmedString(ReferenceInputValues::optionalStringAttribute($reference->getAttributes(), 'part_number')),
                    'label' => ReferenceInputValues::nullableTrimmedString(ReferenceInputValues::optionalStringAttribute($reference->getAttributes(), 'part_label')),
                ]];
            }
            if (ReferencePartType::tryFrom((string) $reference->partTypeValue()) === null) {
                throw ValidationException::withMessages(['part_type' => __('Select a valid part type.')]);
            }
            if (blank($reference->partNumberValue()) && blank($reference->partLabelValue())) {
                throw ValidationException::withMessages(['part_number' => __('Enter a part number or label.')]);
            }
        } else {
            $reference->reference_parts = null;
        }

        if (! $reference->isEdition()) {
            $reference->edition_number = null;
            $reference->edition_label = null;
        } elseif (blank($reference->edition_number) && blank($reference->edition_label) && blank($reference->publisher) && blank($reference->year) && blank($reference->isbn)) {
            throw ValidationException::withMessages(['edition_label' => __('Enter a printing number, edition label, publisher, year, or ISBN to identify this edition.')]);
        }

        $language = ReferenceInputValues::nullableTrimmedString(ReferenceInputValues::optionalStringAttribute($reference->getAttributes(), 'language'));
        $reference->language = $language;

        if ($language !== null) {
            if (mb_strlen($language) > 10) {
                throw ValidationException::withMessages(['language' => __('Select a valid language.')]);
            }

            if (! DB::table('languages')->where('code', $language)->exists()) {
                throw ValidationException::withMessages(['language' => __('Select a valid language.')]);
            }
        }

        unset($reference['part_type'], $reference['part_number'], $reference['part_label']);
    }
}
