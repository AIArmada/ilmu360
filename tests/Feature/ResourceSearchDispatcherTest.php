<?php

use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Platform\Search\ResourceSearchAdapter;
use App\Platform\Search\ResourceSearchDispatcher;
use App\Support\Search\Adapters\InstitutionResourceSearchAdapter;
use App\Support\Search\Adapters\PersonResourceSearchAdapter;
use App\Support\Search\Adapters\ReferenceResourceSearchAdapter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

it('registers adapters in the preserved person institution reference order', function () {
    $adapters = iterator_to_array(app()->tagged(ResourceSearchAdapter::TAG), false);

    expect(array_map(static fn (ResourceSearchAdapter $adapter): string => $adapter::class, $adapters))
        ->toBe([
            PersonResourceSearchAdapter::class,
            InstitutionResourceSearchAdapter::class,
            ReferenceResourceSearchAdapter::class,
        ]);
});

it('applies the first supporting adapter and falls through unsupported models', function () {
    $personAdapter = new class implements ResourceSearchAdapter
    {
        public function supports(Model $model): bool
        {
            return $model instanceof Person;
        }

        public function matchingIds(Builder $query, string $search): array
        {
            return ['person-1'];
        }
    };

    $secondPersonAdapter = new class implements ResourceSearchAdapter
    {
        public function supports(Model $model): bool
        {
            return $model instanceof Person;
        }

        public function matchingIds(Builder $query, string $search): array
        {
            return ['person-2'];
        }
    };

    $institutionAdapter = new class implements ResourceSearchAdapter
    {
        public function supports(Model $model): bool
        {
            return $model instanceof Institution;
        }

        public function matchingIds(Builder $query, string $search): array
        {
            return ['institution-1'];
        }
    };

    $dispatcher = new ResourceSearchDispatcher([
        $personAdapter,
        $secondPersonAdapter,
        $institutionAdapter,
    ]);

    $personQuery = Person::query();
    expect($dispatcher->apply($personQuery, 'ali'))->toBeTrue()
        ->and($personQuery->toSql())->toContain('"id" in (?)')
        ->and($personQuery->getBindings())->toBe(['person-1']);

    $institutionQuery = Institution::query();
    expect($dispatcher->apply($institutionQuery, 'masjid'))->toBeTrue()
        ->and($institutionQuery->getBindings())->toBe(['institution-1']);

    $referenceQuery = Reference::query();
    expect($dispatcher->apply($referenceQuery, 'tajwid'))->toBeFalse();
});

it('matches no records when a supporting adapter finds no ids', function () {
    $adapter = new class implements ResourceSearchAdapter
    {
        public function supports(Model $model): bool
        {
            return $model instanceof Person;
        }

        public function matchingIds(Builder $query, string $search): array
        {
            return [];
        }
    };

    $query = Person::query();

    expect((new ResourceSearchDispatcher([$adapter]))->apply($query, 'missing'))->toBeTrue()
        ->and($query->toSql())->toContain('1 = 0');
});
