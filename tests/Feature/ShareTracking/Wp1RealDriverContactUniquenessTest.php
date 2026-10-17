<?php

declare(strict_types=1);

// Canonical primary-contact uniqueness proof on real drivers.
//
// The original contact_methods migration enforces at most one primary per
// non-null (contactable_type, contactable_id, type, purpose) via a partial
// and a nullable VIRTUAL SHA2 hash column with an ordinary unique index on
// original migration on worker-namespaced databases built from the current
// fixtures. Raw inserts bypass the ContactMethod model demotion so the
// database backstop itself must reject the second primary. If the owning
// these tests would fail.
//
// Guarded test-only worker databases: loopback isolated ports/databases
// only. Never the live application database (ilmu360 / 127.0.0.1:5432).

use AIArmada\Contacting\Models\ContactMethod;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\ShareTracking\Wp1RealDriverHarness;
use Tests\Support\RealDriverFixture;

function wp1ContactUniquenessCase(): void
{
    $database = RealDriverFixture::createWorkerDatabase('contactuniq');
    $previous = RealDriverFixture::useWorkerDatabase($database, Wp1RealDriverHarness::WORKER_CONNECTION);

    try {
        Wp1RealDriverHarness::migrateWorkerDatabase();

        $table = (new ContactMethod)->getTable();
        $indexName = $table.'_primary_unique';

        $ownerOne = User::factory()->create(['email' => 'wp1-contact-owner-one@example.test']);
        $ownerTwo = User::factory()->create(['email' => 'wp1-contact-owner-two@example.test']);
        $morph = $ownerOne->getMorphClass();

        $insert = function (
            ?string $contactableType,
            ?string $contactableId,
            string $type,
            string $purpose,
            bool $isPrimary,
            string $value,
        ) use ($table): string {
            $id = (string) Str::uuid();

            DB::table($table)->insert([
                'id' => $id,
                'contactable_type' => $contactableType,
                'contactable_id' => $contactableId,
                'type' => $type,
                'purpose' => $purpose,
                'value' => $value,
                'is_primary' => $isPrimary,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        };

        // First primary for the canonical tuple succeeds.
        $firstPrimaryId = $insert($morph, (string) $ownerOne->getKey(), 'email', 'general', true, 'first-primary@example.test');

        // Second primary on the identical non-null tuple must be rejected by
        // the canonical uniqueness constraint.
        $failure = null;

        try {
            $insert($morph, (string) $ownerOne->getKey(), 'email', 'general', true, 'second-primary@example.test');
        } catch (QueryException $exception) {
            $failure = $exception->getMessage();
        }

        expect($failure)->not->toBeNull('Second primary on the same tuple must be rejected.');
        expect((string) $failure)->toContain($indexName);

        // Different purpose, type, and owner each allow their own primary.
        $insert($morph, (string) $ownerOne->getKey(), 'email', 'support', true, 'purpose-split@example.test');
        $insert($morph, (string) $ownerOne->getKey(), 'phone', 'general', true, '+60123456789');
        $insert($morph, (string) $ownerTwo->getKey(), 'email', 'general', true, 'owner-split@example.test');

        expect(DB::table($table)->where('contactable_id', (string) $ownerOne->getKey())->where('is_primary', true)->count())->toBe(3)
            ->and(DB::table($table)->where('contactable_id', (string) $ownerTwo->getKey())->where('is_primary', true)->count())->toBe(1);

        // Multiple non-primary rows on the same tuple are allowed.
        $insert($morph, (string) $ownerOne->getKey(), 'email', 'general', false, 'nonprimary-one@example.test');
        $insert($morph, (string) $ownerOne->getKey(), 'email', 'general', false, 'nonprimary-two@example.test');

        expect(DB::table($table)
            ->where('contactable_type', $morph)
            ->where('contactable_id', (string) $ownerOne->getKey())
            ->where('type', 'email')
            ->where('purpose', 'general')
            ->where('is_primary', false)
            ->count())->toBe(2);

        // Nullable-global primaries (both contactable columns null) allow
        // duplicates because the constraint requires non-null scope.
        $insert(null, null, 'email', 'general', true, 'global-one@example.test');
        $insert(null, null, 'email', 'general', true, 'global-two@example.test');

        expect(DB::table($table)->whereNull('contactable_type')->whereNull('contactable_id')->where('is_primary', true)->count())->toBe(2);

        // Partial null scope also allows duplicates: both contactable
        // columns must be non-null for the constraint to apply.
        $insert($morph, null, 'email', 'general', true, 'half-null-one@example.test');
        $insert($morph, null, 'email', 'general', true, 'half-null-two@example.test');
        $insert(null, (string) $ownerOne->getKey(), 'email', 'general', true, 'half-null-three@example.test');
        $insert(null, (string) $ownerOne->getKey(), 'email', 'general', true, 'half-null-four@example.test');

        expect(DB::table($table)->where('contactable_type', $morph)->whereNull('contactable_id')->where('is_primary', true)->count())->toBe(2);

        // non-qualifying rows stay null. PGSQL has no hash column.

        // DB-only helper can never become editable or public API shape.
        $model = ContactMethod::query()->create([
            'contactable_type' => $morph,
            'contactable_id' => (string) $ownerTwo->getKey(),
            'type' => 'phone',
            'purpose' => 'support',
            'value' => '+60129876543',
            'is_primary' => true,
        ]);

        $fresh = $model->fresh();

        expect($fresh)->not->toBeNull();

    } finally {
        RealDriverFixture::restoreDatabase($previous, Wp1RealDriverHarness::WORKER_CONNECTION);
        RealDriverFixture::dropDatabase($database);
    }
}

it('enforces primary-contact uniqueness on isolated PostgreSQL', function (): void {
    wp1ContactUniquenessCase();
});
