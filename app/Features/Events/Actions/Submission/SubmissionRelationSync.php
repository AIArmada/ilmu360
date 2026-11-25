<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Submission;

use AIArmada\Events\Features\Catalog\Models\Event as PackageEvent;
use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Events\Features\Catalog\Models\EventReference;
use AIArmada\Events\Features\Catalog\Models\EventSession;
use App\Features\Events\Api\SubmissionValues;
use App\Features\Events\Queries\PublicReferenceConstraint;
use App\Features\Events\Support\EventModel;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Relation-mapping helpers shared by the new-event and session persistence
 * paths. Both flows normalize person IDs, key-people rows, and reference
 * input identically; event writes stay event-scoped and session writes stay
 * session-scoped.
 */
final readonly class SubmissionRelationSync
{
    public function __construct(private PublicReferenceConstraint $references) {}

    /** @param array<string, mixed> $state */
    public function assertCatalogSelectionsAreAvailable(array $state, string $validationKeyPrefix): void
    {
        $languageIds = $state['languages'] ?? [];
        $referenceIds = $state['references'] ?? [];
        $errors = [];

        if ($languageIds !== [] && array_diff($languageIds, EventModel::query('language')->whereIn('id', $languageIds)->pluck('id')->all()) !== []) {
            $errors[SubmissionValues::prefixedKey('languages', $validationKeyPrefix)] = __('The selected language is invalid.');
        }

        if ($referenceIds !== [] && array_diff($referenceIds, $this->references->apply(EventModel::query('reference'))->whereIn('references.id', $referenceIds)->pluck('references.id')->all()) !== []) {
            $errors[SubmissionValues::prefixedKey('references', $validationKeyPrefix)] = __('The selected reference is unavailable.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<string|int|mixed>  $personIds
     * @return list<string>
     */
    public function normalizePersonIds(array $personIds): array
    {
        return collect($personIds)
            ->filter(fn (mixed $personId): bool => is_string($personId) && Str::isUuid($personId))
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function canonicalKeyPeople(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)->filter(fn (mixed $row): bool => is_array($row))->map(static fn (array $row): array => [
            'role_code' => $row['role_code'] ?? null,
            'involveable_type' => ($row['involveable_type'] ?? null) === 'person' ? 'person' : null,
            'involveable_id' => $row['involveable_id'] ?? null,
            'display_name' => $row['display_name'] ?? null,
            'visibility' => $row['visibility'] ?? 'public',
            'notes' => $row['notes'] ?? null,
        ])->values()->all();
    }

    /**
     * Event references are owned here so the API media-only callback and the
     * Livewire media-only callback share one writer. The pivot defaults
     * (reference, book, public) apply; sort order preserves input order.
     *
     * @param  \App\Features\Events\Models\Event  $event
     */
    public function syncEventReferences(PackageEvent $event, mixed $references): void
    {
        if (! is_array($references)) {
            return;
        }

        $ids = collect($references)
            ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        EventReference::query()
            ->where('event_id', $event->getKey())
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id')
            ->delete();

        foreach ($ids as $sort => $id) {
            EventReference::query()->create([
                'event_id' => $event->getKey(),
                'event_occurrence_id' => null,
                'event_session_id' => null,
                'referenceable_type' => 'reference',
                'referenceable_id' => $id,
                'reference_type' => 'book',
                'visibility' => 'public',
                'sort_order' => $sort,
            ]);
        }
    }

    /**
     * Session references mirror the event pivot defaults
     * (reference/Reference, book, public) on session-scoped rows.
     */
    public function syncSessionReferences(PackageEvent $event, EventOccurrence $occurrence, EventSession $session, mixed $references): void
    {
        if (! is_array($references)) {
            return;
        }

        $ids = collect($references)
            ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
            ->unique()
            ->values();

        foreach ($ids as $sort => $id) {
            EventReference::query()->create([
                'event_id' => $event->getKey(),
                'event_occurrence_id' => $occurrence->getKey(),
                'event_session_id' => $session->getKey(),
                'referenceable_type' => 'reference',
                'referenceable_id' => $id,
                'reference_type' => 'book',
                'visibility' => 'public',
                'sort_order' => $sort,
            ]);
        }
    }

    /**
     * Occurrence references mirror the existing event and session reference
     * defaults while keeping each contribution scoped to its submitted date.
     */
    public function syncOccurrenceReferences(PackageEvent $event, EventOccurrence $occurrence, mixed $references): void
    {
        if (! is_array($references)) {
            return;
        }

        $ids = collect($references)
            ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
            ->unique()
            ->values();

        foreach ($ids as $sort => $id) {
            EventReference::query()->create([
                'event_id' => $event->getKey(),
                'event_occurrence_id' => $occurrence->getKey(),
                'event_session_id' => null,
                'referenceable_type' => 'reference',
                'referenceable_id' => $id,
                'reference_type' => 'book',
                'visibility' => 'public',
                'sort_order' => $sort,
            ]);
        }
    }
}
