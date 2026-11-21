<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Submission;

use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\EventCategoryPolicyResolver;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Api\SubmissionValues;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Semantic public event submission category rules. */
final readonly class ValidateEventSubmissionCategoriesAction
{
    /** @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    public function normalize(array $validated, string $validationKeyPrefix = ''): array
    {
        // Semantic boundary: unknown, wrong-taxonomy, or inactive category IDs
        // must fail here. Catalog normalization silently drops such IDs, so an
        // unchecked result could turn a required selection into [].
        $catalog = app(EventCategoryCatalog::class);
        $requestedCategoryIds = is_array($validated['event_category_ids'] ?? null) ? $validated['event_category_ids'] : [];
        $normalizedRequestedCategoryIds = array_values(array_unique(array_map(static fn (mixed $id): string => (string) $id, $requestedCategoryIds)));
        $knownCategoryIds = $catalog->validTermIds($normalizedRequestedCategoryIds);

        if (array_diff($normalizedRequestedCategoryIds, $knownCategoryIds) !== []) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_category_ids', $validationKeyPrefix) => __('Pilihan kategori majlis tidak sah.'),
            ]);
        }

        $validated['event_category_ids'] = $catalog->validateTermIds($knownCategoryIds);

        return $validated;
    }

    /** @param array<string, mixed> $validated */
    public function assertCommunityPhysicalFormat(array $validated, string $validationKeyPrefix = ''): void
    {
        if (
            $this->hasCommunityCategorySelection($validated['event_category_ids'] ?? [], $validationKeyPrefix)
            && (($validated['event_format'] ?? EventFormat::Physical->value) !== EventFormat::Physical->value)
        ) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_format', $validationKeyPrefix) => __('Jenis majlis komuniti mesti menggunakan format fizikal.'),
            ]);
        }
    }

    private function hasCommunityCategorySelection(mixed $categoryIds, string $validationKeyPrefix = ''): bool
    {
        if ($categoryIds instanceof Collection) {
            $categoryIds = $categoryIds->all();
        }

        if (! is_array($categoryIds)) {
            $categoryIds = [$categoryIds];
        }

        try {
            return app(EventCategoryPolicyResolver::class)->requiresPhysicalDelivery(
                app(EventCategoryCatalog::class)->validateTermIds($categoryIds),
            );
        } catch (ValidationException $exception) {
            $messages = [];

            foreach ($exception->errors() as $fieldMessages) {
                foreach ((array) $fieldMessages as $message) {
                    $messages[SubmissionValues::prefixedKey('event_category_ids', $validationKeyPrefix)][] = $message;
                }
            }

            throw ValidationException::withMessages($messages);
        }
    }
}
