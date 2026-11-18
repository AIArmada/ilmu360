<?php

declare(strict_types=1);

namespace App\Features\Persons\Queries;

use App\Features\Persons\Api\PersonLookup;
use App\Features\Persons\Api\PersonSummary;
use App\Features\Persons\Models\Person;
use App\Platform\Admin\AdminResourceUrl;
use App\Platform\Models\SlugOrUuidResolver;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectType;
use BackedEnum;

final readonly class EloquentPersonLookup implements PersonLookup
{
    public function __construct(private SlugOrUuidResolver $resolver) {}

    public function resolveIdentifier(string $identifier): ?string
    {
        $entity = $this->entity($identifier);

        return $entity instanceof Person ? (string) $entity->getKey() : null;
    }

    public function find(string $identifier): ?PersonSummary
    {
        $entity = $this->entity($identifier);
        if (! $entity instanceof Person) {
            return null;
        }

        $fallback = (string) $entity->getKey();
        $status = $entity->getAttribute('status');
        $status = $status instanceof BackedEnum ? (string) $status->value : (filled($status) ? (string) $status : null);
        $slug = $entity->getAttribute('slug');
        $image = $entity->public_main_url;

        return new PersonSummary(
            id: $fallback,
            title: filled($entity->formatted_name) ? (string) $entity->formatted_name : $fallback,
            slug: filled($slug) ? (string) $slug : null,
            status: $status,
            publicUrl: route('persons.show', $entity),
            adminUrl: AdminResourceUrl::edit('persons', (string) $entity->getRouteKey()),
            imageUrl: is_string($image) && $image !== '' ? $image : null,
        );
    }

    public function findReportSubject(string $id): ?ReportSubject
    {
        if (trim($id) === '') {
            return null;
        }
        $entity = Person::query()->find($id);

        return $entity instanceof Person ? $this->reportSubject($entity) : null;
    }

    public function resolveReportSubject(string $identifier): ?ReportSubject
    {
        $entity = $this->entity($identifier);

        return $entity instanceof Person ? $this->reportSubject($entity) : null;
    }

    private function entity(string $identifier): ?Person
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        return $this->resolver->first(Person::query(), 'persons.slug', $identifier);
    }

    private function reportSubject(Person $entity): ReportSubject
    {
        $id = (string) $entity->getKey();

        return new ReportSubject(
            type: ReportSubjectType::Person,
            id: $id,
            label: __('Speaker'),
            title: filled($entity->getAttribute('formatted_name')) ? (string) $entity->getAttribute('formatted_name') : $id,
            slug: (string) $entity->getAttribute('slug'),
            publicUrl: route('persons.show', $entity),
            adminUrl: AdminResourceUrl::edit('persons', (string) $entity->getRouteKey()),
            imageUrl: $entity->getAttribute('public_main_url'),
        );
    }
}
