<?php

namespace App\Models\Concerns;

use App\Models\Language;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

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
     * @param  array<int, string>|string  $languages
     */
    public function syncLanguages(array|string $languages): void
    {
        $this->auditSync('languages', $languages, true, ['languages.id', 'languages.name']);
    }
}
