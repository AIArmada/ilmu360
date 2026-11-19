<?php

declare(strict_types=1);

namespace App\Features\References\Queries;

use App\Features\References\Api\ReferenceLookup;
use App\Features\References\Api\ReferenceSummary;
use App\Features\References\Models\Reference;
use App\Features\Reports\Api\ReportSubject;
use App\Features\Reports\Api\ReportSubjectType;
use App\Platform\Admin\AdminResourceUrl;
use App\Platform\Models\SlugOrUuidResolver;
use BackedEnum;

final readonly class EloquentReferenceLookup implements ReferenceLookup
{
    public function __construct(private SlugOrUuidResolver $resolver) {}

    public function resolveIdentifier(string $identifier): ?string
    {
        $entity = $this->entity($identifier);

        return $entity instanceof Reference ? (string) $entity->getKey() : null;
    }

    public function find(string $identifier): ?ReferenceSummary
    {
        $entity = $this->entity($identifier);
        if (! $entity instanceof Reference) {
            return null;
        }

        $fallback = (string) $entity->getKey();
        $status = $entity->getAttribute('status');
        $status = $status instanceof BackedEnum ? (string) $status->value : (filled($status) ? (string) $status : null);
        $entity->getAttribute('type');
        $slug = $entity->getAttribute('slug');
        $image = $entity->hasMedia('front_cover') ? $entity->getFirstMediaUrl('front_cover', 'thumb') : null;

        return new ReferenceSummary(
            id: $fallback,
            title: $entity->displayTitle(),
            slug: filled($slug) ? (string) $slug : null,
            status: $status,
            publicUrl: route('references.show', $entity),
            adminUrl: AdminResourceUrl::edit('references', (string) $entity->getRouteKey()),
            imageUrl: is_string($image) && $image !== '' ? $image : null,
        );
    }

    public function findReportSubject(string $id): ?ReportSubject
    {
        if (trim($id) === '') {
            return null;
        }
        $entity = Reference::query()->find($id);

        return $entity instanceof Reference ? $this->reportSubject($entity) : null;
    }

    public function resolveReportSubject(string $identifier): ?ReportSubject
    {
        $entity = $this->entity($identifier);

        return $entity instanceof Reference ? $this->reportSubject($entity) : null;
    }

    private function entity(string $identifier): ?Reference
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        return $this->resolver->first(Reference::query(), 'references.slug', $identifier);
    }

    private function reportSubject(Reference $entity): ReportSubject
    {
        $id = (string) $entity->getKey();

        return new ReportSubject(
            type: ReportSubjectType::Reference,
            id: $id,
            label: __('Reference'),
            title: filled($entity->getAttribute('title')) ? (string) $entity->getAttribute('title') : $id,
            slug: (string) $entity->getAttribute('slug'),
            publicUrl: route('references.show', $entity),
            adminUrl: AdminResourceUrl::edit('references', (string) $entity->getRouteKey()),
            imageUrl: null,
        );
    }
}
