<?php

declare(strict_types=1);

use App\Features\Institutions\Models\Institution;
use App\Platform\Search\PublicDiscovery;
use App\Shared\Discovery\PublicDiscoveryAdapter;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

it('slices ordered ids before hydrating without emitting CASE WHEN SQL', function (): void {
    $first = Institution::factory()->create(['name' => 'Alpha Masjid', 'status' => 'verified']);
    $second = Institution::factory()->create(['name' => 'Beta Masjid', 'status' => 'verified']);
    $third = Institution::factory()->create(['name' => 'Gamma Masjid', 'status' => 'verified']);
    $hidden = Institution::factory()->create(['name' => 'Hidden Masjid', 'status' => 'rejected']);

    $adapter = new class([(string) $third->id, (string) $hidden->id, (string) $first->id, (string) $second->id]) implements PublicDiscoveryAdapter
    {
        /** @param list<string> $ids */
        public function __construct(private readonly array $ids) {}

        public function publicSearchIds(string $search): array
        {
            return $this->ids;
        }

        public function publicFuzzySearchIds(string $search): array
        {
            return [];
        }
    };

    $request = Request::create('/api/v1/institutions', 'GET', ['search' => 'Masjid', 'page' => '2']);
    Paginator::currentPathResolver(static fn (): string => '/api/v1/institutions');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $paginator = app(PublicDiscovery::class)->paginate(
        request: $request,
        search: 'Masjid',
        perPage: 2,
        base: Institution::query()->where('status', 'verified'),
        directBase: Institution::query()->where('status', 'verified'),
        adapter: $adapter,
        idColumn: 'institutions.id',
        preserveDirectEngineOrder: true,
    );

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($paginator->total())->toBe(3)
        ->and($paginator->currentPage())->toBe(2)
        ->and($paginator->getPageName())->toBe('page')
        ->and($paginator->path())->toBe('/api/v1/institutions')
        ->and($paginator->url(1))->toContain('search=Masjid')
        ->and(collect($paginator->items())->pluck('id')->all())->toBe([(string) $second->id])
        ->and($queries->filter(static fn (string $sql): bool => str_contains(strtolower($sql), 'case ')))->toBeEmpty();
});
