<?php

declare(strict_types=1);

// Canonical primary-contact uniqueness proof on real drivers.
//
// The original contact_methods migration enforces at most one primary per
// non-null (contactable_type, contactable_id, type, purpose) via a partial
// unique index on PGSQL/SQLite, a MySQL 8 functional unique index on MySQL,
// and a nullable VIRTUAL SHA2 hash column with an ordinary unique index on
// MariaDB. These tests prove the constraint through raw rows under the ACTUAL
// original migration on worker-namespaced databases built from the current
// original migrations on the isolated PostgreSQL 17 and MariaDB 11.4
// fixtures. Raw inserts bypass the ContactMethod model demotion so the
// database backstop itself must reject the second primary. If the owning
// migration fix were removed, the MariaDB duplicate insert would succeed and
// these tests would fail.
//
// Guarded test-only worker databases: loopback isolated ports/databases
// only. Never the live application database (ilmu360 / 127.0.0.1:5432).

use AIArmada\Contacting\Models\ContactMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\ShareTracking\Wp1RealDriverHarness;

function wp1ContactUniquenessCase(string $driver): void
{
    $database = Wp1RealDriverHarness::createWorkerDatabase($driver, 'contactuniq');
    $previous = Wp1RealDriverHarness::useWorkerDatabase($driver, $database);

    try {
        Wp1RealDriverHarness::migrateWorkerDatabase($driver);

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

        // MariaDB hash semantics: qualifying primary has a 64-char hex hash,
        // non-qualifying rows stay null. PGSQL has no hash column.
        if ($driver === 'mariadb') {
            $primaryHash = DB::table($table)->where('id', $firstPrimaryId)->value('primary_unique_hash');
            $nonPrimaryHash = DB::table($table)->where('value', 'nonprimary-one@example.test')->value('primary_unique_hash');
            $globalHash = DB::table($table)->where('value', 'global-one@example.test')->value('primary_unique_hash');

            expect($primaryHash)->toBeString()->and((string) $primaryHash)->toMatch('/^[0-9a-f]{64}$/')
                ->and($nonPrimaryHash)->toBeNull()
                ->and($globalHash)->toBeNull();
        }

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
        expect($fresh->getFillable())->not->toContain('primary_unique_hash')
            ->and($fresh->getHidden())->toContain('primary_unique_hash')
            ->and($fresh->toArray())->not->toHaveKey('primary_unique_hash');

        expect((new ContactMethod)->isFillable('primary_unique_hash'))->toBeFalse();

        $massAssignmentBlocked = false;

        try {
            new ContactMethod(['primary_unique_hash' => 'injected']);
        } catch (MassAssignmentException) {
            $massAssignmentBlocked = true;
        }

        expect($massAssignmentBlocked)->toBeTrue('DB-only hash must reject mass assignment.');
    } finally {
        Wp1RealDriverHarness::restoreDatabase($previous);
        Wp1RealDriverHarness::dropWorkerDatabase($driver, $database);
    }
}

it('enforces primary-contact uniqueness on isolated PostgreSQL', function (): void {
    wp1ContactUniquenessCase('pgsql');
});

it('enforces primary-contact uniqueness on isolated MariaDB', function (): void {
    wp1ContactUniquenessCase('mariadb');
});
