<?php

declare(strict_types=1);

// Canonical vendor-schema proof for the W2 deletion-boundary worker set.
//
// Six required original migrations could not run on MariaDB 11.4 as
// written (partial-index syntax, explicit driver rejection, or 1071 key
// length). Each now carries a faithful MariaDB-only spelling: same
// canonical index names (except two assist indexes whose generated names
// exceed MariaDB's 64-character identifier limit), same columns, same
// uniqueness, no truncation, no omitted index. Every other driver takes
// byte-identical branches to before.
//
// This suite runs those six current originals on both real engines and
// proves index presence plus meaningful uniqueness/insert behavior.
// Guarded worker databases only (share_rewards_test_wp3_*).

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\ShareTracking\Wp1RealDriverHarness;
use Tests\Feature\ShareTracking\Wp3RealDriverHarness;
use Tests\TestCase;

function wp3VendorSchemaWorker(string $driver): array
{
    $database = Wp3RealDriverHarness::createWorkerDatabase($driver, 'vendorschema');
    $previous = Wp1RealDriverHarness::useWorkerDatabase($driver, $database);

    $files = [
        base_path('vendor/aiarmada/persons/database/migrations/2000_01_01_000001_create_persons_table.php'),
        base_path('vendor/aiarmada/persons/database/migrations/2000_01_01_000009_create_affiliations_table.php'),
        base_path('vendor/aiarmada/references/database/migrations/2000_01_01_000001_create_references_table.php'),
        base_path('vendor/aiarmada/engagement/database/migrations/2000_06_01_000001_create_engagement_follows_table.php'),
        base_path('vendor/aiarmada/engagement/database/migrations/2000_06_01_000005_create_engagement_responses_table.php'),
        base_path('vendor/aiarmada/communications/database/migrations/2000_01_01_000011_create_communication_preferences_table.php'),
    ];

    foreach ($files as $file) {
        expect($file)->toBeFile();

        $migration = require $file;

        // Every original must run in full on both engines: nothing
        // tolerated, swallowed, or hand-built.
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

    return [$database, $previous];
}

function wp3VendorSchemaTeardown(string $driver, string $database, string $previous): void
{
    Wp1RealDriverHarness::restoreDatabase($previous);
    Wp1RealDriverHarness::dropWorkerDatabase($driver, $database);
}

/**
 * @return array<string, array{unique: bool, columns: list<string>}>
 */
function wp3VendorSchemaIndexes(string $driver, string $table): array
{
    if ($driver === 'pgsql') {
        $rows = DB::select(
            "SELECT indexname AS name, indexdef AS def FROM pg_indexes WHERE schemaname = 'public' AND tablename = ?",
            [$table]
        );

        $out = [];

        foreach ($rows as $row) {
            $def = (string) $row->def;

            preg_match('/\((.+)\)/', $def, $matches);

            $columns = array_map(
                static fn (string $part): string => trim($part, ' "'),
                explode(',', (string) ($matches[1] ?? ''))
            );

            $out[(string) $row->name] = [
                'unique' => str_contains($def, 'UNIQUE'),
                'columns' => $columns,
            ];
        }

        return $out;
    }

    $rows = DB::select(
        'SELECT INDEX_NAME AS name, COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS position
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         ORDER BY name, position',
        [$table]
    );

    $out = [];

    foreach ($rows as $row) {
        $name = (string) $row->name;

        $out[$name] ??= ['unique' => true, 'columns' => []];
        $out[$name]['unique'] = $out[$name]['unique'] && (int) $row->non_unique === 0;
        $out[$name]['columns'][] = (string) $row->column_name;
    }

    return $out;
}

function wp3VendorExpectsUniqueViolation(callable $insert): void
{
    try {
        $insert();

        expect(false)->toBeTrue('A duplicate row must violate its unique index.');
    } catch (QueryException $exception) {
        expect((string) ($exception->errorInfo[0] ?? $exception->getCode()))->toBeIn(['23000', '23505']);
    }
}

function wp3VendorExpectsInvisibleIdentity(string $table, string $column): void
{
    // The MariaDB identity column is a virtual generated DB-only
    // backstop: present for the unique index, invisible to SELECT *,
    // so it never leaks into model attributes or API shapes.
    $extra = (string) DB::selectOne(
        'SELECT EXTRA AS extra FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    )->extra;

    expect($extra)->toContain('VIRTUAL GENERATED')
        ->and($extra)->toContain('INVISIBLE');

    $starKeys = array_keys((array) DB::selectOne("SELECT * FROM `{$table}` LIMIT 1"));

    expect($starKeys)->not->toContain($column);
}

function wp3VendorSchemaCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp3VendorSchemaWorker($driver);

    try {
        $follows = (string) config('engagement.database.tables.follows', 'follows');
        $responses = (string) config('engagement.database.tables.responses', 'responses');

        // Persons: one canonical slug unique; NULL slugs never collide.
        $persons = wp3VendorSchemaIndexes($driver, 'persons');

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
        $affiliations = wp3VendorSchemaIndexes($driver, 'affiliations');

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

        if ($driver === 'mariadb') {
            wp3VendorExpectsInvisibleIdentity('affiliations', 'primary_unique_identity');
        }

        // References: separate owner-scoped and global slug uniques.
        $references = wp3VendorSchemaIndexes($driver, 'references');

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

        if ($driver === 'mariadb') {
            wp3VendorExpectsInvisibleIdentity('references', 'references_slug_owner_unique_identity');
            wp3VendorExpectsInvisibleIdentity('references', 'references_slug_global_unique_identity');
        }

        // Follows: exact actor/subject/owner uniqueness; NULL owners fine.
        $followIndexes = wp3VendorSchemaIndexes($driver, $follows);

        expect($followIndexes)->toHaveKey('engagement_follows_actor_subject_unique')
            ->and($followIndexes['engagement_follows_actor_subject_unique']['unique'])->toBeTrue();

        if ($driver === 'mariadb') {
            expect($followIndexes)->toHaveKey('engagement_follows_actor_subject_status_idx')
                ->and($followIndexes['engagement_follows_actor_subject_status_idx']['unique'])->toBeFalse()
                ->and($followIndexes['engagement_follows_actor_subject_status_idx']['columns'])
                ->toBe(['follower_type', 'follower_id', 'followable_type', 'followable_id', 'status']);
        }

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

        if ($driver === 'mariadb') {
            wp3VendorExpectsInvisibleIdentity($follows, 'actor_subject_unique_identity');
        }

        // Responses: same actor/subject/owner uniqueness shape.
        $responseIndexes = wp3VendorSchemaIndexes($driver, $responses);

        expect($responseIndexes)->toHaveKey('engagement_responses_actor_subject_unique')
            ->and($responseIndexes['engagement_responses_actor_subject_unique']['unique'])->toBeTrue()
            ->and($responseIndexes)->toHaveKey('engagement_responses_subject_type_status_idx')
            ->and($responseIndexes['engagement_responses_subject_type_status_idx']['unique'])->toBeFalse();

        if ($driver === 'mariadb') {
            expect($responseIndexes['engagement_responses_subject_type_status_idx']['columns'])
                ->toBe(['respondable_type', 'respondable_id', 'response_type', 'status']);
        }

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

        if ($driver === 'mariadb') {
            wp3VendorExpectsInvisibleIdentity($responses, 'actor_subject_unique_identity');
        }

        // Communication preferences: the owner/channel/category assist
        // index exists with its full membership; rows insert cleanly.
        $preferenceIndexes = wp3VendorSchemaIndexes($driver, 'communication_preferences');

        if ($driver === 'mariadb') {
            expect($preferenceIndexes)->toHaveKey('communication_preferences_owner_channel_category_idx')
                ->and($preferenceIndexes['communication_preferences_owner_channel_category_idx']['unique'])->toBeFalse()
                ->and($preferenceIndexes['communication_preferences_owner_channel_category_idx']['columns'])
                ->toBe(['owner_type', 'owner_id', 'channel', 'category']);
        }

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
        wp3VendorSchemaTeardown($driver, $database, $previous);
    }
}

foreach (['pgsql' => 'PostgreSQL 17', 'mariadb' => 'MariaDB 11.4'] as $wp3Driver => $label) {
    it("keeps the required vendor indexes and uniqueness on {$label}", function () use ($wp3Driver): void {
        wp3VendorSchemaCase($this, $wp3Driver);
    });
}
