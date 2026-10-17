<?php

declare(strict_types=1);

// Canonical vendor-schema proof for the W2 deletion-boundary worker set.
// PostgreSQL runs each original migration and enforces its unique indexes.
// Guarded worker databases only (share_rewards_test_wp3_*).

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Feature\ShareTracking\Wp1RealDriverHarness;
use Tests\Feature\ShareTracking\Wp3RealDriverHarness;
use Tests\Support\RealDriverFixture;

/** @return array{0: string, 1: string} */
function wp3VendorSchemaWorker(): array
{
    $database = RealDriverFixture::createWorkerDatabase('vendorschema', namePrefix: 'wp3_');
    $previous = RealDriverFixture::useWorkerDatabase($database, Wp1RealDriverHarness::WORKER_CONNECTION);

    $files = [
        base_path('vendor/aiarmada/persons/database/migrations/2000_01_01_000001_create_persons_table.php'),
        base_path('vendor/aiarmada/persons/database/migrations/2000_01_01_000009_create_affiliations_table.php'),
        base_path('vendor/aiarmada/references/database/migrations/2000_01_01_000001_create_references_table.php'),
        base_path('vendor/aiarmada/engagement/database/migrations/2000_06_01_000001_create_engagement_follows_table.php'),
        base_path('vendor/aiarmada/engagement/database/migrations/2000_06_01_000005_create_engagement_responses_table.php'),
        base_path('vendor/aiarmada/communications/database/migrations/2000_01_01_000011_create_communication_preferences_table.php'),
    ];

    try {
        foreach ($files as $file) {
            expect($file)->toBeFile();

            $migration = require $file;

            // Every original must run in full: nothing is tolerated,
            // swallowed, or hand-built.
            $migration->up();
        }

        Wp3RealDriverHarness::assertWorkerSchema([
            'persons',
            'affiliations',
            'references',
            (string) config('engagement.database.tables.follows', 'follows'),
            (string) config('engagement.database.tables.responses', 'responses'),
            'communication_preferences',
        ]);
    } catch (Throwable $exception) {
        RealDriverFixture::restoreDatabase($previous, Wp1RealDriverHarness::WORKER_CONNECTION);
        RealDriverFixture::dropDatabase($database);

        throw $exception;
    }

    return [$database, $previous];
}

function wp3VendorSchemaTeardown(string $database, string $previous): void
{
    RealDriverFixture::restoreDatabase($previous, Wp1RealDriverHarness::WORKER_CONNECTION);
    RealDriverFixture::dropDatabase($database);
}

/**
 * @return array<string, array{unique: bool, columns: list<string>}>
 */
function wp3VendorSchemaIndexes(string $table): array
{
    $rows = DB::select(
        "SELECT indexname AS name, indexdef AS definition
         FROM pg_indexes
         WHERE schemaname = 'public' AND tablename = ?",
        [$table],
    );

    $out = [];

    foreach ($rows as $row) {
        $name = (string) $row->name;
        $definition = (string) $row->definition;
        preg_match('/\\((.+)\\)/', $definition, $matches);
        $columns = array_map(
            static fn (string $column): string => trim($column, ' "'),
            explode(',', (string) ($matches[1] ?? '')),
        );

        $out[$name] = [
            'unique' => str_contains($definition, 'UNIQUE'),
            'columns' => $columns,
        ];
    }

    return $out;
}

function wp3VendorExpectsUniqueViolation(callable $insert): void
{
    try {
        $insert();

        Assert::fail('A duplicate row must violate its unique index.');
    } catch (QueryException $exception) {
        expect((string) ($exception->errorInfo[0] ?? $exception->getCode()))->toBe('23505');
    }
}

function wp3VendorSchemaCase(): void
{
    [$database, $previous] = wp3VendorSchemaWorker();

    try {
        $follows = (string) config('engagement.database.tables.follows', 'follows');
        $responses = (string) config('engagement.database.tables.responses', 'responses');

        // Persons: one canonical slug unique; NULL slugs never collide.
        $persons = wp3VendorSchemaIndexes('persons');

        expect($persons)->toHaveKey('persons_slug_unique')
            ->and($persons['persons_slug_unique']['unique'])->toBeTrue();

        DB::table('persons')->insert([
            'id' => (string) Str::uuid(), 'name' => 'A', 'slug' => 'w3-person',
            'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]);

        wp3VendorExpectsUniqueViolation(static fn (): mixed => DB::table('persons')->insert([
            'id' => (string) Str::uuid(), 'name' => 'B', 'slug' => 'w3-person',
            'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]));

        DB::table('persons')->insert([
            'id' => (string) Str::uuid(), 'name' => 'C', 'slug' => null,
            'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]);
        DB::table('persons')->insert([
            'id' => (string) Str::uuid(), 'name' => 'D', 'slug' => null,
            'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]);

        // Affiliations: one primary per target; non-primary duplicates fine.
        $affiliations = wp3VendorSchemaIndexes('affiliations');

        expect($affiliations)->toHaveKey('affiliations_primary_unique')
            ->and($affiliations['affiliations_primary_unique']['unique'])->toBeTrue();

        $target = (string) Str::uuid();

        DB::table('affiliations')->insert([
            'id' => (string) Str::uuid(), 'affiliatable_type' => 'user', 'affiliatable_id' => $target,
            'is_primary' => true, 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]);

        wp3VendorExpectsUniqueViolation(static fn (): mixed => DB::table('affiliations')->insert([
            'id' => (string) Str::uuid(), 'affiliatable_type' => 'user', 'affiliatable_id' => $target,
            'is_primary' => true, 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]));

        DB::table('affiliations')->insert([
            'id' => (string) Str::uuid(), 'affiliatable_type' => 'user', 'affiliatable_id' => $target,
            'is_primary' => false, 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]);

        // References: separate owner-scoped and global slug uniques.
        $references = wp3VendorSchemaIndexes('references');

        expect($references)->toHaveKey('references_slug_owner_unique')
            ->and($references)->toHaveKey('references_slug_global_unique')
            ->and($references['references_slug_owner_unique']['unique'])->toBeTrue()
            ->and($references['references_slug_global_unique']['unique'])->toBeTrue();

        $ownerA = (string) Str::uuid();
        $ownerB = (string) Str::uuid();

        $referenceRow = static fn (array $over): array => array_merge([
            'id' => (string) Str::uuid(), 'type' => 'work', 'title' => 'T', 'slug' => 'w3-ref',
            'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ], $over);

        DB::table('references')->insert($referenceRow(['owner_type' => 'user', 'owner_id' => $ownerA]));

        wp3VendorExpectsUniqueViolation(static fn (): mixed => DB::table('references')->insert(
            $referenceRow(['owner_type' => 'user', 'owner_id' => $ownerA])
        ));

        DB::table('references')->insert($referenceRow(['owner_type' => 'user', 'owner_id' => $ownerB]));
        DB::table('references')->insert($referenceRow(['owner_type' => null, 'owner_id' => null, 'slug' => 'w3-global']));

        wp3VendorExpectsUniqueViolation(static fn (): mixed => DB::table('references')->insert(
            $referenceRow(['owner_type' => null, 'owner_id' => null, 'slug' => 'w3-global'])
        ));

        // Follows: exact actor/subject/owner uniqueness; NULL owners fine.
        $followIndexes = wp3VendorSchemaIndexes($follows);

        expect($followIndexes)->toHaveKey('engagement_follows_actor_subject_unique')
            ->and($followIndexes['engagement_follows_actor_subject_unique']['unique'])->toBeTrue();

        $followRow = static fn (array $over): array => array_merge([
            'id' => (string) Str::uuid(), 'follower_type' => 'user', 'follower_id' => (string) Str::uuid(),
            'followable_type' => 'event', 'followable_id' => (string) Str::uuid(), 'status' => 'active',
            'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ], $over);

        $followBase = $followRow(['owner_type' => 'user', 'owner_id' => (string) Str::uuid()]);

        DB::table($follows)->insert($followBase);

        wp3VendorExpectsUniqueViolation(static fn (): mixed => DB::table($follows)->insert(
            array_merge($followBase, ['id' => (string) Str::uuid()])
        ));

        $nullOwner = $followRow(['owner_type' => null, 'owner_id' => null]);

        DB::table($follows)->insert($nullOwner);
        DB::table($follows)->insert(array_merge($nullOwner, ['id' => (string) Str::uuid()]));

        // Responses: same actor/subject/owner uniqueness shape.
        $responseIndexes = wp3VendorSchemaIndexes($responses);

        expect($responseIndexes)->toHaveKey('engagement_responses_actor_subject_unique')
            ->and($responseIndexes['engagement_responses_actor_subject_unique']['unique'])->toBeTrue()
            ->and($responseIndexes)->toHaveKey('engagement_responses_subject_type_status_idx')
            ->and($responseIndexes['engagement_responses_subject_type_status_idx']['unique'])->toBeFalse();

        $responseRow = static fn (array $over): array => array_merge([
            'id' => (string) Str::uuid(), 'responder_type' => 'user', 'responder_id' => (string) Str::uuid(),
            'respondable_type' => 'event', 'respondable_id' => (string) Str::uuid(),
            'response_type' => 'going', 'status' => 'active', 'visibility' => 'public',
            'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ], $over);

        $responseBase = $responseRow(['owner_type' => 'user', 'owner_id' => (string) Str::uuid()]);

        DB::table($responses)->insert($responseBase);

        wp3VendorExpectsUniqueViolation(static fn (): mixed => DB::table($responses)->insert(
            array_merge($responseBase, ['id' => (string) Str::uuid()])
        ));

        $nullResponseOwner = $responseRow(['owner_type' => null, 'owner_id' => null]);

        DB::table($responses)->insert($nullResponseOwner);
        DB::table($responses)->insert(array_merge($nullResponseOwner, ['id' => (string) Str::uuid()]));

        // Communication preferences: the owner/channel/category assist
        // index exists with its full membership; rows insert cleanly.
        $preferenceIndexes = wp3VendorSchemaIndexes('communication_preferences');
        expect($preferenceIndexes)
            ->toContain([
                'unique' => false,
                'columns' => ['owner_type', 'owner_id', 'channel', 'category'],
            ]);

        DB::table('communication_preferences')->insert([
            'id' => (string) Str::uuid(), 'recipient_type' => 'user', 'recipient_id' => (string) Str::uuid(),
            'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]);
        DB::table('communication_preferences')->insert([
            'id' => (string) Str::uuid(), 'recipient_type' => 'user', 'recipient_id' => (string) Str::uuid(),
            'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]);

        expect(DB::table('communication_preferences')->count())->toBe(2);
    } finally {
        wp3VendorSchemaTeardown($database, $previous);
    }
}

$label = 'PostgreSQL 17';
it("keeps the required vendor indexes and uniqueness on {$label}", function (): void {
    wp3VendorSchemaCase();
});
