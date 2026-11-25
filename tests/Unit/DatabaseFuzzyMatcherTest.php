<?php

declare(strict_types=1);

use App\Features\Institutions\Models\Institution;
use App\Platform\Search\DatabaseFuzzyMatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('normalizes tokens, omission variants, and subsequence patterns', function (): void {
    $matcher = new DatabaseFuzzyMatcher;

    expect($matcher->normalize('  Masjid   Al-Hidayah! '))->toBe('masjid al hidayah')
        ->and($matcher->normalize('   '))->toBeNull()
        ->and($matcher->tokens('masjid a al hidayah', 2))->toBe(['masjid', 'al', 'hidayah'])
        ->and($matcher->omissionVariants('ahmad'))->toBe(['hmad', 'amad', 'ahad', 'ahmd', 'ahma'])
        ->and($matcher->subsequencePattern('smad'))->toBe('%s%m%a%d%');

    $patterns = $matcher->candidatePatterns('ahmad');
    expect($patterns)->toContain('%a%h%m%a%d%')
        ->toContain('%a%h%m%d%');
});

it('scores candidates using FuzzySearchPolicy and optional short-query token guards', function (): void {
    $matcher = new DatabaseFuzzyMatcher;

    expect($matcher->bestScore('hidayh', ['Masjid Al Hidayah'], 2))->toBeGreaterThanOrEqual(0.80)
        ->and($matcher->bestScore('ali', ['Zulkifli Mohamad Al-Bakri'], null, enforceShortQuerySubstringOnTokens: true))
        ->toBe(0.0)
        ->and($matcher->bestScore('ali', ['Ahmad Bin Ali'], null, enforceShortQuerySubstringOnTokens: true))
        ->toBe(1.0);
});

it('applies LIKE candidate patterns on non-postgres drivers', function (): void {
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
    $matcher = new DatabaseFuzzyMatcher;

    $query = $matcher->applyColumnsFilter(
        Institution::on('sqlite'),
        ['institutions.name'],
        'hidayh',
    );

    expect(strtolower($query->toSql()))->toContain('like')
        ->and(strtolower($query->toSql()))->not->toContain('word_similarity');
});

it('applies pg_trgm similarity predicates on pgsql connections', function (): void {
    config()->set('discovery.search.pg_trgm_threshold', 0.31);

    $mockConnection = Mockery::mock(Connection::class);
    $mockConnection->shouldReceive('getDriverName')->andReturn('pgsql');
    $mockConnection->shouldReceive('getTablePrefix')->andReturn('');
    $mockConnection->shouldReceive('getQueryGrammar')->andReturn(new PostgresGrammar($mockConnection));
    $mockConnection->shouldReceive('getPostProcessor')->andReturn(new Illuminate\Database\Query\Processors\PostgresProcessor);
    $mockConnection->shouldReceive('query')->andReturnUsing(
        static fn () => new Illuminate\Database\Query\Builder(
            $mockConnection,
            new PostgresGrammar($mockConnection),
            new Illuminate\Database\Query\Processors\PostgresProcessor,
        ),
    );

    $matcher = new DatabaseFuzzyMatcher;
    $baseQuery = new Illuminate\Database\Eloquent\Builder(
        new Illuminate\Database\Query\Builder(
            $mockConnection,
            new PostgresGrammar($mockConnection),
            new Illuminate\Database\Query\Processors\PostgresProcessor,
        ),
    );
    $baseQuery->setModel(new Institution);

    $query = $matcher->applyColumnsFilter($baseQuery, ['institutions.name'], 'hidayh');

    expect($query->toSql())->toContain('% ?')
        ->and($query->toSql())->toContain('word_similarity(?, lower(coalesce("institutions"."name", \'\'))) >= ?')
        ->and($query->getBindings())->toBe(['hidayh', 'hidayh', 0.31]);
});
