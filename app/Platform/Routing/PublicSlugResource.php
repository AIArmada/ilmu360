<?php

declare(strict_types=1);

namespace App\Platform\Routing;

use Illuminate\Database\Eloquent\Model;

/**
 * Describes one public slug surface: the route parameter and name that expose
 * it, the model that owns the slug, and the optional admin search/label hints.
 */
final readonly class PublicSlugResource
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $searchColumns
     */
    public function __construct(
        public string $parameter,
        public string $routeName,
        public string $modelClass,
        public bool $requiresVisitedSource = true,
        public array $searchColumns = [],
        public ?string $labelAttribute = null,
    ) {}

    public function pathForSlug(string $slug): string
    {
        return route($this->routeName, [$this->parameter => $slug], false);
    }

    public function labelFor(Model $model): string
    {
        $label = $this->labelAttribute === null
            ? null
            : $model->getAttribute($this->labelAttribute);

        if (is_string($label) && trim($label) !== '') {
            return trim($label);
        }

        return class_basename($model::class).' #'.$model->getKey();
    }
}
