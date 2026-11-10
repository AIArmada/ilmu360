<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use App\Features\Directory\Persistence\PersonSearchCache;
use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Directory\PersonSearchText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Owns the person_search_terms projection and the persons.searchable_name
 * denormalization.
 */
final readonly class SyncPersonSearchProjectionAction
{
    public function __construct(
        private PersonSearchCache $cache,
    ) {}

    public function sync(Model $person, bool $bustCache = true): void
    {
        if (Schema::hasColumn('persons', 'searchable_name')) {
            DirectoryModel::query('person')
                ->whereKey($person->getKey())
                ->update([
                    'searchable_name' => PersonSearchText::searchableText($person),
                ]);
        }

        $this->syncTerms($person);

        if ($bustCache) {
            $this->bustPublicSearchCache();
        }
    }

    public function purge(Model|string $person, bool $bustCache = true): void
    {
        $personId = $person instanceof Model ? (string) $person->getKey() : $person;

        if (Schema::hasTable('person_search_terms')) {
            DB::table('person_search_terms')
                ->where('person_id', $personId)
                ->delete();
        }

        if ($bustCache) {
            $this->bustPublicSearchCache();
        }
    }

    public function reindexAll(int $chunkSize = 100): int
    {
        $processed = 0;

        DirectoryModel::query('person')
            ->with(['names', 'titleAssignments.title.category'])
            ->select(['id', 'name', 'middle_name', 'family_name'])
            ->chunkById(max(1, $chunkSize), function ($persons) use (&$processed): void {
                foreach ($persons as $person) {
                    if (! $person instanceof Model) {
                        continue;
                    }

                    $this->sync($person, false);
                    $processed++;
                }
            });

        $this->bustPublicSearchCache();

        return $processed;
    }

    public function bustPublicSearchCache(): void
    {
        $this->cache->bump();
    }

    private function syncTerms(Model $person): void
    {
        if (! Schema::hasTable('person_search_terms')) {
            return;
        }

        DB::table('person_search_terms')
            ->where('person_id', $person->getKey())
            ->delete();

        $terms = PersonSearchText::terms(PersonSearchText::searchableText($person));

        if ($terms === []) {
            return;
        }

        DB::table('person_search_terms')->insert(
            collect($terms)
                ->map(static fn (string $term): array => [
                    'id' => (string) Str::uuid(),
                    'person_id' => (string) $person->getKey(),
                    'term' => $term,
                ])
                ->all()
        );
    }
}
