<?php

declare(strict_types=1);

namespace App\Features\References\Actions;

use App\Models\Person;

use AIArmada\References\Enums\ReferenceContributorRole;
use AIArmada\References\Models\ReferenceContributor;
use App\Features\References\Support\ReferencePayload;
use Illuminate\Database\Eloquent\Model;

/**
 * Replace the author links stored in reference_contributors.
 *
 * Authors are an unordered set owned by the root work; editions and parts
 * inherit them and must never keep their own links.
 */
final readonly class SyncReferenceContributorsAction
{
    /**
     * @param  mixed  $authorIds  List of person UUIDs; explicit null clears the set.
     */
    public function sync(Model $reference, mixed $authorIds): void
    {
        if (! ReferencePayload::isRoot($reference)) {
            return;
        }

        $normalized = [];

        foreach (is_array($authorIds) ? $authorIds : [] as $authorId) {
            $value = is_string($authorId) ? trim($authorId) : '';

            if ($value !== '' && ! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        if (! method_exists($reference, 'syncContributors')) {
            return;
        }

        $personClass = Person::class;

        $reference->syncContributors(
            ReferenceContributorRole::Author,
            (new $personClass)->getMorphClass(),
            $normalized,
        );
    }

    public function clear(Model $reference): void
    {
        ReferenceContributor::query()
            ->where('reference_id', (string) $reference->getKey())
            ->where('role', ReferenceContributorRole::Author->value)
            ->delete();

        $reference->unsetRelation('authorLinks');
        $reference->unsetRelation('authors');
    }

    /**
     * @return list<string>
     */
    public function authorIds(Model $reference): array
    {
        return ReferenceContributor::query()
            ->where('reference_id', (string) $reference->getKey())
            ->where('role', ReferenceContributorRole::Author->value)
            ->pluck('contributor_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
