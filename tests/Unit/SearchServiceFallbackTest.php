<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\PersonSearchService;
use App\Support\Search\ReferenceSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $request = app()->make('request');

    if ($request instanceof Request) {
        OwnerContext::setForRequest(null);
    }
});

it('falls back to the local speaker search index when typesense lookup fails', function () {
    $person = Person::factory()->create([
        'name' => 'Nurul Akma',
        'status' => 'verified',
    ]);

    $baseService = app(PersonSearchService::class);
    $baseService->syncSpeakerRecord($person);

    $service = new class extends PersonSearchService
    {
        protected function shouldUseScoutSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    };

    $ids = $service->publicSearchIds($person->name);
    $queryIds = $service->applyIndexedSearch(Person::query()->where('status', 'verified'), $person->name)
        ->pluck('persons.id')
        ->map(static fn (mixed $id): string => (string) $id)
        ->all();

    expect($ids)->toContain((string) $person->id)
        ->and($queryIds)->toContain((string) $person->id);
});

it('falls back to local speaker fuzzy search when typesense lookup fails', function () {
    $person = Person::factory()->create([
        'name' => 'Samad Al-Bakri',
        'status' => 'verified',
    ]);

    app(PersonSearchService::class)->syncSpeakerRecord($person);

    $service = new class extends PersonSearchService
    {
        protected function shouldUseScoutSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    };

    expect($service->publicFuzzySearchIds('Smad'))->toContain((string) $person->id);
});

it('keeps transposed speaker typos reachable through fallback candidate filtering', function () {
    $person = Person::factory()->create([
        'name' => 'Ahmad Fauzi',
        'status' => 'verified',
    ]);

    app(PersonSearchService::class)->syncSpeakerRecord($person);

    $service = new class extends PersonSearchService
    {
        protected function shouldUseTypesenseSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    };

    expect($service->publicFuzzySearchIds('Ahmda'))->toContain((string) $person->id);
});

it('keeps exact speaker fuzzy matches inside the capped fallback candidate set', function () {
    $baseService = app(PersonSearchService::class);

    foreach (range(1, 5) as $index) {
        Person::factory()->create([
            'name' => "Samadx Alpha {$index}",
            'status' => 'verified',
        ]);
    }

    $exactPerson = Person::factory()->create([
        'name' => 'Samadx',
        'status' => 'verified',
    ]);

    $baseService->syncSpeakerRecord($exactPerson);

    $service = new class extends PersonSearchService
    {
        protected function shouldUseTypesenseSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}

        protected function typesenseResultLimit(): int
        {
            return 5;
        }
    };

    expect($service->publicFuzzySearchIds('Samadx'))->toContain((string) $exactPerson->id);
});

it('falls back to database institution search when typesense lookup fails', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'nickname' => 'Masjid Biru',
        'description' => 'Pusat komuniti',
        'status' => 'verified',
    ]);

    $service = new class extends InstitutionSearchService
    {
        protected function shouldUseScoutSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    };

    $ids = $service->publicSearchIds('Masjid Biru');
    $queryIds = $service->applySearch(Institution::query()->where('status', 'verified'), 'Masjid Biru')
        ->pluck('institutions.id')
        ->map(static fn (mixed $id): string => (string) $id)
        ->all();

    expect($ids)->toContain((string) $institution->id)
        ->and($queryIds)->toContain((string) $institution->id);
});

it('falls back to database institution fuzzy search when typesense lookup fails', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'description' => 'Kuliah dan komuniti',
        'status' => 'verified',
    ]);

    $service = new class extends InstitutionSearchService
    {
        protected function shouldUseScoutSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    };

    expect($service->publicFuzzySearchIds('Hidayh'))->toContain((string) $institution->id);
});

it('keeps transposed institution typos reachable through fallback candidate filtering', function () {
    $institution = Institution::factory()->create([
        'name' => 'Pusat Ahmad',
        'description' => 'Kuliah dan komuniti',
        'status' => 'verified',
    ]);

    $service = new class extends InstitutionSearchService
    {
        protected function shouldUseTypesenseSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    };

    expect($service->publicFuzzySearchIds('Ahmda'))->toContain((string) $institution->id);
});

it('keeps exact institution fuzzy matches inside the capped fallback candidate set', function () {
    foreach (range(1, 5) as $index) {
        Institution::factory()->create([
            'name' => "Samadx Alpha {$index}",
            'description' => 'Kuliah dan komuniti',
            'status' => 'verified',
        ]);
    }

    $exactInstitution = Institution::factory()->create([
        'name' => 'Samadx',
        'description' => 'Kuliah dan komuniti',
        'status' => 'verified',
    ]);

    $service = new class extends InstitutionSearchService
    {
        protected function shouldUseTypesenseSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}

        protected function typesenseResultLimit(): int
        {
            return 5;
        }
    };

    expect($service->publicFuzzySearchIds('Samadx'))->toContain((string) $exactInstitution->id);
});

it('uses scout database search for persons when the database driver is configured', function () {
    config()->set('scout.driver', 'database');

    $person = Person::factory()->create([
        'name' => 'Nurul Akma',
        'status' => 'verified',
    ]);

    $hiddenPerson = Person::factory()->create([
        'name' => 'Nurul Akma Hidden',
        'status' => 'rejected',
    ]);

    $service = app(PersonSearchService::class);
    $service->syncSpeakerRecord($person);

    expect($service->publicSearchIds($person->name))->toContain((string) $person->id)
        ->not->toContain((string) $hiddenPerson->id);
});

it('keeps token-order-insensitive speaker search when the database driver is configured', function () {
    config()->set('scout.driver', 'database');

    $person = Person::factory()->create([
        'name' => 'Nurul Akma',
        'status' => 'verified',
    ]);

    $service = app(PersonSearchService::class);
    $service->syncSpeakerRecord($person);

    expect($service->publicSearchIds($person->name))->toContain((string) $person->id);
});

it('keeps local fuzzy speaker search when the database driver is configured', function () {
    config()->set('scout.driver', 'database');

    $person = Person::factory()->create([
        'name' => 'Samad Al-Bakri',
        'status' => 'verified',
    ]);

    $hiddenPerson = Person::factory()->create([
        'name' => 'Samad Hidden',
        'status' => 'rejected',
    ]);

    $service = app(PersonSearchService::class);
    $service->syncSpeakerRecord($person);

    expect($service->publicFuzzySearchIds('Smad'))->toContain((string) $person->id)
        ->not->toContain((string) $hiddenPerson->id);
});

it('uses scout database search for institutions when the database driver is configured', function () {
    config()->set('scout.driver', 'database');

    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'nickname' => 'Masjid Biru',
        'description' => 'Pusat komuniti',
        'status' => 'verified',
    ]);

    $hiddenInstitution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Hidden',
        'nickname' => 'Masjid Biru',
        'description' => 'Pusat komuniti',
        'status' => 'rejected',
    ]);

    $service = app(InstitutionSearchService::class);

    expect($service->publicSearchIds('Masjid Biru'))->toContain((string) $institution->id)
        ->not->toContain((string) $hiddenInstitution->id);
});

it('keeps split-token institution search when the database driver is configured', function () {
    config()->set('scout.driver', 'database');

    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'nickname' => null,
        'description' => 'Pusat komuniti',
        'status' => 'verified',
    ]);

    $service = app(InstitutionSearchService::class);

    expect($service->publicSearchIds('Sultan Aziz'))->toContain((string) $institution->id);
});

it('keeps local fuzzy institution search when the database driver is configured', function () {
    config()->set('scout.driver', 'database');

    $institution = Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'description' => 'Kuliah dan komuniti',
        'status' => 'verified',
    ]);

    $hiddenInstitution = Institution::factory()->create([
        'name' => 'Masjid Hidden',
        'description' => 'Kuliah dan komuniti',
        'status' => 'rejected',
    ]);

    $service = app(InstitutionSearchService::class);

    expect($service->publicFuzzySearchIds('Hidayh'))->toContain((string) $institution->id)
        ->not->toContain((string) $hiddenInstitution->id);
});

it('resolves the same speaker ids for public and scoped search flows when the scope matches', function () {
    config()->set('scout.driver', 'collection');

    $person = Person::factory()->create([
        'name' => 'Aisyah Binti Hassan',
        'status' => 'verified',
    ]);

    Person::factory()->create([
        'name' => 'Aisyah Hidden',
        'status' => 'rejected',
    ]);

    $service = app(PersonSearchService::class);
    $service->syncSpeakerRecord($person);

    $publicIds = $service->resolvedPublicSearchIds('Aisyh');
    $scopedIds = $service->scopedSearchIds(
        Person::query()->active()->where('status', 'verified'),
        'Aisyh',
    );

    expect($publicIds)->toBe($scopedIds)
        ->toContain((string) $person->id);
});

it('resolves the same institution ids for public and scoped search flows when the scope matches', function () {
    config()->set('scout.driver', 'collection');

    $institution = Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'description' => 'Kuliah dan komuniti',
        'status' => 'verified',
    ]);

    Institution::factory()->create([
        'name' => 'Masjid Hidden',
        'description' => 'Kuliah dan komuniti',
        'status' => 'rejected',
    ]);

    $service = app(InstitutionSearchService::class);

    $publicIds = $service->resolvedPublicSearchIds('Hidayh');
    $scopedIds = $service->scopedSearchIds(
        Institution::query()->active()->where('status', 'verified'),
        'Hidayh',
    );

    expect($publicIds)->toBe($scopedIds)
        ->toContain((string) $institution->id);
});

it('resolves the same reference ids for public and scoped search flows when the scope matches', function () {
    config()->set('scout.driver', 'collection');

    $reference = Reference::factory()->create([
        'title' => 'Bulugh al-Maram',
        'author' => 'Imam Contoh',
        'description' => 'Syarahan fiqh dan hadith',
        'status' => 'verified',
    ]);

    Reference::factory()->create([
        'title' => 'Bulugh Hidden',
        'status' => 'rejected',
    ]);

    $service = app(ReferenceSearchService::class);

    $publicIds = $service->resolvedPublicSearchIds('Bulugh al Mram');
    $scopedIds = $service->scopedSearchIds(
        Reference::query()->active()->where('status', 'verified'),
        'Bulugh al Mram',
    );

    expect($publicIds)->toBe($scopedIds)
        ->toContain((string) $reference->id);
});

it('falls back to database reference search when typesense lookup fails', function () {
    $reference = Reference::factory()->create([
        'title' => 'Riyadus Solihin',
        'author' => 'Imam Nawawi',
        'description' => 'Himpunan hadith',
        'slug' => 'riyadus-solihin',
        'status' => 'verified',
    ]);

    $service = new class extends ReferenceSearchService
    {
        protected function shouldUseTypesenseSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    };

    expect($service->publicSearchIds('himpunan hadith'))->toContain((string) $reference->id);
});

it('falls back to database reference fuzzy search when typesense lookup fails', function () {
    $reference = Reference::factory()->create([
        'title' => 'Bulugh al-Maram',
        'status' => 'verified',
    ]);

    $service = new class extends ReferenceSearchService
    {
        protected function shouldUseTypesenseSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    };

    expect($service->publicFuzzySearchIds('Bulugh al Mram'))->toContain((string) $reference->id);
});

it('keeps split-token reference search when the database driver is configured', function () {
    config()->set('scout.driver', 'database');

    $reference = Reference::factory()->create([
        'title' => 'Bulugh al-Maram',
        'author' => 'Ibn Hajar',
        'status' => 'verified',
    ]);

    $service = app(ReferenceSearchService::class);

    expect($service->publicSearchIds('Bulugh Maram'))->toContain((string) $reference->id);
});
