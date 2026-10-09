<?php

declare(strict_types=1);

use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Jobs\DispatchManagedNotificationJob;
use AIArmada\Communications\Models\CommunicationAttempt;
use AIArmada\Communications\Models\CommunicationDelivery;
use App\Actions\Rewards\DispatchDueRewardDigestsAction;
use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardDigestSlot;
use App\Models\User;
use App\Notifications\Channels\DigestMailChannel;
use App\Notifications\ShareGratitudeDigestNotification;
use App\Services\Notifications\NotificationSettingsManager;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

final class ResolveCmdThrowTransport implements TransportInterface
{
    public function __construct(
        private readonly string $observer,
        private readonly ArrayTransport $inner,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        file_put_contents($this->observer, "accepted\n", FILE_APPEND);

        throw new TransportException('simulated transport timeout after provider acceptance');
    }

    public function messages(): Collection
    {
        return $this->inner->messages();
    }

    public function __toString(): string
    {
        return 'resolve-cmd-throw';
    }
}

function resolveCmdMailCount(): int
{
    $transport = Mail::mailer()->getSymfonyTransport();

    return method_exists($transport, 'messages') ? count($transport->messages()) : 0;
}

function resolveCmdObserverFile(string $prefix): string
{
    $file = sys_get_temp_dir().'/digest-mail-cmd-'.$prefix.'-'.getmypid().'.log';

    if (is_file($file)) {
        unlink($file);
    }

    $GLOBALS['__resolve_cmd_observers'][$file] = true;

    return $file;
}

function resolveCmdObserverCount(string $file): int
{
    if (! is_file($file)) {
        return 0;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return is_array($lines) ? count($lines) : 0;
}

function resolveCmdAdmin(string $role = 'admin'): User
{
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = User::factory()->create();
    $admin->assignRole($role);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $admin->refresh();
}

function resolveCmdAmbiguous(string $prefix, string $source): array
{
    // The dispatch queues its send job; without a fake the sync queue
    // would transmit during planning and no ambiguous attempt could be
    // built. The crash drain below runs through the real job boundary.
    $realQueue = Queue::getFacadeRoot();
    Queue::fake();

    $observer = resolveCmdObserverFile($prefix);

    try {
        [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, RewardProgramMode::Points);
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

        $user = User::factory()->create();

        app(NotificationSettingsManager::class)->save($user, [
            'settings' => [],
            'triggers' => ['share_gratitude_digest' => ['enabled' => true, 'cadence' => 'daily']],
        ]);

        $memberId = (string) $user->getKey();

        EnrollRewardMemberAction::run(
            $program->refresh(), 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        );

        $reader = new FakeRewardSourceFactReader;
        $reader->seed(RewardLedgerFixtures::fact($source, $memberId));

        $result = (new EvaluateRewardEntitlementAction($reader))->handle(
            $program->refresh(), RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
            $source, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
        );

        (new TransitionRewardAwardAction(null, $reader))->handle(
            $result->award, RewardAwardStatus::Approved, 'user', RewardLedgerFixtures::staffId(), 'staff', 'approve',
            null, null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
        );

        $outcome = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse('2026-06-23T08:00:00Z'));

        expect($outcome['dispatched'])->toBe(1);

        $slot = RewardDigestSlot::query()
            ->where('recipient_type', 'user')
            ->where('recipient_id', $memberId)
            ->firstOrFail();

        $communicationId = (string) $slot->communication_id;
        $mailDeliveryId = (string) CommunicationDelivery::query()
            ->where('communication_id', $communicationId)
            ->where('channel', DigestMailChannel::class)
            ->value('id');

        // Nothing transmitted while the dispatch job stayed queued.
        expect(CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->count())->toBe(0)
            ->and(resolveCmdMailCount())->toBe(0)
            ->and(resolveCmdObserverCount($observer))->toBe(0);

        $mailer = Mail::mailer();
        $previousTransport = $mailer->getSymfonyTransport();
        $inner = $previousTransport instanceof ArrayTransport ? $previousTransport : new ArrayTransport;
        $mailer->setSymfonyTransport(new ResolveCmdThrowTransport($observer, $inner));

        Queue::swap($realQueue);

        try {
            $payload = $slot->payload ?? [];
            $deliveries = CommunicationDelivery::query()
                ->where('communication_id', $communicationId)
                ->orderBy('id')
                ->get();

            $byChannel = [];

            foreach ($deliveries as $delivery) {
                $byChannel[(string) $delivery->channel] = (string) $delivery->getKey();
            }

            $notification = (new ShareGratitudeDigestNotification([
                'text' => (string) ($payload['text'] ?? ''),
                'counts' => (array) ($payload['counts'] ?? []),
                'sections' => (array) ($payload['sections'] ?? []),
                'window' => (array) ($payload['window'] ?? []),
                'plan_key' => (string) $slot->plan_key,
                'slot_id' => (string) $slot->getKey(),
            ]))->withCommunicationContext($communicationId, $byChannel);

            DispatchManagedNotificationJob::dispatchSync(
                notifiable: $user->refresh(),
                notification: $notification,
                channels: array_keys($byChannel),
            );

            $crashed = false;
        } catch (TransportException $exception) {
            $crashed = $exception->getMessage() === 'simulated transport timeout after provider acceptance';
        } finally {
            $mailer->setSymfonyTransport($previousTransport);
        }

        expect($crashed)->toBeTrue()
            ->and(resolveCmdObserverCount($observer))->toBe(1);

        $slot = $slot->refresh();

        // The committed attempt is retained unresolved and the job
        // failure handler preserves the ambiguous hold, never Failed.
        expect(CommunicationDelivery::query()->whereKey($mailDeliveryId)->firstOrFail()->status)->toBe(DeliveryStatus::Sending)
            ->and(CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->whereNull('responded_at')->whereNull('failed_at')->count())->toBe(1)
            ->and($slot->status)->toBe(RewardDigestSlot::STATUS_SENDING);

        return [$slot, $mailDeliveryId, $observer];
    } finally {
        Queue::swap($realQueue);
    }
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    foreach (array_keys($GLOBALS['__resolve_cmd_observers'] ?? []) as $file) {
        if (is_string($file) && is_file($file)) {
            unlink($file);
        }
    }

    $GLOBALS['__resolve_cmd_observers'] = [];
});

it('denies ambiguity resolution for an unknown operator', function (): void {
    [$slot, $mailDeliveryId] = resolveCmdAmbiguous('rs-unk', 'rs-unk-source-1');
    $before = resolveCmdMailCount();

    $exit = Artisan::call('rewards:resolve-digest-mail-ambiguity', [
        'delivery' => $mailDeliveryId,
        'decision' => 'sent',
        '--actor' => (string) Str::uuid(),
        '--provider-message-id' => 'observed-1',
    ]);

    expect($exit)->toBe(Command::FAILURE)
        ->and(CommunicationDelivery::query()->whereKey($mailDeliveryId)->firstOrFail()->status)->toBe(DeliveryStatus::Sending)
        ->and(CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->whereNull('responded_at')->whereNull('failed_at')->count())->toBe(1)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
        ->and(resolveCmdMailCount())->toBe($before);
});

it('denies ambiguity resolution for an operator without staff/admin role', function (): void {
    [$slot, $mailDeliveryId] = resolveCmdAmbiguous('rs-plain', 'rs-plain-source-1');
    $plain = User::factory()->create();
    $before = resolveCmdMailCount();

    $exit = Artisan::call('rewards:resolve-digest-mail-ambiguity', [
        'delivery' => $mailDeliveryId,
        'decision' => 'failed',
        '--actor' => (string) $plain->getKey(),
        '--reason' => 'provider logs show no acceptance',
    ]);

    expect($exit)->toBe(Command::FAILURE)
        ->and(CommunicationDelivery::query()->whereKey($mailDeliveryId)->firstOrFail()->status)->toBe(DeliveryStatus::Sending)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
        ->and(resolveCmdMailCount())->toBe($before);
});

it('requires observed evidence for each resolution decision', function (): void {
    [$slot, $mailDeliveryId] = resolveCmdAmbiguous('rs-ev', 'rs-ev-source-1');
    $admin = resolveCmdAdmin();
    $before = resolveCmdMailCount();

    $missingId = Artisan::call('rewards:resolve-digest-mail-ambiguity', [
        'delivery' => $mailDeliveryId,
        'decision' => 'sent',
        '--actor' => (string) $admin->getKey(),
    ]);

    $missingReason = Artisan::call('rewards:resolve-digest-mail-ambiguity', [
        'delivery' => $mailDeliveryId,
        'decision' => 'failed',
        '--actor' => (string) $admin->getKey(),
    ]);

    expect($missingId)->toBe(Command::INVALID)
        ->and($missingReason)->toBe(Command::INVALID)
        ->and(CommunicationDelivery::query()->whereKey($mailDeliveryId)->firstOrFail()->status)->toBe(DeliveryStatus::Sending)
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING)
        ->and(resolveCmdMailCount())->toBe($before);
});

it('resolves ambiguity as sent through the authorized command without sending', function (): void {
    [$slot, $mailDeliveryId, $observer] = resolveCmdAmbiguous('rs-sent', 'rs-sent-source-1');
    $admin = resolveCmdAdmin();
    $before = resolveCmdMailCount();

    $exit = Artisan::call('rewards:resolve-digest-mail-ambiguity', [
        'delivery' => $mailDeliveryId,
        'decision' => 'sent',
        '--actor' => (string) $admin->getKey(),
        '--provider-message-id' => 'cmd-observed-1',
    ]);

    $delivery = CommunicationDelivery::query()->whereKey($mailDeliveryId)->firstOrFail();
    $attempt = CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->firstOrFail();
    $resolution = is_array($attempt->metadata) ? ($attempt->metadata['digest_mail_resolution'] ?? null) : null;

    expect($exit)->toBe(Command::SUCCESS)
        ->and($delivery->status)->toBe(DeliveryStatus::Sent)
        ->and($delivery->provider_message_id)->toBe('cmd-observed-1')
        ->and($attempt->responded_at)->not->toBeNull()
        ->and($resolution)->toBeArray()
        ->and($resolution['decision'])->toBe('sent')
        ->and($resolution['actor_id'])->toBe((string) $admin->getKey())
        ->and($resolution['actor_role'])->toBe('admin')
        ->and($resolution['provider_message_id'])->toBe('cmd-observed-1')
        ->and($resolution['resolved_at'] ?? null)->not->toBeNull()
        ->and(resolveCmdMailCount())->toBe($before)
        ->and(resolveCmdObserverCount($observer))->toBe(1);
});

it('resolves ambiguity as failed through the authorized command without sending', function (): void {
    [$slot, $mailDeliveryId, $observer] = resolveCmdAmbiguous('rs-fail', 'rs-fail-source-1');
    $admin = resolveCmdAdmin('super_admin');
    $before = resolveCmdMailCount();

    $exit = Artisan::call('rewards:resolve-digest-mail-ambiguity', [
        'delivery' => $mailDeliveryId,
        'decision' => 'failed',
        '--actor' => (string) $admin->getKey(),
        '--reason' => 'smtp logs confirm no DATA acceptance',
    ]);

    $delivery = CommunicationDelivery::query()->whereKey($mailDeliveryId)->firstOrFail();
    $attempt = CommunicationAttempt::query()->where('delivery_id', $mailDeliveryId)->firstOrFail();
    $resolution = is_array($attempt->metadata) ? ($attempt->metadata['digest_mail_resolution'] ?? null) : null;

    expect($exit)->toBe(Command::SUCCESS)
        ->and($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($attempt->failed_at)->not->toBeNull()
        ->and($resolution)->toBeArray()
        ->and($resolution['decision'])->toBe('failed')
        ->and($resolution['actor_id'])->toBe((string) $admin->getKey())
        ->and($resolution['reason'])->toBe('smtp logs confirm no DATA acceptance')
        ->and(resolveCmdMailCount())->toBe($before)
        ->and(resolveCmdObserverCount($observer))->toBe(1);
});
