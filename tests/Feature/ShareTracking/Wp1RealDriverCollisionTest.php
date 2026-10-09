<?php

declare(strict_types=1);

// Real-driver proof for the canonical member-creation collision path.
//
// AffiliatesShareTrackingService::createCanonicalAffiliateForUser runs the
// insert in a nested DB::transaction (savepoint) so a unique violation rolls
// back to the savepoint only and the caller's outer transaction stays usable
// for the winner requery. These tests prove that construct through the
// actual public workflow - ShareTrackingService::createOrReuseLink with
// persisted User fixtures - on worker-namespaced databases built from the
// current original migrations on the isolated PostgreSQL 17 and MariaDB
// 11.4 fixtures. If the application creation fix were deleted, the
// collision case would poison the PostgreSQL outer transaction (25P02)
// and the race case would fail or duplicate: no copied closure can pass
// these tests.
//
// Guarded test-only worker databases: loopback isolated ports/databases
// only. Never the live application database (ilmu360 / 127.0.0.1:5432).

use AIArmada\Affiliates\Enums\CommissionType;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\States\Active;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareTrackingProfile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ShareTracking\Wp1RealDriverHarness;
use Tests\TestCase;

function wp1RealCollisionCase(TestCase $testCase, string $driver): void
{
    $database = Wp1RealDriverHarness::createWorkerDatabase($driver, 'collision');
    $previous = Wp1RealDriverHarness::useWorkerDatabase($driver, $database);

    try {
        Wp1RealDriverHarness::migrateWorkerDatabase($driver);

        $service = app(ShareTrackingService::class);
        $pageUrl = route('events.index');

        $victim = User::factory()->create(['email' => 'wp1-race-victim@example.test']);
        $squatterOwner = User::factory()->create(['email' => 'wp1-race-squatter@example.test']);
        $cleanUser = User::factory()->create(['email' => 'wp1-race-clean@example.test']);

        // Learn the victim's canonical code through the real service, then
        // remove the temporary rows so a commercial squatter can occupy it.
        // No hash formula is duplicated: the code is read back from the
        // persisted canonical row.
        $service->createOrReuseLink($victim, $pageUrl, 'Majlis', 'web');

        $temporary = app(AffiliatesShareTrackingService::class)->findAffiliateForUser($victim);

        expect($temporary)->not->toBeNull();

        $canonicalCode = (string) $temporary->code;

        AffiliateLink::query()->where('affiliate_id', $temporary->id)->delete();
        Affiliate::query()->whereKey($temporary->id)->delete();

        expect(app(AffiliatesShareTrackingService::class)->findAffiliateForUser($victim))->toBeNull();

        $squatter = new Affiliate([
            'code' => $canonicalCode,
            'name' => 'Squatter Commercial Account',
            'status' => Active::class,
            'commission_type' => CommissionType::Percentage->value,
            'commission_rate' => 1500,
            'currency' => 'MYR',
            'metadata' => ['commercial_account' => true],
            'activated_at' => now(),
        ]);
        $squatter->assignOwner($squatterOwner);
        $squatter->save();

        // DB-raw snapshot: both sides come from the same select so formats
        // and column order are directly comparable.
        $squatterBefore = $squatter->fresh()->getAttributes();
        $failure = null;

        DB::transaction(function () use ($service, $victim, $cleanUser, $pageUrl, $canonicalCode, &$failure): void {
            try {
                $service->createOrReuseLink($victim, $pageUrl, 'Majlis', 'web');
            } catch (InvalidArgumentException $exception) {
                $failure = $exception->getMessage();
            }

            // The caller's outer transaction stays usable after the
            // rolled-back nested savepoint: ordinary reads still work and
            // a second real creator request (with its own nested
            // transactions) succeeds inside the same outer transaction.
            expect(Affiliate::query()->where('code', $canonicalCode)->exists())->toBeTrue();

            $cleanLink = $service->createOrReuseLink($cleanUser, $pageUrl, 'Majlis', 'web');

            expect($cleanLink->id)->not->toBe('');
        });

        expect($failure)->toBe('A share profile for this account conflicts with an existing profile identity.')
            ->and(app(AffiliatesShareTrackingService::class)->findAffiliateForUser($victim))->toBeNull();

        // No suffixed duplicate was invented for the victim.
        expect(Affiliate::query()
            ->where('owner_type', $victim->getMorphClass())
            ->where('owner_id', $victim->getKey())
            ->count())->toBe(0);

        // The existing commercial collision is untouched.
        $squatter->refresh();

        expect($squatter->getAttributes())->toBe($squatterBefore);

        // The clean user's canonical work committed through the same outer
        // transaction that survived the collision.
        $cleanProfile = app(AffiliatesShareTrackingService::class)->findAffiliateForUser($cleanUser);

        expect($cleanProfile)->not->toBeNull()
            ->and(ShareTrackingProfile::isMarkedMemberFor($cleanProfile, $cleanUser))->toBeTrue();
    } finally {
        Wp1RealDriverHarness::restoreDatabase($previous);
        Wp1RealDriverHarness::dropWorkerDatabase($driver, $database);
    }
}

function wp1RealRaceCase(TestCase $testCase, string $driver): void
{
    $database = Wp1RealDriverHarness::createWorkerDatabase($driver, 'race');
    $previous = Wp1RealDriverHarness::useWorkerDatabase($driver, $database);

    try {
        Wp1RealDriverHarness::migrateWorkerDatabase($driver);

        $user = User::factory()->create(['email' => 'wp1-race-contended@example.test']);
        $pageUrl = route('events.index');

        // Two independent OS processes with independent connections run
        // the true canonical public workflow for the same persisted user
        // behind a start barrier, so the creator requests genuinely
        // overlap. The proof is the asserted end state, never timing.
        [$first, $second] = Wp1RealDriverHarness::runLinkCreationRace([
            'driver' => $driver,
            'database' => $database,
            'user_id' => (string) $user->getKey(),
            'url' => $pageUrl,
            'title' => 'Majlis',
            'app_url' => (string) config('app.url'),
        ]);

        expect((string) $first['link_id'])->toBe((string) $second['link_id']);

        $profiles = Affiliate::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->get();

        // One winner, no random-suffix duplicate.
        expect($profiles)->toHaveCount(1);

        $winner = $profiles->first();

        expect(ShareTrackingProfile::isMarkedMemberFor($winner, $user))->toBeTrue()
            ->and(AffiliateLink::query()->where('affiliate_id', $winner->id)->count())->toBe(1)
            ->and((string) AffiliateLink::query()->where('affiliate_id', $winner->id)->value('id'))
            ->toBe((string) $first['link_id']);
    } finally {
        Wp1RealDriverHarness::restoreDatabase($previous);
        Wp1RealDriverHarness::dropWorkerDatabase($driver, $database);
    }
}

it('survives a commercial code collision inside a caller transaction on isolated PostgreSQL', function (): void {
    wp1RealCollisionCase($this, 'pgsql');
});

it('elects one canonical profile for concurrent creators on isolated PostgreSQL', function (): void {
    wp1RealRaceCase($this, 'pgsql');
});

it('survives a commercial code collision inside a caller transaction on isolated MariaDB', function (): void {
    wp1RealCollisionCase($this, 'mariadb');
});

it('elects one canonical profile for concurrent creators on isolated MariaDB', function (): void {
    wp1RealRaceCase($this, 'mariadb');
});
