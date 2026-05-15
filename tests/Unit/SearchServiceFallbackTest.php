<?php

use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\ReferenceSearchService;
use App\Support\Search\SpeakerSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('falls back to the local speaker search index when typesense lookup fails', function () {
    $speaker = Speaker::factory()->create([
        'name' => 'Nurul Akma',
        'honorific' => null,
        'pre_nominal' => ['ustazah'],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'verified',
        'is_active' => true,
    ]);

    $baseService = app(SpeakerSearchService::class);
    $baseService->syncSpeakerRecord($speaker);

    $service = new class extends SpeakerSearchService
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

    $ids = $service->publicSearchIds('ustazah');
    $queryIds = $service->applyIndexedSearch(Speaker::query()->where('status', 'verified'), 'ustazah')
        ->pluck('speakers.id')
        ->map(static fn (mixed $id): string => (string) $id)
        ->all();

    expect($ids)->toContain((string) $speaker->id)
        ->and($queryIds)->toContain((string) $speaker->id);
});

it('falls back to local speaker fuzzy search when typesense lookup fails', function () {
    $speaker = Speaker::factory()->create([
        'name' => 'Samad Al-Bakri',
        'honorific' => null,
        'pre_nominal' => [],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'verified',
        'is_active' => true,
    ]);

    app(SpeakerSearchService::class)->syncSpeakerRecord($speaker);

    $service = new class extends SpeakerSearchService
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

    expect($service->publicFuzzySearchIds('Smad'))->toContain((string) $speaker->id);
});

it('keeps transposed speaker typos reachable through fallback candidate filtering', function () {
    $speaker = Speaker::factory()->create([
        'name' => 'Ahmad Fauzi',
        'honorific' => null,
        'pre_nominal' => [],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'verified',
        'is_active' => true,
    ]);

    app(SpeakerSearchService::class)->syncSpeakerRecord($speaker);

    $service = new class extends SpeakerSearchService
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

    expect($service->publicFuzzySearchIds('Ahmda'))->toContain((string) $speaker->id);
});

it('keeps exact speaker fuzzy matches inside the capped fallback candidate set', function () {
    foreach (range(1, 5) as $index) {
        Speaker::factory()->create([
            'name' => "Samadx Alpha {$index}",
            'honorific' => null,
            'pre_nominal' => [],
            'post_nominal' => [],
            'qualifications' => [],
            'status' => 'verified',
            'is_active' => true,
        ]);
    }

    $exactSpeaker = Speaker::factory()->create([
        'name' => 'Samadx',
        'honorific' => null,
        'pre_nominal' => [],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'verified',
        'is_active' => true,
    ]);

    $service = new class extends SpeakerSearchService
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

    expect($service->publicFuzzySearchIds('Samadx'))->toContain((string) $exactSpeaker->id);
});

it('falls back to database institution search when typesense lookup fails', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'nickname' => 'Masjid Biru',
        'description' => 'Pusat komuniti',
        'status' => 'verified',
        'is_active' => true,
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
        'is_active' => true,
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
        'is_active' => true,
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
            'is_active' => true,
        ]);
    }

    $exactInstitution = Institution::factory()->create([
        'name' => 'Samadx',
        'description' => 'Kuliah dan komuniti',
        'status' => 'verified',
        'is_active' => true,
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

it('uses scout database search for speakers when the database driver is configured', function () {
    config()->set('scout.driver', 'database');

    $speaker = Speaker::factory()->create([
        'name' => 'Nurul Akma',
        'honorific' => null,
        'pre_nominal' => ['ustazah'],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'verified',
        'is_active' => true,
    ]);

    $hiddenSpeaker = Speaker::factory()->create([
        'name' => 'Nurul Akma Hidden',
        'honorific' => null,
        'pre_nominal' => ['ustazah'],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'rejected',
        'is_active' => true,
    ]);

    $service = app(SpeakerSearchService::class);

    expect($service->publicSearchIds('ustazah'))->toContain((string) $speaker->id)
        ->not->toContain((string) $hiddenSpeaker->id);
});

it('keeps token-order-insensitive speaker search when the database driver is configured', function () {
    config()->set('scout.driver', 'database');

    $speaker = Speaker::factory()->create([
        'name' => 'Nurul Akma',
        'honorific' => null,
        'pre_nominal' => ['ustazah'],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'verified',
        'is_active' => true,
    ]);

    $service = app(SpeakerSearchService::class);

    expect($service->publicSearchIds('ustazah nurul'))->toContain((string) $speaker->id);
});

it('keeps local fuzzy speaker search when the database driver is configured', function () {
    config()->set('scout.driver', 'database');

    $speaker = Speaker::factory()->create([
        'name' => 'Samad Al-Bakri',
        'honorific' => null,
        'pre_nominal' => [],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'verified',
        'is_active' => true,
    ]);

    $hiddenSpeaker = Speaker::factory()->create([
        'name' => 'Samad Hidden',
        'honorific' => null,
        'pre_nominal' => [],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'rejected',
        'is_active' => true,
    ]);

    $service = app(SpeakerSearchService::class);

    expect($service->publicFuzzySearchIds('Smad'))->toContain((string) $speaker->id)
        ->not->toContain((string) $hiddenSpeaker->id);
});

it('uses scout database search for institutions when the database driver is configured', function () {
    config()->set('scout.driver', 'database');

    $institution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'nickname' => 'Masjid Biru',
        'description' => 'Pusat komuniti',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $hiddenInstitution = Institution::factory()->create([
        'name' => 'Masjid Sultan Salahuddin Hidden',
        'nickname' => 'Masjid Biru',
        'description' => 'Pusat komuniti',
        'status' => 'rejected',
        'is_active' => true,
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
        'is_active' => true,
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
        'is_active' => true,
    ]);

    $hiddenInstitution = Institution::factory()->create([
        'name' => 'Masjid Hidden',
        'description' => 'Kuliah dan komuniti',
        'status' => 'rejected',
        'is_active' => true,
    ]);

    $service = app(InstitutionSearchService::class);

    expect($service->publicFuzzySearchIds('Hidayh'))->toContain((string) $institution->id)
        ->not->toContain((string) $hiddenInstitution->id);
});

it('resolves the same speaker ids for public and scoped search flows when the scope matches', function () {
    config()->set('scout.driver', 'collection');

    $speaker = Speaker::factory()->create([
        'name' => 'Aisyah Binti Hassan',
        'honorific' => null,
        'pre_nominal' => [],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'verified',
        'is_active' => true,
    ]);

    Speaker::factory()->create([
        'name' => 'Aisyah Hidden',
        'honorific' => null,
        'pre_nominal' => [],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'rejected',
        'is_active' => true,
    ]);

    $service = app(SpeakerSearchService::class);
    $service->syncSpeakerRecord($speaker);

    $publicIds = $service->resolvedPublicSearchIds('Aisyh');
    $scopedIds = $service->scopedSearchIds(
        Speaker::query()->active()->where('status', 'verified'),
        'Aisyh',
    );

    expect($publicIds)->toBe($scopedIds)
        ->toContain((string) $speaker->id);
});

it('resolves the same institution ids for public and scoped search flows when the scope matches', function () {
    config()->set('scout.driver', 'collection');

    $institution = Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'description' => 'Kuliah dan komuniti',
        'status' => 'verified',
        'is_active' => true,
    ]);

    Institution::factory()->create([
        'name' => 'Masjid Hidden',
        'description' => 'Kuliah dan komuniti',
        'status' => 'rejected',
        'is_active' => true,
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
        'is_active' => true,
    ]);

    Reference::factory()->create([
        'title' => 'Bulugh Hidden',
        'status' => 'rejected',
        'is_active' => true,
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
        'is_active' => true,
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
        'is_active' => true,
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
        'is_active' => true,
    ]);

    $service = app(ReferenceSearchService::class);

    expect($service->publicSearchIds('Bulugh Maram'))->toContain((string) $reference->id);
});
