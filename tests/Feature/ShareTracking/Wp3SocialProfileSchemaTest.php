<?php

declare(strict_types=1);

// W3 canonical social-profile schema proof.
//
// The owning original contacting migration must run in full on PostgreSQL
// with its complete index semantics: the 8-column
// validity index and the conditional primary-unique rule. No swallowed
// migration failure, no omitted index, no global string shortening, no
// lossy uniqueness cast. Guarded worker databases only

use AIArmada\Contacting\Models\SocialProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Feature\ShareTracking\Wp1RealDriverHarness;
use Tests\Feature\ShareTracking\Wp3RealDriverHarness;
use Tests\Support\RealDriverFixture;

/** @return array{0: string, 1: string} */
function wp3SocialSchemaWorker(): array
{
    $database = RealDriverFixture::createWorkerDatabase('socialschema', namePrefix: 'wp3_');
    $previous = RealDriverFixture::useWorkerDatabase($database, Wp1RealDriverHarness::WORKER_CONNECTION);

    Wp3RealDriverHarness::migrateWorkerDatabase();

    Wp3RealDriverHarness::assertWorkerSchema(['users', 'social_profiles']);

    return [$database, $previous];
}

function wp3SocialSchemaTeardown(string $database, string $previous): void
{
    RealDriverFixture::restoreDatabase($previous, Wp1RealDriverHarness::WORKER_CONNECTION);
    RealDriverFixture::dropDatabase($database);
}

function wp3SocialSchemaCase(): void
{
    [$database, $previous] = wp3SocialSchemaWorker();

    try {
        $owner = User::factory()->create(['email' => 'wp3-social-schema@example.test']);
        $socialableType = (new User)->getMorphClass();
        $socialableId = (string) $owner->getKey();

        $indexes = collect(DB::select(
            "SELECT indexname AS name, indexdef AS definition FROM pg_indexes WHERE schemaname = 'public' AND tablename = ?",
            ['social_profiles'],
        ))->keyBy('name');

        expect($indexes->has('social_profiles_socialable_validity_index'))->toBeTrue()
            ->and($indexes->has('social_profiles_primary_unique'))->toBeTrue()
            ->and((string) $indexes->get('social_profiles_socialable_validity_index')->definition)
            ->toContain('socialable_type', 'socialable_id', 'platform', 'purpose', 'is_primary', 'valid_from', 'valid_until', 'sort_order')
            ->and((string) $indexes->get('social_profiles_primary_unique')->definition)
            ->toContain('socialable_type', 'socialable_id', 'platform', 'purpose', 'WHERE', 'is_primary IS TRUE');

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

            Assert::fail('A duplicate primary profile must violate the primary-unique index.');
        } catch (QueryException $exception) {
            expect((string) ($exception->errorInfo[0] ?? $exception->getCode()))->toBe('23505');
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
        // primary for the same scope demotes the first.
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

    } finally {
        wp3SocialSchemaTeardown($database, $previous);
    }
}

$label = 'PostgreSQL 17';
    it("keeps the canonical social-profile indexes and primary uniqueness on {$label}", function (): void {
        wp3SocialSchemaCase();
    });
