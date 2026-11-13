<?php

declare(strict_types=1);

// Permanent race worker for RewardDigestRaceTest. Executed only as a standalone
// immediately when merely included, so test runners never execute it.

use App\Features\Rewards\Actions\GratitudeDigests\CancelRewardDigestAction;
use App\Features\Rewards\Actions\GratitudeDigests\DispatchDueRewardDigestsAction;
use App\Features\Rewards\Actions\GratitudeDigests\PlanRewardGratitudeDigestAction;
use App\Features\Communications\Api\ManagedNotificationDelivery;
use App\Features\Communications\Notifications\ManagedNotification;
use App\Features\Rewards\Services\ShareGratitudeDigestMessages;
use App\Models\RewardDigestSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\RealDriverFixture;

if (PHP_SAPI !== 'cli' || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$options = getopt('', [
    'database:',
    'mode:',
    'ready-dir:',
    'slot:',
]);

$fail = static function (string $message): never {
    echo json_encode(['ok' => false, 'error' => $message], JSON_THROW_ON_ERROR)."\n";

    exit(1);
};
$database = (string) ($options['database'] ?? '');
$mode = (string) ($options['mode'] ?? '');
$readyDir = (string) ($options['ready-dir'] ?? '');
$slot = (int) ($options['slot'] ?? -1);

if (preg_match('/^share_rewards_test_rewards_finance_[a-z0-9_]{1,27}$/', $database) !== 1) {
    $fail('Refusing a database outside the isolated rewards-finance namespace.');
}

if (! in_array($mode, ['plan', 'dispatch', 'cancel', 'cancel_slot', 'send_existing', 'reconcile', 'resolve'], true)
    || $readyDir === ''
    || ! in_array($slot, [0, 1], true)
) {
    $fail('Missing race job arguments.');
}

$payloadFile = $readyDir.'/payload.json';

if (! is_file($payloadFile)) {
    $fail('Missing race payload.');
}

$payload = json_decode((string) file_get_contents($payloadFile), true);

if (! is_array($payload)) {
    $fail('Race payload must be JSON.');
}

$basePath = realpath(__DIR__.'/../../../../');

if (! is_string($basePath) || ! is_file($basePath.'/bootstrap/app.php')) {
    $fail('Unable to locate the application base path.');
}

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$workerConfig = RealDriverFixture::connectionConfig($database);

config(['database.connections.rewards_digest_race' => $workerConfig]);
DB::purge('rewards_digest_race');
DB::setDefaultConnection('rewards_digest_race');

$slotPayload = isset($payload['slots']) && is_array($payload['slots']) ? ($payload['slots'][$slot] ?? null) : null;
$p = is_array($slotPayload) ? $slotPayload : (isset($payload[$mode]) && is_array($payload[$mode]) ? $payload[$mode] : $payload);

if (($payload['controlled'] ?? false) !== true) {
    // File barrier: both slots start the critical section together.
    file_put_contents($readyDir.'/ready-'.$slot, (string) $slot);

    $deadline = microtime(true) + 25.0;

    while ((! is_file($readyDir.'/ready-0') || ! is_file($readyDir.'/ready-1')) && microtime(true) < $deadline) {
        usleep(20000);
    }

    if (! is_file($readyDir.'/ready-0') || ! is_file($readyDir.'/ready-1')) {
        $fail('Race barrier timed out.');
    }

    usleep(50000 * $slot);
}

$markReady = static function (string $name) use ($readyDir): void {
    file_put_contents($readyDir.'/'.$name, $name);
};
$waitForRelease = static function (string $name) use ($readyDir, $fail): void {
    $releasePath = $readyDir.'/'.$name;
    $deadline = microtime(true) + 30.0;

    while (! is_file($releasePath) && microtime(true) < $deadline) {
        usleep(20000);
    }

    if (! is_file($releasePath)) {
        $fail('Controlled query barrier timed out waiting for '.$name.'.');
    }
};

$workerBarriers = isset($payload['worker_barriers'][$slot]) && is_array($payload['worker_barriers'][$slot])
    ? $payload['worker_barriers'][$slot]
    : [];
$pauseAfterDeliveryLock = $workerBarriers['pause_after_delivery_lock'] ?? null;

if (is_array($pauseAfterDeliveryLock)) {
    $lockCount = 0;
    DB::connection()->listen(function (QueryExecuted $event) use (
        &$lockCount,
        $pauseAfterDeliveryLock,
        $markReady,
        $waitForRelease,
    ): void {
        $query = strtolower($event->sql);

        if (! str_contains($query, 'communication_deliveries') || ! str_contains($query, 'for update')) {
            return;
        }

        $queryKind = (string) ($pauseAfterDeliveryLock['query_kind'] ?? 'single');
        if ($queryKind === 'single' && ! str_contains($query, 'limit 1')) {
            return;
        }

        if ($queryKind === 'aggregate'
            && (! str_contains($query, 'communication_id') || ! str_contains($query, 'order by'))
        ) {
            return;
        }

        $targetDeliveryId = $pauseAfterDeliveryLock['delivery_id'] ?? null;
        if (is_string($targetDeliveryId) && ! in_array($targetDeliveryId, $event->bindings, true)) {
            return;
        }

        $targetCommunicationId = $pauseAfterDeliveryLock['communication_id'] ?? null;
        if (is_string($targetCommunicationId) && ! in_array($targetCommunicationId, $event->bindings, true)) {
            return;
        }

        $lockCount++;
        if ($lockCount !== (int) ($pauseAfterDeliveryLock['occurrence'] ?? 1)) {
            return;
        }

        $markReady((string) $pauseAfterDeliveryLock['marker']);
        $waitForRelease((string) $pauseAfterDeliveryLock['release']);
    });
}

$pauseAfterCancelUpdate = $workerBarriers['pause_after_cancel_update'] ?? null;
if (is_array($pauseAfterCancelUpdate)) {
    DB::connection()->listen(function (QueryExecuted $event) use (
        $pauseAfterCancelUpdate,
        $markReady,
        $waitForRelease,
    ): void {
        $query = strtolower($event->sql);

        if (! str_contains($query, 'update')
            || ! str_contains($query, 'communication_deliveries')
            || ! in_array('cancelled', $event->bindings, true)
        ) {
            return;
        }

        $markReady((string) $pauseAfterCancelUpdate['marker']);
        $waitForRelease((string) $pauseAfterCancelUpdate['release']);
    });
}

$beforeDeliveryLock = $workerBarriers['before_delivery_lock'] ?? null;
if (is_array($beforeDeliveryLock)) {
    DB::connection()->beforeExecuting(function (string $query, array $bindings) use (
        $beforeDeliveryLock,
        $markReady,
    ): void {
        $query = strtolower($query);

        if (! str_contains($query, 'communication_deliveries') || ! str_contains($query, 'for update')) {
            return;
        }

        $queryKind = (string) ($beforeDeliveryLock['query_kind'] ?? 'aggregate');
        if ($queryKind === 'aggregate'
            && (! str_contains($query, 'communication_id') || ! str_contains($query, 'order by'))
        ) {
            return;
        }

        $targetDeliveryId = $beforeDeliveryLock['delivery_id'] ?? null;
        if (is_string($targetDeliveryId) && ! in_array($targetDeliveryId, $bindings, true)) {
            return;
        }

        $targetCommunicationId = $beforeDeliveryLock['communication_id'] ?? null;
        if (is_string($targetCommunicationId) && ! in_array($targetCommunicationId, $bindings, true)) {
            return;
        }

        $markReady((string) $beforeDeliveryLock['marker']);
    });
}

$beforeDeliveryUpdate = $workerBarriers['before_delivery_update'] ?? null;
if (is_array($beforeDeliveryUpdate)) {
    DB::connection()->beforeExecuting(function (string $query, array $bindings) use (
        $beforeDeliveryUpdate,
        $markReady,
    ): void {
        $query = strtolower($query);
        $targetDeliveryId = $beforeDeliveryUpdate['delivery_id'] ?? null;

        if (! str_contains($query, 'update')
            || ! str_contains($query, 'communication_deliveries')
            || ! is_string($targetDeliveryId)
            || ! in_array($targetDeliveryId, $bindings, true)
        ) {
            return;
        }

        $markReady((string) $beforeDeliveryUpdate['marker']);
    });
}

$pauseMessageSending = $workerBarriers['pause_message_sending'] ?? null;
if (is_array($pauseMessageSending)) {
    Event::listen(MessageSending::class, static function () use ($pauseMessageSending, $markReady, $waitForRelease): void {
        $markReady((string) $pauseMessageSending['marker']);
        $waitForRelease((string) $pauseMessageSending['release']);
    });
}

if (isset($p['provider_marker']) && is_string($p['provider_marker'])) {
    $providerMarker = $readyDir.'/'.$p['provider_marker'];
    Event::listen(MessageSent::class, static function () use ($providerMarker): void {
        file_put_contents($providerMarker, "sent\n", FILE_APPEND);
    });
}

try {
    $result = match ($mode) {
        'plan' => (function () use ($p): array {
            $slotRow = PlanRewardGratitudeDigestAction::run(
                'user',
                (string) $p['member_id'],
                (array) $p['categories'],
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'slot_id' => (string) $slotRow->getKey(), 'categories' => array_values((array) $slotRow->categories)];
        })(),
        'dispatch' => (function () use ($p): array {
            if (($p['in_memory_mail'] ?? false) === true) {
                config(['mail.default' => 'array']);
            }

            $outcome = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse((string) $p['now']));

            return ['ok' => true, 'outcome' => $outcome];
        })(),
        'cancel' => (function () use ($p): array {
            $count = CancelRewardDigestAction::cancelPendingForRecipient(
                'user',
                (string) $p['member_id'],
                (string) $p['reason'],
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'cancelled' => $count];
        })(),
        'cancel_slot' => (function () use ($p): array {
            try {
                $slotRow = CancelRewardDigestAction::run(
                    (string) $p['slot_id'],
                    (string) $p['reason'],
                    (string) ($p['actor_type'] ?? 'system'),
                    is_string($p['actor_id'] ?? null) ? $p['actor_id'] : null,
                    (string) ($p['actor_role'] ?? 'system'),
                    (bool) ($p['force'] ?? false),
                    CarbonImmutable::parse((string) $p['decided_at']),
                );

                return ['ok' => true, 'outcome' => 'cancelled-or-recalculated', 'slot_status' => (string) $slotRow->getAttribute('status')];
            } catch (InvalidArgumentException $exception) {
                return ['ok' => true, 'outcome' => 'refused', 'message' => $exception->getMessage()];
            }
        })(),
        'send_existing' => (function () use ($p): array {
            config(['mail.default' => 'array']);

            $user = User::query()->whereKey((string) $p['user_id'])->firstOrFail();
            $slotRow = RewardDigestSlot::query()->whereKey((string) $p['slot_id'])->firstOrFail();
            $deliveries = \AIArmada\Communications\Models\CommunicationDelivery::query()
                ->where('communication_id', (string) $p['communication_id'])
                ->orderBy('id')
                ->get();
            $deliveryIdsByChannel = [];

            foreach ($deliveries as $delivery) {
                $deliveryIdsByChannel[(string) $delivery->channel] = (string) $delivery->getKey();
            }

            $notification = new ManagedNotification(app(ShareGratitudeDigestMessages::class)->build((array) $p['summary']));
            $notification->withCommunicationContext((string) $p['communication_id'], $deliveryIdsByChannel);
            Notification::sendNow($user, $notification, array_keys($deliveryIdsByChannel));

            return ['ok' => true, 'outcome' => 'send-finished', 'slot_status' => (string) $slotRow->status];
        })(),
        'reconcile' => (function () use ($p): array {
            $outcome = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse((string) $p['now']));

            return ['ok' => true, 'outcome' => $outcome];
        })(),
        'resolve' => (function () use ($p): array {
            $resolution = app(ManagedNotificationDelivery::class)->resolveAmbiguousExternalDelivery(
                (string) $p['delivery_id'],
                (string) ($p['decision'] ?? 'sent'),
                (string) ($p['actor_type'] ?? 'user'),
                is_string($p['actor_id'] ?? null) ? $p['actor_id'] : null,
                (string) ($p['actor_role'] ?? 'admin'),
                is_string($p['provider_message_id'] ?? null) ? $p['provider_message_id'] : null,
                is_string($p['reason'] ?? null) ? $p['reason'] : null,
            );

            return ['ok' => true, 'delivery_id' => $resolution->deliveryId, 'status' => $resolution->status];
        })(),
    };

    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";

    exit(0);
} catch (Throwable $exception) {
    $fail(get_class($exception).': '.$exception->getMessage());
}
