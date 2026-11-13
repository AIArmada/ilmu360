<?php

declare(strict_types=1);

// Rw1 seams: the scalar gratitude digest Api, its provider binding, the
// reward_digest_slot morph alias + literal table, the three-writer claim, the
// thin root planner/command adapters, and a plan/cancel/dispatch round trip
// through the Api only.

use AIArmada\Rewards\Contracts\RewardGratitudePlanner;
use App\Features\Rewards\Actions\GratitudeDigests\CancelRewardDigestAction;
use App\Features\Rewards\Actions\GratitudeDigests\DispatchDueRewardDigestsAction;
use App\Features\Rewards\Actions\GratitudeDigests\GratitudeDigestAction;
use App\Features\Rewards\Actions\GratitudeDigests\PlanRewardGratitudeDigestAction;
use App\Features\Rewards\Api\GratitudeDigest;
use App\Features\Rewards\Api\GratitudeDigestSnapshot;
use App\Features\Rewards\FeatureServiceProvider as RewardsFeatureServiceProvider;
use App\Features\Rewards\Support\RewardsModel;
use App\Models\RewardDigestSlot;
use App\Platform\FeatureCatalog;
use App\Support\Rewards\DigestRewardGratitudePlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Rewards\Host\Support\RewardCharacterizationFixtures;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

final class Rw1FakeGratitudeDigest implements GratitudeDigest
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function plan(
        string $recipientType,
        string $recipientId,
        array $categories = ['awards'],
        ?DateTimeImmutable $decidedAt = null,
    ): GratitudeDigestSnapshot {
        $this->calls[] = ['plan', $recipientType, $recipientId, $categories, $decidedAt?->format(DATE_ATOM)];

        return $this->snapshot();
    }

    public function cancel(
        string $slotId,
        string $reason,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        bool $force = false,
        ?DateTimeImmutable $decidedAt = null,
    ): GratitudeDigestSnapshot {
        $this->calls[] = ['cancel', $slotId, $reason, $actorType, $actorId, $actorRole, $force, $decidedAt?->format(DATE_ATOM)];

        return $this->snapshot('cancelled');
    }

    public function cancelPendingForRecipient(
        string $recipientType,
        string $recipientId,
        string $reason,
        ?DateTimeImmutable $decidedAt = null,
    ): int {
        $this->calls[] = ['cancelPending', $recipientType, $recipientId, $reason, $decidedAt?->format(DATE_ATOM)];

        return 1;
    }

    public function dispatchDue(int $batch = 50, ?DateTimeImmutable $now = null): array
    {
        $this->calls[] = ['dispatchDue', $batch, $now?->format(DATE_ATOM)];

        return ['dispatched' => 1, 'reconciled' => 0, 'cancelled' => 0, 'skipped' => 0];
    }

    private function snapshot(string $status = 'planned'): GratitudeDigestSnapshot
    {
        return new GratitudeDigestSnapshot(
            id: 'slot-1',
            recipientType: 'user',
            recipientId: 'recipient-1',
            localDay: '2026-06-16',
            planKey: 'gratitude:recipient-1:2026-06-16',
            status: $status,
            categories: ['awards'],
            payload: ['total' => 0],
            timezone: 'UTC',
            dueAt: '2026-06-16T08:00:00+00:00',
            sentAt: null,
            cancelledAt: null,
            communicationId: null,
        );
    }
}

it('binds the gratitude digest Api to the feature action and keeps its surface scalar', function (): void {
    expect(app(GratitudeDigest::class))->toBeInstanceOf(GratitudeDigestAction::class);

    $api = new ReflectionClass(GratitudeDigest::class);

    expect($api->getMethod('plan')->getReturnType()?->getName())->toBe(GratitudeDigestSnapshot::class)
        ->and($api->getMethod('cancel')->getReturnType()?->getName())->toBe(GratitudeDigestSnapshot::class)
        ->and($api->getMethod('cancelPendingForRecipient')->getReturnType()?->getName())->toBe('int')
        ->and($api->getMethod('dispatchDue')->getReturnType()?->getName())->toBe('array');

    $snapshot = new ReflectionClass(GratitudeDigestSnapshot::class);

    expect($snapshot->isFinal())->toBeTrue()
        ->and($snapshot->isReadOnly())->toBeTrue();

    foreach ($snapshot->getConstructor()?->getParameters() ?? [] as $parameter) {
        $type = (string) $parameter->getType();

        expect($type)->not->toContain('Illuminate\\')
            ->and($type)->not->toContain('Carbon\\')
            ->and($type)->not->toContain('App\\Models\\')
            ->and($type)->not->toContain('App\\Support\\');
    }

    $sources = (string) file_get_contents(app_path('Features/Rewards/Api/GratitudeDigest.php'))
        .(string) file_get_contents(app_path('Features/Rewards/Api/GratitudeDigestSnapshot.php'));

    expect($sources)->not->toContain('Illuminate\\')
        ->and($sources)->not->toContain('Carbon\\')
        ->and($sources)->not->toContain('App\\Models\\')
        ->and($sources)->not->toContain('App\\Services\\')
        ->and($sources)->not->toContain('App\\Support\\');
});

it('claims reward_digest_slots with exactly the three gratitude digest writers', function (): void {
    $catalog = FeatureCatalog::load(base_path());
    $manifest = json_decode(
        (string) file_get_contents(base_path('app/Features/Rewards/feature.json')), true, 512, JSON_THROW_ON_ERROR,
    );
    $providers = require base_path('bootstrap/providers.php');

    expect($manifest['owns'] ?? null)->toBe([
        'table:reward_digest_slots' => [
            PlanRewardGratitudeDigestAction::class,
            DispatchDueRewardDigestsAction::class,
            CancelRewardDigestAction::class,
        ],
    ])
        ->and($catalog['owners']['table:reward_digest_slots'] ?? null)->toBe('Rewards')
        ->and(array_filter($catalog['owners'], static fn (string $owner): bool => $owner === 'Rewards'))->toHaveCount(1)
        ->and(array_keys($providers, RewardsFeatureServiceProvider::class, true))->toHaveCount(1);
});

it('resolves the root digest slot model through its morph alias and literal table', function (): void {
    $defaults = (new ReflectionClass(RewardDigestSlot::class))->getDefaultProperties();

    expect(RewardsModel::digestSlot())->toBe(RewardDigestSlot::class)
        ->and((new RewardDigestSlot)->getMorphClass())->toBe('reward_digest_slot')
        ->and($defaults['table'] ?? null)->toBe('reward_digest_slots')
        ->and((new RewardDigestSlot)->getTable())->toBe('reward_digest_slots');
});

it('routes the package planner contract and dispatch command through the feature Api', function (): void {
    $fake = new Rw1FakeGratitudeDigest;
    $this->app->instance(GratitudeDigest::class, $fake);

    $planner = $this->app->make(RewardGratitudePlanner::class);

    expect($planner)->toBeInstanceOf(DigestRewardGratitudePlanner::class);

    $planner->planFor('user', 'recipient-1', ['awards', 'badges'], CarbonImmutable::parse('2026-06-15T10:00:00Z'));
    $planner->cancelPendingFor('user', 'recipient-1', 'award-reversed', CarbonImmutable::parse('2026-06-15T11:00:00Z'));

    expect($fake->calls[0] ?? null)
        ->toBe(['plan', 'user', 'recipient-1', ['awards', 'badges'], '2026-06-15T10:00:00+00:00'])
        ->and($fake->calls[1] ?? null)
        ->toBe(['cancelPending', 'user', 'recipient-1', 'award-reversed', '2026-06-15T11:00:00+00:00']);

    $exit = Artisan::call('rewards:dispatch-gratitude-digests', ['--batch' => 7]);

    expect($exit)->toBe(0)
        ->and($fake->calls[2] ?? null)->toBe(['dispatchDue', 7, null])
        ->and(Artisan::output())->toContain('Digests dispatched: 1');
});

it('round-trips plan, cancel and dispatch through the scalar Api', function (): void {
    $api = app(GratitudeDigest::class);
    $user = RewardCharacterizationFixtures::digestUser();

    $planned = $api->plan(
        'user', (string) $user->getKey(), ['awards'], new DateTimeImmutable('2026-06-15T10:00:00Z'),
    );

    expect($planned->status)->toBe('planned')
        ->and($planned->recipientId)->toBe((string) $user->getKey())
        ->and($planned->categories)->toBe(['awards'])
        ->and($planned->payload['total'])->toBe(0)
        ->and($planned->dueAt)->toBe('2026-06-16T08:00:00+00:00');

    $cancelled = $api->cancel(
        $planned->id,
        'member-asked-to-stop',
        'user',
        RewardLedgerFixtures::staffId(),
        'staff',
        true,
        new DateTimeImmutable('2026-06-15T11:00:00Z'),
    );

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->cancelledAt)->toBe('2026-06-15T11:00:00+00:00');

    $outcome = $api->dispatchDue(50, new DateTimeImmutable((string) $planned->dueAt));

    expect($outcome)->toBe(['dispatched' => 0, 'reconciled' => 0, 'cancelled' => 0, 'skipped' => 0]);

    $second = RewardCharacterizationFixtures::digestUser();
    $pending = $api->plan(
        'user', (string) $second->getKey(), ['awards'], new DateTimeImmutable('2026-06-15T10:00:00Z'),
    );

    expect($api->cancelPendingForRecipient(
        'user',
        (string) $second->getKey(),
        'award-reversed',
        new DateTimeImmutable('2026-06-15T11:00:00Z'),
    ))->toBe(1);

    expect(RewardDigestSlot::query()->whereKey($pending->id)->firstOrFail()->status)
        ->toBe(RewardDigestSlot::STATUS_CANCELLED);
});
