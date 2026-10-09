<?php

declare(strict_types=1);

// W3 canonical social-profile schema proof.
//
// The owning original contacting migration must run in full on the
// supported engines with its complete index semantics: the 8-column
// validity index and the conditional primary-unique rule. No swallowed
// migration failure, no omitted index, no global string shortening, no
// lossy uniqueness cast. Guarded worker databases only
// (share_rewards_test_wp3_*, isolated PG 45432 / MariaDB 43306).

use AIArmada\Contacting\Models\SocialProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\ShareTracking\Wp1RealDriverHarness;
use Tests\Feature\ShareTracking\Wp3RealDriverHarness;
use Tests\TestCase;

function wp3SocialSchemaWorker(string $driver): array
{
    $database = Wp3RealDriverHarness::createWorkerDatabase($driver, 'socialschema');
    $previous = Wp1RealDriverHarness::useWorkerDatabase($driver, $database);

    Wp3RealDriverHarness::migrateWorkerDatabase($driver);

    Wp3RealDriverHarness::assertWorkerSchema(['users', 'social_profiles']);

    return [$database, $previous];
}

function wp3SocialSchemaTeardown(string $driver, string $database, string $previous): void
{
    Wp1RealDriverHarness::restoreDatabase($previous);
    Wp1RealDriverHarness::dropWorkerDatabase($driver, $database);
}

function wp3SocialSchemaCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp3SocialSchemaWorker($driver);

    try {
        $owner = User::factory()->create(['email' => 'wp3-social-schema@example.test']);
        $socialableType = (new User)->getMorphClass();
        $socialableId = (string) $owner->getKey();

        if ($driver === 'pgsql') {
            $indexes = collect(DB::select(
                "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'social_profiles'"
            ))->keyBy('indexname');

            expect($indexes->has('social_profiles_socialable_validity_index'))->toBeTrue()
                ->and($indexes->has('social_profiles_primary_unique'))->toBeTrue();

            $validityDef = (string) $indexes->get('social_profiles_socialable_validity_index')->indexdef;

            foreach (['socialable_type', 'socialable_id', 'platform', 'purpose', 'is_primary', 'valid_from', 'valid_until', 'sort_order'] as $column) {
                expect($validityDef)->toContain($column);
            }

            $uniqueDef = (string) $indexes->get('social_profiles_primary_unique')->indexdef;

            expect($uniqueDef)->toContain('UNIQUE')
                ->and($uniqueDef)->toContain('WHERE')
                ->and($uniqueDef)->toContain('is_primary');

            $width = DB::selectOne(
                "SELECT character_maximum_length AS width FROM information_schema.columns
                 WHERE table_name = 'social_profiles' AND column_name = 'platform'"
            )->width;

            expect((int) $width)->toBe(255);
        } else {
            $rows = DB::select(
                'SELECT INDEX_NAME AS name, COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS position
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                 ORDER BY name, position',
                ['social_profiles']
            );

            $byIndex = collect($rows)->groupBy('name');

            expect($byIndex->has('social_profiles_socialable_validity_index'))->toBeTrue()
                ->and($byIndex->has('social_profiles_primary_unique'))->toBeTrue();

            $validityColumns = $byIndex->get('social_profiles_socialable_validity_index')
                ->sortBy('position')
                ->map(static fn (object $row): string => (string) $row->column_name)
                ->values()
                ->all();

            expect($validityColumns)->toBe([
                'socialable_type', 'socialable_id', 'platform', 'purpose',
                'is_primary', 'valid_from', 'valid_until', 'sort_order',
            ]);

            $validityUnique = $byIndex->get('social_profiles_socialable_validity_index')
                ->every(static fn (object $row): bool => (int) $row->non_unique === 1);

            expect($validityUnique)->toBeTrue();

            $primaryRows = $byIndex->get('social_profiles_primary_unique');

            expect($primaryRows->every(static fn (object $row): bool => (int) $row->non_unique === 0))->toBeTrue()
                ->and($primaryRows->map(static fn (object $row): string => (string) $row->column_name)->values()->all())
                ->toBe(['primary_unique_identity']);

            $width = DB::selectOne(
                'SELECT CHARACTER_MAXIMUM_LENGTH AS width FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['social_profiles', 'platform']
            )->width;

            expect((int) $width)->toBe(255);
        }

        // Real model insert: one primary per (socialable, platform, purpose).
        $first = SocialProfile::query()->create([
            'socialable_type' => $socialableType,
            'socialable_id' => $socialableId,
            'platform' => 'facebook',
            'purpose' => 'general',
            'is_primary' => true,
        ]);

        expect($first->is_primary)->toBeTrue();

        // The database backstop rejects a duplicate primary for the same
        // scope. Raw inserts bypass the model's sibling demotion so the
        // unique index itself is exercised.
        $raw = [
            'id' => (string) Str::uuid(),
            'socialable_type' => $socialableType,
            'socialable_id' => $socialableId,
            'platform' => 'facebook',
            'purpose' => 'general',
            'is_primary' => true,
            'is_public' => true,
            'is_verified' => false,
            'sort_order' => 0,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ];

        try {
            DB::table('social_profiles')->insert($raw);

            expect(false)->toBeTrue('A duplicate primary profile must violate the primary-unique index.');
        } catch (QueryException $exception) {
            expect((string) ($exception->errorInfo[0] ?? $exception->getCode()))->toBeIn(['23000', '23505']);
        }

        // Non-primary duplicates never collide.
        DB::table('social_profiles')->insert(array_merge($raw, [
            'id' => (string) Str::uuid(),
            'is_primary' => false,
        ]));
        DB::table('social_profiles')->insert(array_merge($raw, [
            'id' => (string) Str::uuid(),
            'is_primary' => false,
        ]));

        // A different platform scopes a separate primary, through the real
        // model (its sibling demotion is platform-scoped, so no demotion).
        $other = SocialProfile::query()->create([
            'socialable_type' => $socialableType,
            'socialable_id' => $socialableId,
            'platform' => 'instagram',
            'purpose' => 'general',
            'is_primary' => true,
        ]);

        $firstStillPrimary = (bool) SocialProfile::query()->withoutGlobalScopes()
            ->whereKey($first->getKey())->value('is_primary');

        expect($other->is_primary)->toBeTrue()
            ->and($firstStillPrimary)->toBeTrue();

        // Unscoped primaries (NULL socialable) never collide.
        DB::table('social_profiles')->insert(array_merge($raw, [
            'id' => (string) Str::uuid(),
            'socialable_type' => null,
            'socialable_id' => null,
        ]));
        DB::table('social_profiles')->insert(array_merge($raw, [
            'id' => (string) Str::uuid(),
            'socialable_type' => null,
            'socialable_id' => null,
        ]));

        // Package behavior stays intact on the new schema: a second model
        // primary for the same scope demotes the first instead of
        // violating, including across the MariaDB generated column.
        $replacement = SocialProfile::query()->create([
            'socialable_type' => $socialableType,
            'socialable_id' => $socialableId,
            'platform' => 'facebook',
            'purpose' => 'general',
            'is_primary' => true,
        ]);

        $firstDemoted = (bool) SocialProfile::query()->withoutGlobalScopes()
            ->whereKey($first->getKey())->value('is_primary');

        expect($replacement->is_primary)->toBeTrue()
            ->and($firstDemoted)->toBeFalse();

        expect(DB::table('social_profiles')->where('is_primary', true)->count())->toBe(4);

        if ($driver === 'mariadb') {
            // The identity column is a virtual generated DB-only
            // backstop: present for the unique index, invisible to
            // SELECT *, so it never leaks into model attributes.
            $extra = (string) DB::selectOne(
                'SELECT EXTRA AS extra FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['social_profiles', 'primary_unique_identity']
            )->extra;

            expect($extra)->toContain('VIRTUAL GENERATED')
                ->and($extra)->toContain('INVISIBLE');

            $starKeys = array_keys((array) DB::selectOne('SELECT * FROM `social_profiles` LIMIT 1'));

            expect($starKeys)->not->toContain('primary_unique_identity');
        }
    } finally {
        wp3SocialSchemaTeardown($driver, $database, $previous);
    }
}

foreach (['pgsql' => 'PostgreSQL 17', 'mariadb' => 'MariaDB 11.4'] as $wp3Driver => $label) {
    it("keeps the canonical social-profile indexes and primary uniqueness on {$label}", function () use ($wp3Driver): void {
        wp3SocialSchemaCase($this, $wp3Driver);
    });
}
