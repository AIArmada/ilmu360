<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Venue;
use App\Platform\Routing\PublicSlugRegistry;
use Tests\TestCase;

uses(TestCase::class);

it('registers the current public slug surfaces in a stable order', function (): void {
    $registry = app(PublicSlugRegistry::class);

    expect($registry->parameters())
        ->toBe(['event', 'institution', 'person', 'venue', 'reference'])
        ->and($registry->resourceForParameter('event')?->routeName)->toBe('events.show')
        ->and($registry->resourceForParameter('event')?->modelClass)->toBe(Event::class)
        ->and($registry->resourceForParameter('institution')?->modelClass)->toBe(Institution::class)
        ->and($registry->resourceForParameter('person')?->modelClass)->toBe(Person::class)
        ->and($registry->resourceForParameter('venue')?->modelClass)->toBe(Venue::class)
        ->and($registry->resourceForParameter('reference')?->modelClass)->toBe(Reference::class);
});

it('records redirects without a prior visit only for the event surface', function (): void {
    $registry = app(PublicSlugRegistry::class);

    expect($registry->resourceForParameter('event')?->requiresVisitedSource)->toBeFalse()
        ->and($registry->resourceForParameter('institution')?->requiresVisitedSource)->toBeTrue()
        ->and($registry->resourceForParameter('person')?->requiresVisitedSource)->toBeTrue()
        ->and($registry->resourceForParameter('venue')?->requiresVisitedSource)->toBeTrue()
        ->and($registry->resourceForParameter('reference')?->requiresVisitedSource)->toBeTrue();
});

it('exposes search columns and label attributes for every surface', function (): void {
    $registry = app(PublicSlugRegistry::class);

    $expected = [
        'event' => [['title', 'slug'], 'title'],
        'institution' => [['name', 'slug'], 'name'],
        'person' => [['name', 'slug'], 'formatted_name'],
        'venue' => [['name', 'slug'], 'name'],
        'reference' => [['title', 'slug'], 'title'],
    ];

    foreach ($expected as $parameter => [$columns, $label]) {
        $resource = $registry->resourceForParameter($parameter);

        expect($resource)->not->toBeNull()
            ->and($resource->searchColumns)->toBe($columns)
            ->and($resource->labelAttribute)->toBe($label);
    }
});

it('resolves resources by parameter, model and morph alias', function (): void {
    $registry = app(PublicSlugRegistry::class);
    $eventResource = $registry->resourceForParameter('event');
    $event = new Event;

    expect($eventResource)->not->toBeNull()
        ->and($registry->resourceForModel($event))->toBe($eventResource)
        ->and($registry->resourceForType('event'))->toBe($eventResource)
        ->and($registry->resourceForType(Event::class))->toBe($eventResource)
        ->and($registry->resourceForType('series'))->toBeNull()
        ->and($registry->resourceForParameter('series'))->toBeNull();
});

it('builds canonical public paths from parameters and models', function (): void {
    $registry = app(PublicSlugRegistry::class);
    $event = new Event;
    $event->setAttribute('slug', 'majlis-ilmu-test');

    $sluglessEvent = new Event;
    $sluglessEvent->setAttribute('slug', '   ');

    expect($registry->pathForParameter('institution', 'masjid-test'))->toBe('/institusi/masjid-test')
        ->and($registry->pathForParameter('series', 'siri-test'))->toBeNull()
        ->and($registry->pathForModel($event))->toBe('/majlis/majlis-ilmu-test')
        ->and($registry->pathForModel($sluglessEvent))->toBeNull()
        ->and($registry->parameterForModel($event))->toBe('event');
});
