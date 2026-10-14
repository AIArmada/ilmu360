<?php

declare(strict_types=1);

// WP2 real-driver proof for the atomic outcome slice.
//
// Every case runs the actual current application + affiliates flows
// (captureRequest landings, recordOutcome/recordInheritedOutcome,
// ReverseShareOutcomeAction, DispatchShareEffectReceiptsAction) against
// worker-namespaced databases built from the current original migrations
// on the isolated PostgreSQL 17 and MariaDB 11.4 fixtures. If the
// receipt-first freeze, the fraud/accounting rollback, the TTL/dedupe
// edges, the dispatcher recovery, or the monetary guard were deleted,
// these tests fail: no copied closure or SQLite-only claim can pass them.
//
// Guarded test-only worker databases: loopback isolated ports/databases
// only. Never the live application database (ilmu360 / 127.0.0.1:5432).

use AIArmada\Affiliates\Enums\FraudSeverity;
use AIArmada\Affiliates\Enums\FraudSignalStatus;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateBalance;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Affiliates\Services\FraudDetectionService;
use AIArmada\Affiliates\States\ConversionStatus;
use AIArmada\Engagement\Models\Response;
use App\Actions\ShareTracking\DispatchShareEffectReceiptsAction;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Enums\DawahShareOutcomeType;
use App\Enums\ShareEffectStatus;
use App\Models\Event;
use App\Models\Registration;
use App\Models\ShareBrowserSelection;
use App\Models\ShareEffectReceipt;
use App\Models\ShareOutcomeCorrection;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\ShareTracking\Wp1RealDriverHarness;
use Tests\Feature\ShareTracking\Wp2RealDriverHarness;
use Tests\Feature\ShareTracking\Wp2ThrowingFraudRule;
use Tests\TestCase;

function wp2ConversionStatusValue(AffiliateConversion $conversion): string
{
    $status = $conversion->status;

    return $status instanceof ConversionStatus
        ? (string) $status->getValue()
        : (string) $status;
}

/**
 * @return array{0: string, 1: string}
 */
function wp2OutcomeWorker(string $driver, string $test): array
{
    $database = Wp1RealDriverHarness::createWorkerDatabase($driver, $test);
    $previous = Wp1RealDriverHarness::useWorkerDatabase($driver, $database);

    Wp2RealDriverHarness::migrateWorkerDatabase($driver);

    return [$database, $previous];
}

function wp2OutcomeTeardown(string $driver, string $database, string $previous): void
{
    Wp1RealDriverHarness::restoreDatabase($previous);
    Wp1RealDriverHarness::dropWorkerDatabase($driver, $database);
}

function wp2OutcomeLink(User $sharer, string $url): AffiliateLink
{
    app(ShareTrackingService::class)->createOrReuseLink($sharer, $url, 'Majlis', 'web');

    $affiliate = Affiliate::query()
        ->where('owner_type', $sharer->getMorphClass())
        ->where('owner_id', $sharer->getKey())
        ->firstOrFail();

    return AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->firstOrFail();
}

function wp2OutcomeLanding(string $url, ?string $cookie = null): ?string
{
    return app(ShareTrackingService::class)->captureRequest(
        Wp2RealDriverHarness::landingRequest($url, $cookie)
    );
}

function wp2OutcomeRequest(?string $cookie = null): Request
{
    $request = Request::create((string) config('app.url'), 'GET');

    if (is_string($cookie) && $cookie !== '') {
        $request->cookies->set((string) config('dawah-share.cookie.name', 'mi_dawah_share'), $cookie);
    }

    return $request;
}

function wp2OutcomeCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'outcome');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $cookie = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);

        expect($cookie)->toBeString()->not->toBe('');

        $service = app(ShareTrackingService::class);
        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());
        $occurredAt = CarbonImmutable::now()->subMinutes(7)->startOfSecond();

        $outcome = $service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            $businessKey,
            null,
            $actor,
            wp2OutcomeRequest($cookie),
            ['saved_search_id' => 'wp2'],
            $occurredAt,
        );

        expect($outcome)->not->toBeNull();

        $receipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail();

        expect($receipt->isAttributed())->toBeTrue()
            ->and($receipt->occurred_at->equalTo($occurredAt))->toBeTrue();

        expect(AffiliateConversion::query()->count())->toBe(1)
            ->and(ShareEffectReceipt::query()->count())->toBe(1);

        // The projection is dispatcher-owned: recording must not move the
        // link counter synchronously.
        expect((int) $link->fresh()->conversions)->toBe(0);

        // Replay with a later time and a different cookie keeps the
        // committed decision and the original immutable source time, and
        // writes nothing more.
        $replayed = $service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            $businessKey,
            null,
            $actor,
            wp2OutcomeRequest(null),
            [],
            CarbonImmutable::now(),
        );

        expect($replayed)->not->toBeNull()
            ->and(AffiliateConversion::query()->count())->toBe(1)
            ->and(ShareEffectReceipt::query()->count())->toBe(1)
            ->and($receipt->fresh()->occurred_at->equalTo($occurredAt))->toBeTrue();
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2UnattributedCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'unattributed');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $cookie = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);

        $service = app(ShareTrackingService::class);
        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());

        // No cookie at the original transition: the freeze is explicitly
        // unattributed and earns nothing.
        expect($service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            $businessKey,
            null,
            $actor,
            wp2OutcomeRequest(null),
        ))->toBeNull();

        $receipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail();

        expect($receipt->isAttributed())->toBeFalse()
            ->and(AffiliateConversion::query()->count())->toBe(0)
            ->and(ShareEffectReceipt::query()->count())->toBe(0);

        // A later landing cookie can never revive the frozen decision.
        expect($service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            $businessKey,
            null,
            $actor,
            wp2OutcomeRequest($cookie),
        ))->toBeNull()
            ->and(AffiliateConversion::query()->count())->toBe(0);
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2FraudRejectionCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'fraudreject');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $cookie = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);

        // The real velocity rule fires from the second same-day
        // conversion; the lowered blocking threshold rejects it.
        config([
            'affiliates.fraud.velocity.max_conversions_per_day' => 1,
            'affiliates.fraud.blocking_threshold' => 30,
        ]);

        $service = app(ShareTrackingService::class);
        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());

        expect($service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            $businessKey,
            null,
            $actor,
            wp2OutcomeRequest($cookie),
        ))->toBeNull();

        $conversion = AffiliateConversion::query()->firstOrFail();

        $balance = AffiliateBalance::query()->firstOrFail();

        expect(wp2ConversionStatusValue($conversion))->toBe('rejected')
            ->and(AffiliateFraudSignal::query()->where('rule_code', 'CONVERSION_VELOCITY')->count())->toBe(1)
            ->and(ShareEffectReceipt::query()->count())->toBe(0)
            ->and((int) $balance->available_minor)->toBe(0)
            ->and((int) $balance->holding_minor)->toBe(0)
            ->and((int) $balance->lifetime_earnings_minor)->toBe(0);

        // A rejected conversion stays rejected at the receipt boundary:
        // replay never revives it into credit.
        expect($service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            $businessKey,
            null,
            $actor,
            wp2OutcomeRequest($cookie),
        ))->toBeNull()
            ->and(AffiliateConversion::query()->count())->toBe(1);
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2RollbackCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'rollback');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $cookie = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);

        app()->tag([Wp2ThrowingFraudRule::class], 'affiliates.fraud_rule');
        app()->forgetInstance(FraudDetectionService::class);

        $service = app(ShareTrackingService::class);
        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());
        $failure = null;

        try {
            $service->recordOutcome(
                DawahShareOutcomeType::EventSave,
                $businessKey,
                null,
                $actor,
                wp2OutcomeRequest($cookie),
            );
        } catch (RuntimeException $exception) {
            $failure = $exception->getMessage();
        }

        expect($failure)->toBe('wp2 injected fraud-stage failure')
            ->and(AffiliateConversion::query()->count())->toBe(0)
            ->and(AffiliateFraudSignal::query()->count())->toBe(0)
            ->and(AffiliateBalance::query()->count())->toBe(0)
            ->and(ShareSourceAttributionReceipt::query()->count())->toBe(0)
            ->and(ShareEffectReceipt::query()->count())->toBe(0);
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2AccountingFaultCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'acctfault');

    // Test-only query seam: throw once, after the first accounting UPDATE
    // to affiliate_balances has executed inside the real production
    // record/accounting transaction but before commit. The armed flag is
    // the listener scope restore: once disarmed the listener stays
    // registered but inert, so later cases are never affected.
    $faultArmed = true;
    $faultFired = false;

    DB::listen(static function (QueryExecuted $query) use (&$faultArmed, &$faultFired): void {
        if (! $faultArmed) {
            return;
        }

        if (str_contains($query->sql, 'affiliate_balances')
            && str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
            $faultArmed = false;
            $faultFired = true;

            throw new RuntimeException('wp2 injected accounting-stage failure');
        }
    });

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $cookie = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);

        $service = app(ShareTrackingService::class);
        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());
        $failure = null;

        try {
            $service->recordOutcome(
                DawahShareOutcomeType::EventSave,
                $businessKey,
                null,
                $actor,
                wp2OutcomeRequest($cookie),
            );
        } catch (RuntimeException $exception) {
            $failure = $exception->getMessage();
        }

        // The fault really fired inside accounting, and the whole
        // fact/fraud/accounting/projection/effect/source receipt rolled
        // back together: no partial rows survive on either driver.
        expect($faultFired)->toBeTrue()
            ->and($failure)->toBe('wp2 injected accounting-stage failure')
            ->and(AffiliateConversion::query()->count())->toBe(0)
            ->and(AffiliateFraudSignal::query()->count())->toBe(0)
            ->and(AffiliateBalance::query()->count())->toBe(0)
            ->and(ShareSourceAttributionReceipt::query()->count())->toBe(0)
            ->and(ShareEffectReceipt::query()->count())->toBe(0);

        // A subsequent legitimate retry records the frozen decision
        // exactly once.
        $retry = $service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            $businessKey,
            null,
            $actor,
            wp2OutcomeRequest($cookie),
        );

        expect($retry)->not->toBeNull()
            ->and(AffiliateConversion::query()->count())->toBe(1)
            ->and(AffiliateBalance::query()->count())->toBe(1)
            ->and(ShareSourceAttributionReceipt::query()->count())->toBe(1)
            ->and(ShareSourceAttributionReceipt::query()->firstOrFail()->isAttributed())->toBeTrue()
            ->and(ShareEffectReceipt::query()->count())->toBe(1);
    } finally {
        $faultArmed = false;
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2DispatchRecoveryCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'dispatch');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $cookie = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);

        $service = app(ShareTrackingService::class);

        $service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            ShareOutcomeBusinessKey::eventSave((string) Str::uuid()),
            null,
            $actor,
            wp2OutcomeRequest($cookie),
        );

        $dispatcher = app(DispatchShareEffectReceiptsAction::class);

        // Commit-before-dispatch recovery: the pending keyed receipt is
        // the durable gap coverage, applied exactly once.
        expect($dispatcher->handle(50, 'wp2-worker-a'))->toBe(1)
            ->and((int) $link->fresh()->conversions)->toBe(1)
            ->and($dispatcher->handle(50, 'wp2-worker-a'))->toBe(0)
            ->and((int) $link->fresh()->conversions)->toBe(1);

        $receipt = ShareEffectReceipt::query()->firstOrFail();

        expect($receipt->status)->toBe(ShareEffectStatus::Succeeded)
            ->and($receipt->succeeded_at)->not->toBeNull();

        // An expired lease from a dead worker is reclaimed and applied
        // once instead of stranding the effect.
        $service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            ShareOutcomeBusinessKey::eventSave((string) Str::uuid()),
            null,
            $actor,
            wp2OutcomeRequest($cookie),
        );

        $stranded = ShareEffectReceipt::query()->where('status', ShareEffectStatus::Pending->value)->firstOrFail();
        $stranded->forceFill([
            'status' => ShareEffectStatus::Leased,
            'lease_token' => (string) Str::uuid(),
            'leased_by' => 'wp2-dead-worker',
            'lease_expires_at' => CarbonImmutable::now()->subMinute(),
        ])->save();

        expect($dispatcher->handle(50, 'wp2-worker-b'))->toBe(1)
            ->and((int) $link->fresh()->conversions)->toBe(2)
            ->and($stranded->fresh()->status)->toBe(ShareEffectStatus::Succeeded);
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2TtlDedupeCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'ttldedupe');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $landingUrl = $pageUrl.'?share='.$link->custom_slug;

        $service = app(ShareTrackingService::class);

        $cookie = wp2OutcomeLanding($landingUrl);

        expect(AffiliateAttribution::query()->count())->toBe(1)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1);

        // Rolling five-minute dedupe: the same browser+link+route repeat
        // is suppressed and never moves the accepted clock.
        $acceptedAt = ShareBrowserSelection::query()->firstOrFail()->accepted_at;

        $testCase->travel(4)->minutes();

        wp2OutcomeLanding($landingUrl, $cookie);

        expect(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1)
            ->and(ShareBrowserSelection::query()->firstOrFail()->accepted_at->equalTo($acceptedAt))->toBeTrue();

        // Past the rolling window, the same landing mints a fresh
        // immutable attribution with a new window; it never renews the old.
        $testCase->travel(2)->minutes();

        wp2OutcomeLanding($landingUrl, $cookie);

        expect(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(2)
            ->and(AffiliateAttribution::query()->count())->toBe(2);

        // Thirty-day TTL: after expiry the old cookie earns nothing and
        // a fresh landing creates a new immutable attribution instead of
        // renewing the old one.
        $testCase->travel(31)->days();

        $staleOutcome = $service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            ShareOutcomeBusinessKey::eventSave((string) Str::uuid()),
            null,
            $actor,
            wp2OutcomeRequest($cookie),
        );

        expect($staleOutcome)->toBeNull()
            ->and(AffiliateConversion::query()->count())->toBe(0);

        $freshCookie = wp2OutcomeLanding($landingUrl, $cookie);

        expect(AffiliateAttribution::query()->count())->toBe(3);

        $freshOutcome = $service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            ShareOutcomeBusinessKey::eventSave((string) Str::uuid()),
            null,
            $actor,
            wp2OutcomeRequest($freshCookie),
        );

        expect($freshOutcome)->not->toBeNull()
            ->and(AffiliateConversion::query()->count())->toBe(1);

        $testCase->travelBack();
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2InheritanceCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'inherit');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $cookie = wp2OutcomeLanding($landingUrl = $pageUrl.'?share='.$link->custom_slug);

        $service = app(ShareTrackingService::class);
        $sourceKey = ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid());

        expect($service->recordOutcome(
            DawahShareOutcomeType::EventRegistration,
            $sourceKey,
            null,
            $actor,
            wp2OutcomeRequest($cookie),
        ))->not->toBeNull();

        $sourceReceipt = ShareSourceAttributionReceipt::query()->where('business_key', $sourceKey)->firstOrFail();

        // Current link disablement cannot erase accrued provenance.
        // Inheritance reads only the frozen source receipt, never any
        // live staff browser cookie.
        $link->deactivate();

        $testCase->travel(31)->days();

        $inherited = $service->recordInheritedOutcome(
            DawahShareOutcomeType::EventCheckin,
            ShareOutcomeBusinessKey::eventCheckin((string) Str::uuid()),
            $sourceKey,
            null,
            null,
            ['staff_assisted' => true],
        );

        expect($inherited)->not->toBeNull();

        $checkinReceipt = ShareSourceAttributionReceipt::query()
            ->where('outcome_type', DawahShareOutcomeType::EventCheckin->value)
            ->firstOrFail();

        expect((string) $checkinReceipt->attribution_id)->toBe((string) $sourceReceipt->attribution_id)
            ->and($checkinReceipt->provenance)->toBe(ShareSourceAttributionReceipt::PROVENANCE_REGISTRATION);

        $testCase->travelBack();
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2CorrectionCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'correct');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $cookie = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);

        $service = app(ShareTrackingService::class);
        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());

        expect($service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            $businessKey,
            null,
            $actor,
            wp2OutcomeRequest($cookie),
        ))->not->toBeNull();

        $reverser = app(ReverseShareOutcomeAction::class);
        $correction = $reverser->handle($businessKey, 'save_removed', $actor, null);

        expect($correction)->toBeInstanceOf(ShareOutcomeCorrection::class)
            ->and(ShareOutcomeCorrection::query()->count())->toBe(1)
            ->and(wp2ConversionStatusValue(AffiliateConversion::query()->firstOrFail()))->toBe('reversed')
            ->and(ShareEffectReceipt::query()->firstOrFail()->status)->toBe(ShareEffectStatus::Cancelled);

        // The frozen source decision stays attributed history; the
        // correction is idempotent under retry with different text.
        expect(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail()->isAttributed())->toBeTrue();

        $retry = $reverser->handle($businessKey, 'save_removed_retry', $actor, null);

        expect((string) $retry->getKey())->toBe((string) $correction->getKey())
            ->and(ShareOutcomeCorrection::query()->count())->toBe(1);

        // A conversion carrying posted money is never touched by the
        // zero-fact helper: the guarded throw leaves every row intact.
        $moneyKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());

        expect($service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            $moneyKey,
            null,
            $actor,
            wp2OutcomeRequest($cookie),
        ))->not->toBeNull();

        $moneyConversion = AffiliateConversion::query()->where('idempotency_key', hash('sha256', $moneyKey))->firstOrFail();
        $moneyConversion->forceFill(['commission_minor' => 100])->save();

        $failure = null;

        try {
            $reverser->handle($moneyKey, 'save_removed', $actor, null);
        } catch (InvalidArgumentException $exception) {
            $failure = $exception->getMessage();
        }

        expect($failure)->toBe('Monetary conversions require the authorized finance reversal workflow, not the zero-fact helper.')
            ->and(ShareOutcomeCorrection::query()->count())->toBe(1)
            ->and(wp2ConversionStatusValue($moneyConversion->fresh()))->toBe('approved');
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2MalformedKeyCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'malformed');

    try {
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $service = app(ShareTrackingService::class);

        $malformed = [
            'legacy:signup:user:'.((string) Str::uuid()),
            'share-outcome:event_save:response:'.((string) Str::uuid()),
            'share-outcome:event_save:bookmark:not-a-uuid',
            'share-outcome:event_save:bookmark:',
            'share-outcome:event_save',
            '',
        ];

        foreach ($malformed as $key) {
            $failure = null;

            try {
                $service->recordOutcome(DawahShareOutcomeType::EventSave, $key, null, $actor, null);
            } catch (InvalidArgumentException $exception) {
                $failure = $exception->getMessage();
            }

            expect($failure)->not->toBeNull();
        }

        expect(ShareSourceAttributionReceipt::query()->count())->toBe(0)
            ->and(AffiliateConversion::query()->count())->toBe(0);
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2GoingCrashCase(TestCase $testCase): void
{
    // PostgreSQL only: the going flow spans the events and engagement
    // tables, which the MariaDB minimal worker schema cannot carry (an
    // unrelated vendor migration exceeds the identifier limit there).
    [$database, $previous] = wp2OutcomeWorker('pgsql', 'goingcrash');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $cookieName = (string) config('dawah-share.cookie.name', 'mi_dawah_share');

        // The landing runs through real HTTP so the attribution cookie
        // round-trips encrypted exactly like a browser.
        $landingResponse = $testCase->get($pageUrl.'?share='.$link->custom_slug);
        $landingResponse->assertOk();
        $cookie = $landingResponse->getCookie($cookieName)?->getValue();

        expect($cookie)->toBeString()->not->toBe('');

        $makeEvent = static fn (): Event => Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'starts_at' => now()->addDays(3),
            'pricing_mode' => 'free',
        ]);

        Sanctum::actingAs($actor);

        // Control: the going flow credits the response and the
        // going-triggered admission, each with its own receipt.
        $event = $makeEvent();

        $testCase->withCredentials()
            ->withCookie($cookieName, (string) $cookie)
            ->putJson(route('api.events.going.update', $event))
            ->assertCreated();

        expect(Response::query()->where('response_type', 'going')->count())->toBe(1)
            ->and(Registration::query()->count())->toBe(1)
            ->and(ShareSourceAttributionReceipt::query()->count())->toBe(2)
            ->and(AffiliateConversion::query()->count())->toBe(2);

        // Crash: a failure while recording the going outcome rolls the
        // whole source transaction back, including the already-posted
        // admission accounting and fraud signals.
        app()->tag([Wp2ThrowingFraudRule::class], 'affiliates.fraud_rule');
        app()->forgetInstance(FraudDetectionService::class);

        $event2 = $makeEvent();

        $testCase->withCredentials()
            ->withCookie($cookieName, (string) $cookie)
            ->putJson(route('api.events.going.update', $event2))
            ->assertServerError();

        expect(Response::query()->where('response_type', 'going')->count())->toBe(1)
            ->and(Registration::query()->count())->toBe(1)
            ->and(ShareSourceAttributionReceipt::query()->count())->toBe(2)
            ->and(AffiliateConversion::query()->count())->toBe(2)
            ->and(AffiliateFraudSignal::query()->where('rule_code', 'WP2_THROW')->count())->toBe(0);
    } finally {
        wp2OutcomeTeardown($driver = 'pgsql', $database, $previous);
    }
}

function wp2BrowserReversedArrivalCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'reversed');

    try {
        $pageUrl = route('events.index');
        $sharerA = User::factory()->create(['email' => 'wp2-sharer-a@example.test']);
        $sharerB = User::factory()->create(['email' => 'wp2-sharer-b@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $linkA = wp2OutcomeLink($sharerA, $pageUrl);
        $linkB = wp2OutcomeLink($sharerB, $pageUrl);
        $service = app(ShareTrackingService::class);

        $cookieA = wp2OutcomeLanding($pageUrl.'?share='.$linkA->custom_slug);
        expect($cookieA)->toBeString()->not->toBe('');

        $cookieB = wp2OutcomeLanding($pageUrl.'?share='.$linkB->custom_slug, $cookieA);
        expect($cookieB)->toBeString()->not->toBe('')->not->toBe($cookieA);

        $winnerB = AffiliateAttribution::query()->where('affiliate_link_id', $linkB->getKey())->latest('first_seen_at')->firstOrFail();
        $selection = ShareBrowserSelection::query()->firstOrFail();
        expect((string) $selection->current_attribution_id)->toBe((string) $winnerB->getKey());

        $resolved = $service->resolveActiveAttribution(wp2OutcomeRequest($cookieA));
        expect($resolved)->not->toBeNull();
        expect((string) $resolved->id)->toBe((string) $winnerB->getKey());

        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());
        $outcome = $service->recordOutcome(DawahShareOutcomeType::EventSave, $businessKey, null, $actor, wp2OutcomeRequest($cookieA));

        expect($outcome)->not->toBeNull();
        expect((string) $outcome->linkId)->toBe((string) $linkB->getKey());

        $receipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail();
        expect((string) $receipt->attribution_id)->toBe((string) $winnerB->getKey());

        $conversion = AffiliateConversion::query()->where('idempotency_key', hash('sha256', $businessKey))->firstOrFail();
        expect((string) $conversion->affiliate_link_id)->toBe((string) $linkB->getKey());
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2BrowserInvalidTokenCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'invalidtok');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $service = app(ShareTrackingService::class);

        $cookie = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);
        expect($cookie)->toBeString()->not->toBe('');
        expect(ShareBrowserSelection::query()->whereNotNull('current_attribution_id')->count())->toBe(1);

        $cleared = wp2OutcomeLanding($pageUrl.'?share=deadbeef-dead-beef-dead-beefdeadbeef', $cookie);
        expect($cleared)->toBeString()->not->toBe('');

        expect(ShareBrowserSelection::query()->whereNotNull('current_attribution_id')->count())->toBe(0);
        expect($service->resolveActiveAttribution(wp2OutcomeRequest($cookie)))->toBeNull();

        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());
        expect($service->recordOutcome(DawahShareOutcomeType::EventSave, $businessKey, null, $actor, wp2OutcomeRequest($cookie)))->toBeNull();

        $receipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail();
        expect($receipt->isAttributed())->toBeFalse();
        expect(AffiliateConversion::query()->count())->toBe(0);
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2BrowserSameLinkTtlCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'samelinkttl');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);

        $cookie0 = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);
        expect($cookie0)->toBeString()->not->toBe('');
        expect(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(1);

        $first = AffiliateAttribution::query()->where('attribution_type', 'landing')->firstOrFail();
        $selection0 = ShareBrowserSelection::query()->firstOrFail();
        $accepted0 = (string) $selection0->accepted_at;

        $testCase->travel(4)->minutes();
        $cookieDedupe = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug, $cookie0);
        expect($cookieDedupe)->toBeString()->not->toBe('');
        expect(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(1);
        expect((string) ShareBrowserSelection::query()->firstOrFail()->accepted_at)->toBe($accepted0);

        $testCase->travel(2)->minutes();
        $cookie1 = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug, (string) $cookieDedupe);
        expect($cookie1)->toBeString()->not->toBe('');
        expect(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(2);

        $testCase->travel(10)->days();
        $cookie2 = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug, (string) $cookie1);
        expect($cookie2)->toBeString()->not->toBe('');
        expect(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3);

        $latest = AffiliateAttribution::query()->where('attribution_type', 'landing')->latest('first_seen_at')->firstOrFail();
        expect($latest->first_seen_at->greaterThan($first->first_seen_at))->toBeTrue();

        $expectedExpiry = $latest->first_seen_at->copy()->addDays(30);
        expect($latest->expires_at->equalTo($expectedExpiry))->toBeTrue();
        expect((string) ShareBrowserSelection::query()->firstOrFail()->current_attribution_id)->toBe((string) $latest->getKey());

        $passive = app(ShareTrackingService::class)->captureRequest(wp2OutcomeRequest($cookie2));
        expect($passive)->toBeString()->not->toBe('');
        expect(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3);
        expect((string) ShareBrowserSelection::query()->firstOrFail()->current_attribution_id)->toBe((string) $latest->getKey());
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2CancelledSourceInheritanceCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'cancelinherit');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $service = app(ShareTrackingService::class);

        $cookie = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);
        $sourceKey = ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid());
        $source = $service->recordOutcome(DawahShareOutcomeType::EventRegistration, $sourceKey, null, $actor, wp2OutcomeRequest($cookie));
        expect($source)->not->toBeNull();

        $correction = app(ReverseShareOutcomeAction::class)->handle($sourceKey, 'registration_cancelled', $actor, wp2OutcomeRequest($cookie));
        expect($correction)->not->toBeNull();
        expect(wp2ConversionStatusValue(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $sourceKey))->firstOrFail()))->toBe('reversed');

        $childKey = ShareOutcomeBusinessKey::eventCheckin((string) Str::uuid());
        $child = $service->recordInheritedOutcome(DawahShareOutcomeType::EventCheckin, $childKey, $sourceKey, null, $actor, ['staff_assisted' => true]);

        expect($child)->toBeNull();
        expect(ShareSourceAttributionReceipt::query()->where('business_key', $childKey)->firstOrFail()->isAttributed())->toBeFalse();
        expect(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $childKey))->count())->toBe(0);
        expect(ShareEffectReceipt::query()->where('source_id', $childKey)->count())->toBe(0);
        expect(ShareSourceAttributionReceipt::query()->where('business_key', $sourceKey)->firstOrFail()->isAttributed())->toBeTrue();
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2FraudConfirmedInheritanceCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'fraudinherit');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $service = app(ShareTrackingService::class);

        $cookie = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);
        $sourceKey = ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid());
        expect($service->recordOutcome(DawahShareOutcomeType::EventRegistration, $sourceKey, null, $actor, wp2OutcomeRequest($cookie)))->not->toBeNull();

        $sourceConversion = AffiliateConversion::query()->where('idempotency_key', hash('sha256', $sourceKey))->firstOrFail();
        AffiliateFraudSignal::query()->create([
            'affiliate_id' => $sourceConversion->affiliate_id,
            'conversion_id' => $sourceConversion->getKey(),
            'rule_code' => 'WP2_CONFIRMED',
            'risk_points' => 95,
            'severity' => FraudSeverity::High->value,
            'description' => 'WP2 confirmed fraud probe.',
            'status' => FraudSignalStatus::Confirmed->value,
            'detected_at' => now(),
            'confirmed_at' => now(),
        ]);

        $childKey = ShareOutcomeBusinessKey::eventCheckin((string) Str::uuid());
        expect($service->recordInheritedOutcome(DawahShareOutcomeType::EventCheckin, $childKey, $sourceKey, null, $actor))->toBeNull();
        expect(ShareSourceAttributionReceipt::query()->where('business_key', $childKey)->firstOrFail()->isAttributed())->toBeFalse();
        expect(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $childKey))->count())->toBe(0);
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2CorrectionDispatchCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'corrdispatch');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $service = app(ShareTrackingService::class);

        $cookie = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);
        $revokedKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());
        $validKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());
        expect($service->recordOutcome(DawahShareOutcomeType::EventSave, $revokedKey, null, $actor, wp2OutcomeRequest($cookie)))->not->toBeNull();
        expect($service->recordOutcome(DawahShareOutcomeType::EventSave, $validKey, null, $actor, wp2OutcomeRequest($cookie)))->not->toBeNull();

        expect(app(ReverseShareOutcomeAction::class)->handle($revokedKey, 'registration_cancelled', $actor, wp2OutcomeRequest($cookie)))->not->toBeNull();

        $processed = app(DispatchShareEffectReceiptsAction::class)->handle(10);
        expect($processed)->toBe(1);
        expect(ShareEffectReceipt::query()->where('source_id', $revokedKey)->firstOrFail()->status)->toBe(ShareEffectStatus::Cancelled);
        expect(ShareEffectReceipt::query()->where('source_id', $validKey)->firstOrFail()->status)->toBe(ShareEffectStatus::Succeeded);
        expect((int) $link->fresh()->conversions)->toBe(1);
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2LeaseFencingCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'leasefence');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $service = app(ShareTrackingService::class);

        $cookie = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);
        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());
        expect($service->recordOutcome(DawahShareOutcomeType::EventSave, $businessKey, null, $actor, wp2OutcomeRequest($cookie)))->not->toBeNull();

        $effect = ShareEffectReceipt::query()->where('source_id', $businessKey)->firstOrFail();
        $effect->forceFill([
            'status' => ShareEffectStatus::Leased,
            'lease_token' => (string) Str::uuid(),
            'leased_by' => 'worker-wp2-stale',
            'lease_expires_at' => now()->subMinute(),
            'attempts' => 1,
        ])->save();

        expect(app(DispatchShareEffectReceiptsAction::class)->handle(10))->toBe(1);
        expect(ShareEffectReceipt::query()->whereKey($effect->getKey())->firstOrFail()->status)->toBe(ShareEffectStatus::Succeeded);
        expect((int) $link->fresh()->conversions)->toBe(1);

        expect(app(DispatchShareEffectReceiptsAction::class)->handle(10))->toBe(0);
        expect((int) $link->fresh()->conversions)->toBe(1);
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2BrowserAbaDifferentSharersCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'abadiff');

    try {
        $pageUrl = route('events.index');
        $otherPageUrl = route('institutions.index');
        $sharerA = User::factory()->create(['email' => 'wp2-aba-a@example.test']);
        $sharerB = User::factory()->create(['email' => 'wp2-aba-b@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-aba-actor@example.test']);
        $linkA = wp2OutcomeLink($sharerA, $pageUrl);
        $linkB = wp2OutcomeLink($sharerB, $pageUrl);
        $service = app(ShareTrackingService::class);

        expect((string) $linkA->getKey())->not->toBe((string) $linkB->getKey())
            ->and((string) $linkA->affiliate_id)->not->toBe((string) $linkB->affiliate_id);

        $cookieA1 = wp2OutcomeLanding($pageUrl.'?share='.$linkA->custom_slug);

        expect($cookieA1)->toBeString()->not->toBe('')
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(1)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1);

        $winnerA1 = AffiliateAttribution::query()->where('attribution_type', 'landing')->firstOrFail();
        $selectionA1 = ShareBrowserSelection::query()->firstOrFail();

        expect((string) $selectionA1->current_attribution_id)->toBe((string) $winnerA1->getKey())
            ->and((string) $winnerA1->affiliate_link_id)->toBe((string) $linkA->getKey());

        $acceptedA1 = $selectionA1->accepted_at;
        $firstSeenA1 = $winnerA1->first_seen_at;
        $expiresA1 = $winnerA1->expires_at;

        expect($winnerA1->channel)->toBeNull()
            ->and((string) $winnerA1->origin)->toBe('web')
            ->and($acceptedA1->equalTo($firstSeenA1))->toBeTrue()
            ->and($expiresA1->equalTo($firstSeenA1->copy()->addDays(30)))->toBeTrue();

        $visitTouchedA1 = AffiliateTouchpoint::query()
            ->where('touchpoint_type', 'visit')
            ->where('affiliate_link_id', $linkA->getKey())
            ->firstOrFail()->touched_at;

        expect($visitTouchedA1->equalTo($firstSeenA1))->toBeTrue();

        $testCase->travel(1)->minutes();

        $cookieB = wp2OutcomeLanding($pageUrl.'?share='.$linkB->custom_slug, $cookieA1);

        expect($cookieB)->toBeString()->not->toBe('')->not->toBe($cookieA1)
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(2)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(2);

        $winnerB = AffiliateAttribution::query()->where('affiliate_link_id', $linkB->getKey())->firstOrFail();
        $selectionB = ShareBrowserSelection::query()->firstOrFail();
        $acceptedB = $selectionB->accepted_at;

        expect((string) $selectionB->current_attribution_id)->toBe((string) $winnerB->getKey())
            ->and($acceptedB->equalTo($acceptedA1))->toBeFalse()
            ->and($acceptedB->greaterThan($acceptedA1))->toBeTrue();

        $testCase->travel(1)->minutes();

        $cookieA2 = wp2OutcomeLanding($pageUrl.'?share='.$linkA->custom_slug, $cookieB);

        expect($cookieA2)->toBeString()->not->toBe('')->not->toBe($cookieB)
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(2);

        $winnerA2 = AffiliateAttribution::query()
            ->where('attribution_type', 'landing')
            ->where('affiliate_link_id', $linkA->getKey())
            ->latest('first_seen_at')
            ->firstOrFail();
        $selectionA2 = ShareBrowserSelection::query()->firstOrFail();
        $acceptedA2 = $selectionA2->accepted_at;

        expect((string) $selectionA2->current_attribution_id)->toBe((string) $winnerA2->getKey())
            ->and((string) $winnerA2->getKey())->not->toBe((string) $winnerA1->getKey())
            ->and((string) $winnerA2->affiliate_link_id)->toBe((string) $linkA->getKey())
            ->and((string) $winnerA2->affiliate_id)->toBe((string) $linkA->affiliate_id)
            ->and($winnerA2->channel)->toBeNull()
            ->and((string) $winnerA2->origin)->toBe('web')
            ->and($acceptedA2->equalTo($acceptedB))->toBeFalse()
            ->and($acceptedA2->greaterThan($acceptedB))->toBeTrue()
            ->and($winnerA2->first_seen_at->equalTo($acceptedA2))->toBeTrue()
            ->and($winnerA2->expires_at->equalTo($winnerA2->first_seen_at->copy()->addDays(30)))->toBeTrue()
            ->and($winnerA1->fresh()->first_seen_at->equalTo($firstSeenA1))->toBeTrue()
            ->and($winnerA1->fresh()->expires_at->equalTo($expiresA1))->toBeTrue()
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->where('affiliate_link_id', $linkA->getKey())->count())->toBe(1)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->where('affiliate_link_id', $linkB->getKey())->count())->toBe(1);

        $resolvedStale = $service->resolveActiveAttribution(wp2OutcomeRequest($cookieB));

        expect($resolvedStale)->not->toBeNull()
            ->and((string) $resolvedStale->id)->toBe((string) $winnerA2->getKey());

        $resolvedLatest = $service->resolveActiveAttribution(wp2OutcomeRequest($cookieA2));

        expect((string) $resolvedLatest->id)->toBe((string) $winnerA2->getKey());

        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());
        $outcome = $service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            $businessKey,
            null,
            $actor,
            wp2OutcomeRequest($cookieB),
        );

        expect($outcome)->not->toBeNull()
            ->and((string) $outcome->linkId)->toBe((string) $linkA->getKey());

        $receipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail();
        $conversion = AffiliateConversion::query()->where('idempotency_key', hash('sha256', $businessKey))->firstOrFail();

        expect((string) $receipt->attribution_id)->toBe((string) $winnerA2->getKey())
            ->and((string) $conversion->affiliate_link_id)->toBe((string) $linkA->getKey())
            ->and((string) $conversion->affiliate_attribution_id)->toBe((string) $winnerA2->getKey());

        $cookieA2Refresh = wp2OutcomeLanding($pageUrl.'?share='.$linkA->custom_slug, $cookieA2);

        expect($cookieA2Refresh)->toBeString()->not->toBe('')
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(2);

        $selectionRefresh = ShareBrowserSelection::query()->firstOrFail();

        expect((string) $selectionRefresh->current_attribution_id)->toBe((string) $winnerA2->getKey())
            ->and($selectionRefresh->accepted_at->equalTo($acceptedA2))->toBeTrue();

        $navSame = $service->captureRequest(Wp2RealDriverHarness::landingRequest($pageUrl, $cookieA2));

        expect($navSame)->toBeString()->not->toBe('')
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(2);

        expect((string) ShareBrowserSelection::query()->firstOrFail()->current_attribution_id)->toBe((string) $winnerA2->getKey())
            ->and(ShareBrowserSelection::query()->firstOrFail()->accepted_at->equalTo($acceptedA2))->toBeTrue();

        $navOther = $service->captureRequest(Wp2RealDriverHarness::landingRequest($otherPageUrl, $cookieA2));

        expect($navOther)->toBeString()->not->toBe('')
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3);

        expect((string) ShareBrowserSelection::query()->firstOrFail()->current_attribution_id)->toBe((string) $winnerA2->getKey())
            ->and(ShareBrowserSelection::query()->firstOrFail()->accepted_at->equalTo($acceptedA2))->toBeTrue()
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(3);

        $navVisit = AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->latest('touched_at')->firstOrFail();

        expect((string) $navVisit->affiliate_link_id)->toBe((string) $linkA->getKey())
            ->and((string) $navVisit->affiliate_attribution_id)->toBe((string) $winnerA2->getKey());

        $navStale = $service->captureRequest(Wp2RealDriverHarness::landingRequest($pageUrl, $cookieB));

        expect($navStale)->toBeString()->not->toBe('')
            ->and((string) ShareBrowserSelection::query()->firstOrFail()->current_attribution_id)->toBe((string) $winnerA2->getKey())
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3);

        $testCase->travelBack();
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2BrowserAbaSameSharerCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'abasame');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-aba-same@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-aba-same-actor@example.test']);
        $service = app(ShareTrackingService::class);

        $linkDataA = $service->createOrReuseLink($sharer, $pageUrl, 'Majlis', 'web');
        $linkA = AffiliateLink::query()->whereKey($linkDataA->id)->firstOrFail();
        $linkDataB = $service->createOrReuseLink($sharer, $pageUrl, 'Majlis', 'iosapp');
        $linkB = AffiliateLink::query()->whereKey($linkDataB->id)->firstOrFail();

        expect((string) $linkA->getKey())->not->toBe((string) $linkB->getKey())
            ->and((string) $linkA->affiliate_id)->toBe((string) $linkB->affiliate_id)
            ->and((string) $linkA->origin)->toBe('web')
            ->and((string) $linkB->origin)->toBe('iosapp');

        $cookieA1 = wp2OutcomeLanding($pageUrl.'?share='.$linkA->custom_slug);

        expect($cookieA1)->toBeString()->not->toBe('')
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(1)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1);

        $winnerA1 = AffiliateAttribution::query()->where('attribution_type', 'landing')->firstOrFail();
        $acceptedA1 = ShareBrowserSelection::query()->firstOrFail()->accepted_at;

        expect((string) $winnerA1->affiliate_link_id)->toBe((string) $linkA->getKey())
            ->and((string) $winnerA1->origin)->toBe('web');

        $testCase->travel(1)->minutes();

        $cookieB = wp2OutcomeLanding($pageUrl.'?share='.$linkB->custom_slug, $cookieA1);

        expect($cookieB)->toBeString()->not->toBe('')->not->toBe($cookieA1)
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(2)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(2);

        $winnerB = AffiliateAttribution::query()->where('affiliate_link_id', $linkB->getKey())->firstOrFail();
        $acceptedB = ShareBrowserSelection::query()->firstOrFail()->accepted_at;

        expect((string) ShareBrowserSelection::query()->firstOrFail()->current_attribution_id)->toBe((string) $winnerB->getKey())
            ->and((string) $winnerB->origin)->toBe('iosapp')
            ->and($acceptedB->greaterThan($acceptedA1))->toBeTrue();

        $testCase->travel(1)->minutes();

        $cookieA2 = wp2OutcomeLanding($pageUrl.'?share='.$linkA->custom_slug, $cookieB);

        expect($cookieA2)->toBeString()->not->toBe('')->not->toBe($cookieB)
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(2);

        $winnerA2 = AffiliateAttribution::query()
            ->where('attribution_type', 'landing')
            ->where('affiliate_link_id', $linkA->getKey())
            ->latest('first_seen_at')
            ->firstOrFail();
        $selectionA2 = ShareBrowserSelection::query()->firstOrFail();
        $acceptedA2 = $selectionA2->accepted_at;

        expect((string) $selectionA2->current_attribution_id)->toBe((string) $winnerA2->getKey())
            ->and((string) $winnerA2->getKey())->not->toBe((string) $winnerA1->getKey())
            ->and((string) $winnerA2->affiliate_id)->toBe((string) $linkA->affiliate_id)
            ->and((string) $winnerA2->origin)->toBe('web')
            ->and($winnerA2->channel)->toBeNull()
            ->and($acceptedA2->greaterThan($acceptedB))->toBeTrue()
            ->and($winnerA2->first_seen_at->equalTo($acceptedA2))->toBeTrue()
            ->and($winnerA2->expires_at->equalTo($winnerA2->first_seen_at->copy()->addDays(30)))->toBeTrue();

        $resolvedStale = $service->resolveActiveAttribution(wp2OutcomeRequest($cookieB));

        expect($resolvedStale)->not->toBeNull()
            ->and((string) $resolvedStale->id)->toBe((string) $winnerA2->getKey());

        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());
        $outcome = $service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            $businessKey,
            null,
            $actor,
            wp2OutcomeRequest($cookieB),
        );

        expect($outcome)->not->toBeNull()
            ->and((string) $outcome->linkId)->toBe((string) $linkA->getKey());

        expect((string) ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail()->attribution_id)
            ->toBe((string) $winnerA2->getKey());

        $cookieA2Refresh = wp2OutcomeLanding($pageUrl.'?share='.$linkA->custom_slug, $cookieA2);

        expect($cookieA2Refresh)->toBeString()->not->toBe('')
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(2)
            ->and((string) ShareBrowserSelection::query()->firstOrFail()->current_attribution_id)->toBe((string) $winnerA2->getKey())
            ->and(ShareBrowserSelection::query()->firstOrFail()->accepted_at->equalTo($acceptedA2))->toBeTrue();

        $navSame = $service->captureRequest(Wp2RealDriverHarness::landingRequest($pageUrl, $cookieA2));

        expect($navSame)->toBeString()->not->toBe('')
            ->and((string) ShareBrowserSelection::query()->firstOrFail()->current_attribution_id)->toBe((string) $winnerA2->getKey())
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3);

        $testCase->travelBack();
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

function wp2BrowserChannelChangeCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = wp2OutcomeWorker($driver, 'chanedge');

    try {
        $pageUrl = route('events.index');
        $otherPageUrl = route('institutions.index');
        $sharer = User::factory()->create(['email' => 'wp2-chan@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-chan-actor@example.test']);
        $link = wp2OutcomeLink($sharer, $pageUrl);
        $service = app(ShareTrackingService::class);

        $cookieNull = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug);

        expect($cookieNull)->toBeString()->not->toBe('')
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(1)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1);

        $winnerNull = AffiliateAttribution::query()->where('attribution_type', 'landing')->firstOrFail();
        $acceptedNull = ShareBrowserSelection::query()->firstOrFail()->accepted_at;
        $firstSeenNull = $winnerNull->first_seen_at;
        $expiresNull = $winnerNull->expires_at;

        expect($winnerNull->channel)->toBeNull()
            ->and($acceptedNull->equalTo($firstSeenNull))->toBeTrue()
            ->and($expiresNull->equalTo($firstSeenNull->copy()->addDays(30)))->toBeTrue();

        $testCase->travel(1)->minutes();

        $cookieWa = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug.'&channel=whatsapp', $cookieNull);

        expect($cookieWa)->toBeString()->not->toBe('')->not->toBe($cookieNull)
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(2)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1);

        $winnerWa = AffiliateAttribution::query()->where('channel', 'whatsapp')->firstOrFail();
        $selectionWa = ShareBrowserSelection::query()->firstOrFail();
        $acceptedWa = $selectionWa->accepted_at;

        expect((string) $selectionWa->current_attribution_id)->toBe((string) $winnerWa->getKey())
            ->and((string) $winnerWa->affiliate_link_id)->toBe((string) $link->getKey())
            ->and($acceptedWa->greaterThan($acceptedNull))->toBeTrue()
            ->and($winnerWa->first_seen_at->equalTo($acceptedWa))->toBeTrue()
            ->and($winnerWa->expires_at->equalTo($winnerWa->first_seen_at->copy()->addDays(30)))->toBeTrue()
            ->and($winnerNull->fresh()->first_seen_at->equalTo($firstSeenNull))->toBeTrue()
            ->and($winnerNull->fresh()->expires_at->equalTo($expiresNull))->toBeTrue();

        $testCase->travel(1)->minutes();

        $cookieTg = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug.'&channel=telegram', $cookieWa);

        expect($cookieTg)->toBeString()->not->toBe('')->not->toBe($cookieWa)
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1);

        $winnerTg = AffiliateAttribution::query()->where('channel', 'telegram')->firstOrFail();
        $selectionTg = ShareBrowserSelection::query()->firstOrFail();
        $acceptedTg = $selectionTg->accepted_at;

        expect((string) $selectionTg->current_attribution_id)->toBe((string) $winnerTg->getKey())
            ->and($acceptedTg->greaterThan($acceptedWa))->toBeTrue()
            ->and($winnerTg->first_seen_at->equalTo($acceptedTg))->toBeTrue()
            ->and($winnerTg->expires_at->equalTo($winnerTg->first_seen_at->copy()->addDays(30)))->toBeTrue();

        $cookieTgRefresh = wp2OutcomeLanding($pageUrl.'?share='.$link->custom_slug.'&channel=telegram', $cookieTg);

        expect($cookieTgRefresh)->toBeString()->not->toBe('')
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1)
            ->and((string) ShareBrowserSelection::query()->firstOrFail()->current_attribution_id)->toBe((string) $winnerTg->getKey())
            ->and(ShareBrowserSelection::query()->firstOrFail()->accepted_at->equalTo($acceptedTg))->toBeTrue();

        $resolvedStale = $service->resolveActiveAttribution(wp2OutcomeRequest($cookieWa));

        expect($resolvedStale)->not->toBeNull()
            ->and((string) $resolvedStale->id)->toBe((string) $winnerTg->getKey());

        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());
        $outcome = $service->recordOutcome(
            DawahShareOutcomeType::EventSave,
            $businessKey,
            null,
            $actor,
            wp2OutcomeRequest($cookieWa),
        );

        expect($outcome)->not->toBeNull()
            ->and((string) $outcome->linkId)->toBe((string) $link->getKey());

        $receipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail();
        $conversion = AffiliateConversion::query()->where('idempotency_key', hash('sha256', $businessKey))->firstOrFail();

        expect((string) $receipt->attribution_id)->toBe((string) $winnerTg->getKey())
            ->and((string) $conversion->affiliate_attribution_id)->toBe((string) $winnerTg->getKey())
            ->and((string) $conversion->channel)->toBe('telegram');

        $navSame = $service->captureRequest(Wp2RealDriverHarness::landingRequest($pageUrl, $cookieTg));

        expect($navSame)->toBeString()->not->toBe('')
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3)
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1)
            ->and((string) ShareBrowserSelection::query()->firstOrFail()->current_attribution_id)->toBe((string) $winnerTg->getKey())
            ->and(ShareBrowserSelection::query()->firstOrFail()->accepted_at->equalTo($acceptedTg))->toBeTrue();

        $navOther = $service->captureRequest(Wp2RealDriverHarness::landingRequest($otherPageUrl, $cookieTg));

        expect($navOther)->toBeString()->not->toBe('')
            ->and(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(3)
            ->and((string) ShareBrowserSelection::query()->firstOrFail()->current_attribution_id)->toBe((string) $winnerTg->getKey())
            ->and(ShareBrowserSelection::query()->firstOrFail()->accepted_at->equalTo($acceptedTg))->toBeTrue()
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(2);

        $testCase->travelBack();
    } finally {
        wp2OutcomeTeardown($driver, $database, $previous);
    }
}

foreach (['pgsql', 'mariadb'] as $wp2Driver) {
    $label = $wp2Driver === 'pgsql' ? 'isolated PostgreSQL' : 'isolated MariaDB';

    it("credits once and replays the original decision on {$label}", function () use ($wp2Driver): void {
        wp2OutcomeCase($this, $wp2Driver);
    });

    it("freezes unattributed and blocks late credit on {$label}", function () use ($wp2Driver): void {
        wp2UnattributedCase($this, $wp2Driver);
    });

    it("rejects fraud-blocked conversions without credit on {$label}", function () use ($wp2Driver): void {
        wp2FraudRejectionCase($this, $wp2Driver);
    });

    it("rolls back conversion fraud accounting and receipt together on {$label}", function () use ($wp2Driver): void {
        wp2RollbackCase($this, $wp2Driver);
    });

    it("rolls back after an accounting-stage fault and retries exactly once on {$label}", function () use ($wp2Driver): void {
        wp2AccountingFaultCase($this, $wp2Driver);
    });

    it("recovers pending effects after commit exactly once on {$label}", function () use ($wp2Driver): void {
        wp2DispatchRecoveryCase($this, $wp2Driver);
    });

    it("honors TTL expiry and the rolling dedupe window on {$label}", function () use ($wp2Driver): void {
        wp2TtlDedupeCase($this, $wp2Driver);
    });

    it("inherits source provenance for late staff attendance on {$label}", function () use ($wp2Driver): void {
        wp2InheritanceCase($this, $wp2Driver);
    });

    it("reverses zero facts idempotently and guards posted money on {$label}", function () use ($wp2Driver): void {
        wp2CorrectionCase($this, $wp2Driver);
    });

    it("fails closed on malformed business keys on {$label}", function () use ($wp2Driver): void {
        wp2MalformedKeyCase($this, $wp2Driver);
    });

    it("credits the committed winner despite reversed cookie arrival on {$label}", function () use ($wp2Driver): void {
        wp2BrowserReversedArrivalCase($this, $wp2Driver);
    });

    it("clears selection on invalid tokens and blocks stale tabs on {$label}", function () use ($wp2Driver): void {
        wp2BrowserInvalidTokenCase($this, $wp2Driver);
    });

    it("mints fresh same-link windows without dedupe or navigation renewal on {$label}", function () use ($wp2Driver): void {
        wp2BrowserSameLinkTtlCase($this, $wp2Driver);
    });

    it("refuses inheritance from cancelled sources on {$label}", function () use ($wp2Driver): void {
        wp2CancelledSourceInheritanceCase($this, $wp2Driver);
    });

    it("refuses inheritance from confirmed-fraud sources on {$label}", function () use ($wp2Driver): void {
        wp2FraudConfirmedInheritanceCase($this, $wp2Driver);
    });

    it("cancels revoked effects while preserving valid projections on {$label}", function () use ($wp2Driver): void {
        wp2CorrectionDispatchCase($this, $wp2Driver);
    });

    it("reclaims expired leases once and fences stale workers on {$label}", function () use ($wp2Driver): void {
        wp2LeaseFencingCase($this, $wp2Driver);
    });

    it("freezes last-eligible A-B-A across sharers despite visit dedupe on {$label}", function () use ($wp2Driver): void {
        wp2BrowserAbaDifferentSharersCase($this, $wp2Driver);
    });

    it("freezes last-eligible A-B-A across same-sharer links and origins on {$label}", function () use ($wp2Driver): void {
        wp2BrowserAbaSameSharerCase($this, $wp2Driver);
    });

    it("freezes same-link channel changes while deduping identical refreshes on {$label}", function () use ($wp2Driver): void {
        wp2BrowserChannelChangeCase($this, $wp2Driver);
    });
}

it('rolls back the whole going flow when outcome recording fails on isolated PostgreSQL', function (): void {
    wp2GoingCrashCase($this);
});
