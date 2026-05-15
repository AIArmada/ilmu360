<?php

declare(strict_types=1);

namespace App\Support\Slugs;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Model;

final class PublicSlugPathResolver
{
    public function supportsParameter(string $parameter): bool
    {
        return $this->routeNameForParameter($parameter) !== null;
    }

    public function pathForParameter(string $parameter, string $slug): ?string
    {
        $routeName = $this->routeNameForParameter($parameter);

        if ($routeName === null) {
            return null;
        }

        return route($routeName, [$parameter => $slug], false);
    }

    public function pathForModel(Model $model): ?string
    {
        $parameter = $this->parameterForModel($model);
        $slug = $this->normalizedSlug($model->getAttribute('slug'));

        if ($parameter === null || $slug === null) {
            return null;
        }

        return $this->pathForParameter($parameter, $slug);
    }

    public function parameterForModel(Model $model): ?string
    {
        return match ($model::class) {
            Event::class => 'event',
            Institution::class => 'institution',
            Speaker::class => 'speaker',
            Venue::class => 'venue',
            Reference::class => 'reference',
            default => null,
        };
    }

    private function routeNameForParameter(string $parameter): ?string
    {
        return match ($parameter) {
            'event' => 'events.show',
            'institution' => 'institutions.show',
            'speaker' => 'speakers.show',
            'venue' => 'venues.show',
            'reference' => 'references.show',
            default => null,
        };
    }

    private function normalizedSlug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
