<?php

declare(strict_types=1);

// WP3 real-driver OS-process race proof.
//
// Two independent OS processes with independent connections run the actual
// current claim and share-creation flows behind a start barrier on
// worker-namespaced share_rewards_test_wp3_* databases built from the
// current original migrations (full set on PostgreSQL, the WP1/WP2
// asserted end state (exactly one claim association, one link and one
// managed short URL), never timing and never a sleep. If the
// exactly-one claim key, the single-use capability consume, or the
// create-or-reuse convergence were deleted, these tests fail.
//
// Guarded test-only worker databases: loopback isolated ports/databases
// only. Never the live application database (ilmu360 / 127.0.0.1:5432).

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateBalance;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Links\Models\Link;
use App\Actions\ShareTracking\ClaimGuestShareProfileAction;
use App\Enums\ShareGuestClaimCapabilityStatus;
use App\Enums\ShareGuestClaimResult;
use App\Enums\ShareGuestClaimStatus;
use App\Enums\ShareGuestCredentialStatus;
use App\Models\ShareGuestClaim;
use App\Models\ShareGuestClaimCapability;
use App\Models\ShareGuestCredential;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareTrackingProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ShareTracking\Wp1RealDriverHarness;
use Tests\Feature\ShareTracking\Wp2RealDriverHarness;
use Tests\Feature\ShareTracking\Wp3RealDriverHarness;
use Tests\Support\RealDriverFixture;
use Tests\TestCase;

/**
 * @return array{0: string, 1: string}
 */
function wp3RaceWorker(string $test): array
{
    $database = RealDriverFixture::createWorkerDatabase($test, namePrefix: 'wp3_');
    $previous = RealDriverFixture::useWorkerDatabase($database, Wp1RealDriverHarness::WORKER_CONNECTION);

    try {
        Wp3RealDriverHarness::migrateWorkerDatabase();

        // Explicit schema proof: no swallowed migration failure. The
        // affiliates package prefixes its tables (affiliate_affiliates).
        Wp3RealDriverHarness::assertWorkerSchema([
            'users',
            'affiliate_affiliates',
            'affiliate_links',
            'tracked_links',
            'contact_methods',
            'share_guest_credentials',
            'share_guest_claim_capabilities',
            'share_guest_claims',
        ]);
    } catch (Throwable $exception) {
        RealDriverFixture::restoreDatabase($previous, Wp1RealDriverHarness::WORKER_CONNECTION);
        RealDriverFixture::dropDatabase($database);

        throw $exception;
    }

    return [$database, $previous];
}

function wp3RaceTeardown(string $database, string $previous): void
{
    RealDriverFixture::restoreDatabase($previous, Wp1RealDriverHarness::WORKER_CONNECTION);
    RealDriverFixture::dropDatabase($database);
}

function wp3ClaimRaceCase(TestCase $testCase): void
{
    [$database, $previous] = wp3RaceWorker('claim');

    try {
        $claimantA = User::factory()->create(['email' => 'wp3-race-a@example.test']);
        $claimantB = User::factory()->create(['email' => 'wp3-race-b@example.test']);

        // Real guest issuance through current source: one guest identity
        // with one single-purpose claim capability.
        $payload = app(ShareTrackingService::class)->sharePayload(
            null,
            route('events.index'),
            'Majlis',
            'Majlis',
            'web',
            Request::create(route('events.index'), 'GET')
        );

        $capability = (string) ($payload['guest_claim_capability'] ?? '');

        expect($capability)->toMatch('/^[A-Za-z0-9]{32}$/');

        $guestAffiliateId = (string) AffiliateLink::query()
            ->where('custom_slug', (string) $payload['tracking_token'])
            ->value('affiliate_id');

        // Two authenticated strangers race the same capability behind the
        // start barrier. Exactly one association wins; the loser refuses
        // generically without writing anything.
        [$first, $second] = Wp3RealDriverHarness::runRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'claim',
            'payload' => [
                'capability' => $capability,
                'user_ids' => [(string) $claimantA->getKey(), (string) $claimantB->getKey()],
            ],
        ]);

        $results = [(string) $first['result'], (string) $second['result']];

        sort($results);

        expect($results)->toBe([
            ShareGuestClaimResult::Claimed->value,
            ShareGuestClaimResult::ClaimedByOther->value,
        ]);

        expect(ShareGuestClaim::query()->where('guest_affiliate_id', $guestAffiliateId)->count())->toBe(1);

        $claim = ShareGuestClaim::query()->where('guest_affiliate_id', $guestAffiliateId)->firstOrFail();
        $winnerId = (string) $first['result'] === ShareGuestClaimResult::Claimed->value
            ? (string) $claimantA->getKey()
            : (string) $claimantB->getKey();

        expect($claim->status)->toBe(ShareGuestClaimStatus::Active)
            ->and((string) $claim->claimant_user_id)->toBe($winnerId)
            ->and(ShareGuestClaimCapability::query()->where('guest_affiliate_id', $guestAffiliateId)->value('status'))
            ->toBe(ShareGuestClaimCapabilityStatus::Consumed);

        // The guest source is untouched; the recipient is distinct.
        $source = Affiliate::query()->whereKey($guestAffiliateId)->firstOrFail();
        $recipient = Affiliate::query()
            ->where('owner_type', $claimantA->getMorphClass())
            ->where('owner_id', $winnerId)
            ->firstOrFail();

        expect(ShareTrackingProfile::isMarkedGuest($source))->toBeTrue()
            ->and($source->owner)->toBeNull()
            ->and((string) $recipient->getKey())->not->toBe($guestAffiliateId);
    } finally {
        wp3RaceTeardown($database, $previous);
    }
}

function wp3SlugRaceCase(TestCase $testCase): void
{
    [$database, $previous] = wp3RaceWorker('slug');

    try {
        $sharer = User::factory()->create(['email' => 'wp3-race-sharer@example.test']);
        $pageUrl = route('events.index');

        // Concurrent creation for the same owner and destination must
        // converge on one link and one managed short URL: one token,
        // one slug, no duplicate rows on either table.
        [$first, $second] = Wp3RealDriverHarness::runRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'slug',
            'payload' => [
                'user_id' => (string) $sharer->getKey(),
                'url' => $pageUrl,
            ],
        ]);

        expect((string) $first['link_id'])->toBe((string) $second['link_id'])
            ->and((string) $first['token'])->toBe((string) $second['token'])
            ->and((string) $first['token'])->toMatch('/^[a-z0-9]{16}$/');

        $affiliate = Affiliate::query()
            ->where('owner_type', $sharer->getMorphClass())
            ->where('owner_id', $sharer->getKey())
            ->firstOrFail();

        $links = AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->get();

        expect($links)->toHaveCount(1)
            ->and(Link::query()->withoutOwnerScope()->count())->toBe(1)
            ->and(Link::query()->withoutOwnerScope()->value('slug'))->not->toBeNull();

        // A second destination mints a distinct token and slug.
        $other = app(ShareTrackingService::class)->createOrReuseLink(
            $sharer,
            route('events.index', ['category' => 'fiqh']),
            'Majlis',
            'web'
        );

        $otherToken = AffiliateLink::query()->whereKey($other->id)->value('custom_slug');

        expect((string) $otherToken)->toMatch('/^[a-z0-9]{16}$/')
            ->and((string) $otherToken)->not->toBe((string) $first['token'])
            ->and(Link::query()->withoutOwnerScope()->count())->toBe(2)
            ->and(Link::query()->withoutOwnerScope()->distinct()->count('slug'))->toBe(2);
    } finally {
        wp3RaceTeardown($database, $previous);
    }
}

function wp3SlugForcedRaceCase(TestCase $testCase): void
{
    [$database, $previous] = wp3RaceWorker('slugforced');

    try {
        $sharer = User::factory()->create(['email' => 'wp3-forced-sharer@example.test']);
        $pageUrl = route('events.index');
        $raceUrl = route('events.index', ['category' => 'fiqh']);

        // Mint the member affiliate on an unrelated destination; the
        // raced destination starts with a genuinely empty lookup.
        app(ShareTrackingService::class)->createOrReuseLink($sharer, $pageUrl, 'Majlis', 'web');

        $affiliate = Affiliate::query()
            ->where('owner_type', $sharer->getMorphClass())
            ->where('owner_id', $sharer->getKey())
            ->firstOrFail();

        expect(AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->count())->toBe(1);

        DB::beginTransaction();

        try {
            OwnerContext::withOwner($sharer, static function () use ($affiliate): void {
                Affiliate::query()->whereKey($affiliate->getKey())->lockForUpdate()->firstOrFail();
            });

            [$commit, $worker] = Wp3RealDriverHarness::runForcedSolo([
                'database' => $database,
                'app_url' => (string) config('app.url'),
                'mode' => 'slug_solo',
                'payload' => [
                    'user_id' => (string) $sharer->getKey(),
                    'url' => $raceUrl,
                ],
            ], static function () use ($sharer, $raceUrl): array {
                $link = app(ShareTrackingService::class)->createOrReuseLink($sharer, $raceUrl, 'Majlis', 'web');

                $commitStartedAt = microtime(true);

                DB::commit();

                return [
                    'link_id' => $link->id,
                    'commit_started_at' => $commitStartedAt,
                    'committed_at' => microtime(true),
                ];
            });
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        expect((string) $worker['link_id'])->toBe((string) $commit['link_id'])
            ->and((string) $worker['token'])->toMatch('/^[a-z0-9]{16}$/');

        // The worker attempted before the parent committed and returned
        // after: it blocked on the affiliate lock, then reused the row.
        expect($worker['attempted_at'])->toBeLessThan($commit['committed_at'])
            ->and($worker['returned_at'])->toBeGreaterThan($commit['commit_started_at'])
            ->and($worker['returned_at'] - $worker['attempted_at'])->toBeGreaterThan(1.0);

        // Exactly one affiliate link, one managed row, one token, and one
        // identical reused URL for the raced destination.
        $converged = AffiliateLink::query()->whereKey($commit['link_id'])->firstOrFail();

        expect(AffiliateLink::query()
            ->where('affiliate_id', $affiliate->getKey())
            ->where('destination_url', $converged->destination_url)
            ->count())->toBe(1)
            ->and((string) $worker['token'])->toBe((string) $converged->custom_slug)
            ->and(Link::query()->withoutOwnerScope()->where('subject_id', $converged->getKey())->count())->toBe(1)
            ->and((string) $worker['url'])->toBe(app(AffiliatesShareTrackingService::class)->shortUrlForLink($converged))
            ->and((string) $worker['url'])->toContain('/k/');

        // A refused reuse rolls back cleanly: disabling the converged
        // link and reusing throws without minting any orphan mapping.
        $managedBefore = Link::query()->withoutOwnerScope()->count();
        $converged->deactivate();

        expect(fn () => app(ShareTrackingService::class)->createOrReuseLink($sharer, $raceUrl, 'Majlis', 'web'))
            ->toThrow(InvalidArgumentException::class);

        expect(AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->count())->toBe(2)
            ->and(Link::query()->withoutOwnerScope()->count())->toBe($managedBefore);
    } finally {
        wp3RaceTeardown($database, $previous);
    }
}

function wp3GuestSlugForcedRaceCase(TestCase $testCase): void
{
    [$database, $previous] = wp3RaceWorker('guestforced');

    try {
        $pageUrl = route('events.index');
        $raceUrl = route('events.index', ['category' => 'fiqh']);

        // Mint the guest identity; the raced destination starts empty.
        $issued = app(ShareTrackingService::class)->sharePayload(
            null,
            $pageUrl,
            'Majlis',
            'Majlis',
            'web',
            Request::create($pageUrl, 'GET')
        );

        $credential = (string) $issued['guest_credential'];
        $affiliateId = (string) AffiliateLink::query()->where('custom_slug', (string) $issued['tracking_token'])->value('affiliate_id');

        expect($credential)->toMatch('/^[A-Za-z0-9]{32}$/');

        $requestForRace = static function () use ($raceUrl, $credential): Request {
            $request = Request::create($raceUrl, 'GET');
            $request->headers->set('X-Dawah-Guest-Credential', $credential);

            return $request;
        };

        DB::beginTransaction();

        try {
            OwnerContext::withOwner(null, static function () use ($affiliateId): void {
                Affiliate::query()->whereKey($affiliateId)->lockForUpdate()->firstOrFail();
            });

            [$commit, $worker] = Wp3RealDriverHarness::runForcedSolo([
                'database' => $database,
                'app_url' => (string) config('app.url'),
                'mode' => 'guest_slug_solo',
                'payload' => [
                    'credential' => $credential,
                    'url' => $raceUrl,
                ],
            ], static function () use ($raceUrl, $requestForRace): array {
                $result = app(ShareTrackingService::class)->sharePayload(
                    null,
                    $raceUrl,
                    'Majlis',
                    'Majlis',
                    'web',
                    $requestForRace()
                );

                $commitStartedAt = microtime(true);

                DB::commit();

                return [
                    'token' => (string) $result['tracking_token'],
                    'url' => (string) $result['url'],
                    'reissued' => ($result['guest_credential'] ?? null) !== null,
                    'commit_started_at' => $commitStartedAt,
                    'committed_at' => microtime(true),
                ];
            });
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        expect((string) $worker['token'])->toBe((string) $commit['token'])
            ->and((string) $worker['token'])->toMatch('/^[a-z0-9]{16}$/')
            ->and((string) $worker['url'])->toBe((string) $commit['url'])
            ->and($worker['reissued_credential'])->toBeNull()
            ->and($worker['reissued_capability'])->toBeNull()
            ->and($commit['reissued'])->toBeFalse();

        expect($worker['attempted_at'])->toBeLessThan($commit['committed_at'])
            ->and($worker['returned_at'])->toBeGreaterThan($commit['commit_started_at'])
            ->and($worker['returned_at'] - $worker['attempted_at'])->toBeGreaterThan(1.0);

        $converged = AffiliateLink::query()->where('custom_slug', $commit['token'])->firstOrFail();

        expect((string) $converged->affiliate_id)->toBe($affiliateId)
            ->and(AffiliateLink::query()
                ->where('affiliate_id', $affiliateId)
                ->where('destination_url', $converged->destination_url)
                ->count())->toBe(1)
            ->and(Link::query()->withoutOwnerScope()->where('subject_id', $converged->getKey())->count())->toBe(1);

        // The refused path mints no orphan mapping for guests either.
        $managedBefore = Link::query()->withoutOwnerScope()->count();
        $converged->deactivate();

        expect(fn () => app(ShareTrackingService::class)->sharePayload(
            null,
            $raceUrl,
            'Majlis',
            'Majlis',
            'web',
            $requestForRace()
        ))->toThrow(InvalidArgumentException::class);

        expect(AffiliateLink::query()->where('affiliate_id', $affiliateId)->count())->toBe(2)
            ->and(Link::query()->withoutOwnerScope()->count())->toBe($managedBefore);
    } finally {
        wp3RaceTeardown($database, $previous);
    }
}

/**
 * @return array{0: string, 1: string}
 */
function wp3DeleteRaceWorker(string $test): array
{
    [$database, $previous] = wp3RaceWorker($test);

    // The real production deletion lifecycle (snapshot, deletion-hook
    // cleanup, physical removal) needs its full original schema: the
    // payout/hold/stats/social tables for the tracking cascade plus the
    // lifecycle tables for the model hook. PostgreSQL already runs the
    // current originals. No stand-ins, no tolerated failures.

    Wp3RealDriverHarness::assertWorkerSchema([
        'affiliate_payouts',
        'affiliate_daily_stats',
        'affiliate_payout_methods',
        'affiliate_payout_holds',
        'social_profiles',
        'audits',
        'deleted_models',
        'socialite',
        'personal_access_tokens',
        'sessions',
        'oauth_auth_codes',
        'oauth_access_tokens',
        'oauth_refresh_tokens',
        'oauth_device_codes',
        'institution_members',
        'person_members',
        'reference_members',
        'event_members',
        'institutions',
        'persons',
        'references',
        'contribution_requests',
        'reports',
        'saved_searches',
        'ai_usage_logs',
        'donation_channels',
        'venues',
        'events',
        'event_registrations',
        'event_attendances',
        'event_submissions',
        'membership_applications',
        'moderation_actions',
        'affiliations',
    ]);

    return [$database, $previous];
}

function wp3DeleteBeforeClaimRaceCase(TestCase $testCase): void
{
    [$database, $previous] = wp3DeleteRaceWorker('deleteseal');

    try {
        $claimant = User::factory()->create(['email' => 'wp3-victim@example.test']);

        $payload = app(ShareTrackingService::class)->sharePayload(
            null,
            route('events.index'),
            'Majlis',
            'Majlis',
            'web',
            Request::create(route('events.index'), 'GET')
        );

        $capability = (string) $payload['guest_claim_capability'];
        $guestAffiliateId = (string) AffiliateLink::query()
            ->where('custom_slug', (string) $payload['tracking_token'])
            ->value('affiliate_id');

        DB::beginTransaction();

        try {
            // The REAL production deletion runs first and is held
            // uncommitted: snapshot, hook cleanup, and physical removal
            // inside one transaction with the user lock held throughout.
            // The worker's claim is the forced contender at that
            // cleanup/removal boundary.
            User::query()->whereKey($claimant->getKey())->firstOrFail()->delete();

            [$commit, $worker] = Wp3RealDriverHarness::runForcedSolo([
                'database' => $database,
                'app_url' => (string) config('app.url'),
                'mode' => 'claim_solo',
                'payload' => [
                    'capability' => $capability,
                    'user_id' => (string) $claimant->getKey(),
                ],
            ], static function (): array {
                $commitStartedAt = microtime(true);

                DB::commit();

                return ['commit_started_at' => $commitStartedAt, 'committed_at' => microtime(true)];
            });
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        // The claim attempted before the deletion committed and returned
        // after: it blocked on the user lock held through the removal.
        expect($worker['attempted_at'])->toBeLessThan($commit['committed_at'])
            ->and($worker['returned_at'])->toBeGreaterThan($commit['commit_started_at'])
            ->and($worker['returned_at'] - $worker['attempted_at'])->toBeGreaterThan(1.0);

        // Delete-first order: the user is gone, and the racing claim is
        // refused on the missing user without writing anything.
        expect((string) $worker['result'])->toBe(ShareGuestClaimResult::ExpiredOrInvalid->value)
            ->and(User::query()->whereKey($claimant->getKey())->exists())->toBeFalse()
            ->and(ShareGuestClaim::query()->where('guest_affiliate_id', $guestAffiliateId)->exists())->toBeFalse()
            ->and(ShareGuestClaimCapability::query()->where('guest_affiliate_id', $guestAffiliateId)->value('status'))
            ->toBe(ShareGuestClaimCapabilityStatus::Active);

        // The guest source itself is never adopted or deleted.
        $source = Affiliate::query()->whereKey($guestAffiliateId)->firstOrFail();

        expect(ShareTrackingProfile::isMarkedGuest($source))->toBeTrue()
            ->and($source->owner)->toBeNull();
    } finally {
        wp3RaceTeardown($database, $previous);
    }
}

function wp3ClaimBeforeDeleteRaceCase(TestCase $testCase): void
{
    [$database, $previous] = wp3DeleteRaceWorker('claimsealdel');

    try {
        $claimant = User::factory()->create(['email' => 'wp3-victim-claimer@example.test']);

        $payload = app(ShareTrackingService::class)->sharePayload(
            null,
            route('events.index'),
            'Majlis',
            'Majlis',
            'web',
            Request::create(route('events.index'), 'GET')
        );

        $capability = (string) $payload['guest_claim_capability'];
        $guestAffiliateId = (string) AffiliateLink::query()
            ->where('custom_slug', (string) $payload['tracking_token'])
            ->value('affiliate_id');

        DB::beginTransaction();

        try {
            // The real production deletion takes the user lock first; the
            // claim takes the same first lock. Holding the user lock here
            // forces the worker's REAL deletion to wait while the parent's
            // claim commits first.
            User::query()->whereKey($claimant->getKey())->lockForUpdate()->firstOrFail();

            [$commit, $worker] = Wp3RealDriverHarness::runForcedSolo([
                'database' => $database,
                'app_url' => (string) config('app.url'),
                'mode' => 'delete_solo',
                'payload' => [
                    'user_id' => (string) $claimant->getKey(),
                ],
            ], static function () use ($claimant, $capability): array {
                $result = app(ClaimGuestShareProfileAction::class)->handle($claimant, $capability, true);

                $commitStartedAt = microtime(true);

                DB::commit();

                return [
                    'result' => $result->value,
                    'commit_started_at' => $commitStartedAt,
                    'committed_at' => microtime(true),
                ];
            });
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        expect($commit['result'])->toBe(ShareGuestClaimResult::Claimed->value);

        // The deletion attempted before the claim committed and returned
        // after: it blocked on the user lock, then revoked the committed
        // claim inside its own cleanup.
        expect($worker['attempted_at'])->toBeLessThan($commit['committed_at'])
            ->and($worker['returned_at'])->toBeGreaterThan($commit['commit_started_at'])
            ->and($worker['returned_at'] - $worker['attempted_at'])->toBeGreaterThan(1.0);

        // Claim-first order: the user is gone through the real entry
        // point, and the committed claim is preserved as revoked history.
        expect($worker['deleted'])->toBeTrue()
            ->and(User::query()->whereKey($claimant->getKey())->exists())->toBeFalse();

        $claim = ShareGuestClaim::query()->where('guest_affiliate_id', $guestAffiliateId)->firstOrFail();

        expect($claim->status)->toBe(ShareGuestClaimStatus::Revoked)
            ->and($claim->revoked_at)->not->toBeNull()
            ->and((string) $claim->claimant_user_id)->toBe((string) $claimant->getKey())
            ->and(ShareGuestClaimCapability::query()->where('guest_affiliate_id', $guestAffiliateId)->value('status'))
            ->toBe(ShareGuestClaimCapabilityStatus::Consumed)
            ->and(ShareGuestCredential::query()->where('affiliate_id', $guestAffiliateId)->value('status'))
            ->toBe(ShareGuestCredentialStatus::Revoked);

        // Claimed sources disarm (no new credit) while every history row
        // survives; the guest profile itself is never adopted or deleted.
        $guestLinks = AffiliateLink::query()->where('affiliate_id', $guestAffiliateId)->get();

        expect($guestLinks->count())->toBeGreaterThanOrEqual(1);

        foreach ($guestLinks as $guestLink) {
            expect($guestLink->deactivated_at)->not->toBeNull();
        }

        $source = Affiliate::query()->whereKey($guestAffiliateId)->firstOrFail();

        expect(ShareTrackingProfile::isMarkedGuest($source))->toBeTrue()
            ->and($source->owner)->toBeNull();
    } finally {
        wp3RaceTeardown($database, $previous);
    }
}

function wp3DeletePreservesObligationsCase(TestCase $testCase): void
{
    [$database, $previous] = wp3DeleteRaceWorker('deleteowes');

    try {
        $claimant = User::factory()->create(['email' => 'wp3-victim-owes@example.test']);

        $payload = app(ShareTrackingService::class)->sharePayload(
            null,
            route('events.index'),
            'Majlis',
            'Majlis',
            'web',
            Request::create(route('events.index'), 'GET')
        );

        $guestAffiliateId = (string) AffiliateLink::query()
            ->where('custom_slug', (string) $payload['tracking_token'])
            ->value('affiliate_id');

        expect(app(ClaimGuestShareProfileAction::class)->handle(
            $claimant,
            (string) $payload['guest_claim_capability'],
            true
        ))->toBe(ShareGuestClaimResult::Claimed);

        // An owed obligation on the claimant's distinct recipient
        // profile: unsettled holding money that deletion must preserve
        // as the restricted payee record.
        $recipient = Affiliate::query()
            ->where('owner_type', $claimant->getMorphClass())
            ->where('owner_id', $claimant->getKey())
            ->firstOrFail();

        expect((string) $recipient->getKey())->not->toBe($guestAffiliateId);

        AffiliateBalance::query()->create([
            'affiliate_id' => $recipient->getKey(),
            'currency' => 'MYR',
            'holding_minor' => 150,
            'minimum_payout_minor' => 0,
        ]);

        $guestLink = AffiliateLink::query()->where('custom_slug', (string) $payload['tracking_token'])->firstOrFail();
        $guestSlug = (string) $guestLink->trackedLink()->firstOrFail()->slug;

        // Sequential real-entry deletion: claim, obligation, then remove.
        User::query()->whereKey($claimant->getKey())->firstOrFail()->delete();

        expect(User::query()->whereKey($claimant->getKey())->exists())->toBeFalse()
            ->and(ShareGuestClaim::query()->where('guest_affiliate_id', $guestAffiliateId)->value('status'))
            ->toBe(ShareGuestClaimStatus::Revoked)
            ->and($guestLink->fresh()->deactivated_at)->not->toBeNull()
            ->and(Link::query()->withoutOwnerScope()->where('slug', $guestSlug)->exists())->toBeTrue()
            ->and(AffiliateLink::query()->whereKey($guestLink->getKey())->exists())->toBeTrue();

        // Owed obligations survive byte-identical: the recipient profile
        // stays as the restricted payee with its holding intact, and no
        // secret or historical effect is replayed or restored.
        $retained = Affiliate::query()->whereKey($recipient->getKey())->firstOrFail();
        $balance = AffiliateBalance::query()
            ->where('affiliate_id', $recipient->getKey())
            ->where('currency', 'MYR')
            ->firstOrFail();

        expect((int) $balance->holding_minor)->toBe(150)
            ->and(ShareGuestClaim::query()->where('guest_affiliate_id', $guestAffiliateId)->count())->toBe(1)
            ->and((string) $retained->owner_id)->toBe((string) $claimant->getKey());

        $source = Affiliate::query()->whereKey($guestAffiliateId)->firstOrFail();

        expect(ShareTrackingProfile::isMarkedGuest($source))->toBeTrue()
            ->and($source->owner)->toBeNull();
    } finally {
        wp3RaceTeardown($database, $previous);
    }
}

function wp3ClaimSealsRacingCreationCase(TestCase $testCase): void
{
    [$database, $previous] = wp3RaceWorker('claimseal');

    try {
        $claimant = User::factory()->create(['email' => 'wp3-seal-claimant@example.test']);
        $pageUrl = route('events.index');
        $raceUrl = route('events.index', ['category' => 'fiqh']);

        // One guest identity with one link; the raced destination is empty.
        $issued = app(ShareTrackingService::class)->sharePayload(
            null,
            $pageUrl,
            'Majlis',
            'Majlis',
            'web',
            Request::create($pageUrl, 'GET')
        );

        $credential = (string) $issued['guest_credential'];
        $capability = (string) $issued['guest_claim_capability'];
        $originalToken = (string) $issued['tracking_token'];
        $guestAffiliateId = (string) AffiliateLink::query()
            ->where('custom_slug', $originalToken)
            ->value('affiliate_id');

        expect($credential)->toMatch('/^[A-Za-z0-9]{32}$/')
            ->and($capability)->toMatch('/^[A-Za-z0-9]{32}$/')
            ->and(AffiliateLink::query()->where('affiliate_id', $guestAffiliateId)->count())->toBe(1);

        DB::beginTransaction();

        try {
            OwnerContext::withOwner(null, static function () use ($guestAffiliateId): void {
                Affiliate::query()->whereKey($guestAffiliateId)->lockForUpdate()->firstOrFail();
            });

            [$commit, $worker] = Wp3RealDriverHarness::runForcedSolo([
                'database' => $database,
                'app_url' => (string) config('app.url'),
                'mode' => 'guest_slug_solo',
                'payload' => [
                    'credential' => $credential,
                    'url' => $raceUrl,
                ],
            ], static function () use ($claimant, $capability): array {
                // The real claim commits while the worker's creation waits
                // on the profile lock: the creation must seal afterwards,
                // never land in the owned history.
                $result = app(ClaimGuestShareProfileAction::class)->handle($claimant, $capability, true);

                $commitStartedAt = microtime(true);

                DB::commit();

                return [
                    'result' => $result->value,
                    'commit_started_at' => $commitStartedAt,
                    'committed_at' => microtime(true),
                ];
            });
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        expect($commit['result'])->toBe(ShareGuestClaimResult::Claimed->value);

        // The worker attempted before the claim committed and returned
        // after: it blocked on the profile lock, then sealed.
        expect($worker['attempted_at'])->toBeLessThan($commit['committed_at'])
            ->and($worker['returned_at'])->toBeGreaterThan($commit['commit_started_at'])
            ->and($worker['returned_at'] - $worker['attempted_at'])->toBeGreaterThan(1.0);

        // Truthful fresh-anonymous-identity transport for the sealed caller.
        expect($worker['reissued_credential'])->toMatch('/^[A-Za-z0-9]{32}$/')
            ->and($worker['reissued_capability'])->toMatch('/^[A-Za-z0-9]{32}$/')
            ->and((string) $worker['reissued_credential'])->not->toBe($credential)
            ->and((string) $worker['reissued_capability'])->not->toBe($capability)
            ->and((string) $worker['token'])->toMatch('/^[a-z0-9]{16}$/');

        $workerAffiliateId = (string) AffiliateLink::query()
            ->where('custom_slug', (string) $worker['token'])
            ->value('affiliate_id');

        // No post-seal authored link in A's history: the worker's link
        // belongs to the fresh identity, and the claimed history is
        // untouched.
        expect($workerAffiliateId)->not->toBe($guestAffiliateId)
            ->and(AffiliateLink::query()->where('affiliate_id', $guestAffiliateId)->count())->toBe(1)
            ->and(AffiliateLink::query()->where('affiliate_id', $workerAffiliateId)->count())->toBe(1);

        // No fresh proof for the old history: exactly the consumed
        // original capability, and the credential stays revoked.
        expect(ShareGuestClaimCapability::query()->where('guest_affiliate_id', $guestAffiliateId)->count())->toBe(1)
            ->and(ShareGuestClaimCapability::query()->where('guest_affiliate_id', $guestAffiliateId)->value('status'))
            ->toBe(ShareGuestClaimCapabilityStatus::Consumed)
            ->and(ShareGuestCredential::query()->where('affiliate_id', $guestAffiliateId)->value('status'))
            ->toBe(ShareGuestCredentialStatus::Revoked)
            ->and(ShareGuestClaim::query()->where('guest_affiliate_id', $guestAffiliateId)->value('status'))
            ->toBe(ShareGuestClaimStatus::Active);

        // The fresh identity carries its own live proof, unclaimed.
        expect(ShareGuestClaimCapability::query()->where('guest_affiliate_id', $workerAffiliateId)->count())->toBe(1)
            ->and(ShareGuestClaimCapability::query()->where('guest_affiliate_id', $workerAffiliateId)->value('status'))
            ->toBe(ShareGuestClaimCapabilityStatus::Active)
            ->and(ShareGuestClaim::query()->where('guest_affiliate_id', $workerAffiliateId)->exists())->toBeFalse();

        // Legitimate new traffic on the existing claimed public link still
        // records against its original guest source.
        $cookie = app(ShareTrackingService::class)->captureRequest(
            Wp2RealDriverHarness::landingRequest($pageUrl.'?share='.$originalToken)
        );

        expect($cookie)->toBeString()
            ->and($cookie)->not->toBe('')
            ->and(AffiliateAttribution::query()->where('affiliate_id', $guestAffiliateId)->count())->toBe(1);
    } finally {
        wp3RaceTeardown($database, $previous);
    }
}

function wp3CreationBeforeClaimRaceCase(TestCase $testCase): void
{
    [$database, $previous] = wp3RaceWorker('createseal');

    try {
        $claimant = User::factory()->create(['email' => 'wp3-seal-creator@example.test']);
        $pageUrl = route('events.index');
        $raceUrl = route('events.index', ['category' => 'fiqh']);

        $issued = app(ShareTrackingService::class)->sharePayload(
            null,
            $pageUrl,
            'Majlis',
            'Majlis',
            'web',
            Request::create($pageUrl, 'GET')
        );

        $credential = (string) $issued['guest_credential'];
        $capability = (string) $issued['guest_claim_capability'];
        $guestAffiliateId = (string) AffiliateLink::query()
            ->where('custom_slug', (string) $issued['tracking_token'])
            ->value('affiliate_id');

        expect(AffiliateLink::query()->where('affiliate_id', $guestAffiliateId)->count())->toBe(1);

        DB::beginTransaction();

        try {
            // The claim needs the user row first; the anonymous creation
            // does not. Holding the user lock forces the worker's claim to
            // wait while the parent's creation commits first.
            User::query()->whereKey($claimant->getKey())->lockForUpdate()->firstOrFail();

            [$commit, $worker] = Wp3RealDriverHarness::runForcedSolo([
                'database' => $database,
                'app_url' => (string) config('app.url'),
                'mode' => 'claim_solo',
                'payload' => [
                    'capability' => $capability,
                    'user_id' => (string) $claimant->getKey(),
                ],
            ], static function () use ($raceUrl, $credential): array {
                $request = Request::create($raceUrl, 'GET');
                $request->headers->set('X-Dawah-Guest-Credential', $credential);

                $result = app(ShareTrackingService::class)->sharePayload(
                    null,
                    $raceUrl,
                    'Majlis',
                    'Majlis',
                    'web',
                    $request
                );

                $commitStartedAt = microtime(true);

                DB::commit();

                return [
                    'token' => (string) $result['tracking_token'],
                    'reissued' => ($result['guest_credential'] ?? null) !== null,
                    'commit_started_at' => $commitStartedAt,
                    'committed_at' => microtime(true),
                ];
            });
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        // The worker attempted its claim before the creation committed and
        // returned after: the claim blocked on the user lock.
        expect($worker['attempted_at'])->toBeLessThan($commit['committed_at'])
            ->and($worker['returned_at'])->toBeGreaterThan($commit['commit_started_at'])
            ->and($worker['returned_at'] - $worker['attempted_at'])->toBeGreaterThan(1.0);

        // Pre-seal authorship is honest reuse: no fresh identity issued.
        expect($commit['reissued'])->toBeFalse()
            ->and($commit['token'])->toMatch('/^[a-z0-9]{16}$/');

        // The racing claim still wins afterwards, sealing a history that
        // truthfully includes the pre-seal link.
        expect((string) $worker['result'])->toBe(ShareGuestClaimResult::Claimed->value)
            ->and(AffiliateLink::query()->where('affiliate_id', $guestAffiliateId)->count())->toBe(2)
            ->and((string) AffiliateLink::query()->where('custom_slug', $commit['token'])->value('affiliate_id'))
            ->toBe($guestAffiliateId)
            ->and(ShareGuestCredential::query()->where('affiliate_id', $guestAffiliateId)->value('status'))
            ->toBe(ShareGuestCredentialStatus::Revoked)
            ->and(ShareGuestClaimCapability::query()->where('guest_affiliate_id', $guestAffiliateId)->value('status'))
            ->toBe(ShareGuestClaimCapabilityStatus::Consumed);

        $claim = ShareGuestClaim::query()->where('guest_affiliate_id', $guestAffiliateId)->firstOrFail();

        expect($claim->status)->toBe(ShareGuestClaimStatus::Active)
            ->and((string) $claim->claimant_user_id)->toBe((string) $claimant->getKey());
    } finally {
        wp3RaceTeardown($database, $previous);
    }
}

$label = 'PostgreSQL 17';
it("claims exactly one winner for a competing-claimant race on {$label}", function (): void {
    wp3ClaimRaceCase($this);
});

it("converges concurrent share creation on one short URL on {$label}", function (): void {
    wp3SlugRaceCase($this);
});

it("serializes a forced empty-lookup overlap for member creation on {$label}", function (): void {
    wp3SlugForcedRaceCase($this);
});

it("serializes a forced empty-lookup overlap for guest creation on {$label}", function (): void {
    wp3GuestSlugForcedRaceCase($this);
});

it("refuses a claim that raced a real deletion on {$label}", function (): void {
    wp3DeleteBeforeClaimRaceCase($this);
});

it("revokes a claim that beat a real deletion on {$label}", function (): void {
    wp3ClaimBeforeDeleteRaceCase($this);
});

it("preserves owed obligations through a real deletion on {$label}", function (): void {
    wp3DeletePreservesObligationsCase($this);
});

it("seals a creation that raced a claim into a fresh identity on {$label}", function (): void {
    wp3ClaimSealsRacingCreationCase($this);
});

it("keeps a creation that beat a claim inside the sealed history on {$label}", function (): void {
    wp3CreationBeforeClaimRaceCase($this);
});
