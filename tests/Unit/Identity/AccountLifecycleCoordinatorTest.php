<?php

use App\Features\Identity\Api\AccountLifecycle;
use App\Features\Identity\Services\AccountLifecycleCoordinator;
use App\Shared\Auth\AccountLifecycleContributor;
use Tests\TestCase;

uses(TestCase::class);

it('binds the identity account lifecycle seam to the coordinator', function (): void {
    expect(app(AccountLifecycle::class))->toBeInstanceOf(AccountLifecycleCoordinator::class);
});

it('runs each lifecycle phase by priority and key', function (): void {
    $high = new RecordingAccountLifecycleContributor('high', 100, ['source' => 'high']);
    $low = new RecordingAccountLifecycleContributor('low', 10, ['source' => 'low']);
    $middle = new RecordingAccountLifecycleContributor('middle', 50);

    $lifecycle = new AccountLifecycleCoordinator([$low, $high, $middle]);

    expect(array_keys($lifecycle->snapshot('account')))
        ->toBe(['high', 'middle', 'low']);

    $lifecycle->detach('account');
    $lifecycle->restore('account', [
        'high' => ['source' => 'restored-high'],
        'low' => ['source' => 'restored-low'],
        'unknown' => ['ignored' => true],
    ]);
    $lifecycle->afterRestore('account');

    expect($high->calls)->toBe([
        'snapshot:account',
        'detach:account',
        'restore:account:{"source":"restored-high"}',
        'afterRestore:account',
    ])->and($middle->calls)->toBe([
        'snapshot:account',
        'detach:account',
        'restore:account:[]',
        'afterRestore:account',
    ])->and($low->calls)->toBe([
        'snapshot:account',
        'detach:account',
        'restore:account:{"source":"restored-low"}',
        'afterRestore:account',
    ]);
});

it('delegates to tagged contributors', function (): void {
    $high = new RecordingAccountLifecycleContributor('high', 100);
    $low = new RecordingAccountLifecycleContributor('low', 10);

    app()->instance('test.identity.lifecycle.high', $high);
    app()->instance('test.identity.lifecycle.low', $low);
    app()->tag(
        ['test.identity.lifecycle.high', 'test.identity.lifecycle.low'],
        AccountLifecycleContributor::TAG,
    );

    $lifecycle = app(AccountLifecycle::class);

    expect(array_keys($lifecycle->snapshot('account')))->toBe(['high', 'low']);
});

it('rejects duplicate contributor keys', function (): void {
    $first = new RecordingAccountLifecycleContributor('duplicate', 100);
    $second = new RecordingAccountLifecycleContributor('duplicate', 10);

    expect(fn (): AccountLifecycleCoordinator => new AccountLifecycleCoordinator([$first, $second]))
        ->toThrow(LogicException::class, 'Duplicate account lifecycle contributor key: duplicate');
});

final class RecordingAccountLifecycleContributor implements AccountLifecycleContributor
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @param  array<string, mixed>  $snapshotData
     */
    public function __construct(
        private readonly string $key,
        private readonly int $priority,
        private readonly array $snapshotData = [],
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function snapshot(string $accountId): array
    {
        $this->calls[] = "snapshot:{$accountId}";

        return $this->snapshotData;
    }

    public function detach(string $accountId): void
    {
        $this->calls[] = "detach:{$accountId}";
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->calls[] = "restore:{$accountId}:".json_encode($snapshot, JSON_THROW_ON_ERROR);
    }

    public function afterRestore(string $accountId): void
    {
        $this->calls[] = "afterRestore:{$accountId}";
    }
}
