<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Nnjeim\World\Models\Language;

/**
 * @method array<string, list<mixed>> auditSync(string $relationName, mixed $ids, bool $detaching = true, array<int, string> $columns = ['*'], mixed $callback = null)
 */
trait HasLanguages
{
    /**
     * Get all of the languages for the model.
     *
     * @return MorphToMany<Language, $this>
     */
    public function languages(): MorphToMany
    {
        return $this->morphToMany(Language::class, 'languageable', 'languageables');
    }

    /**
     * Sync the languages for the model.
     *
     * @param  array<int>|int  $languages
     */
    public function syncLanguages(array|int $languages): void
    {
        $this->auditSync('languages', $languages, true, ['languages.id', 'languages.name']);
    }
}
