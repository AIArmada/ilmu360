<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Atomic recipient-local-day gratitude digest slot.
 *
 * One slot per recipient per local day across all categories and
 * channels (unique recipient + local_day): planning the same day twice
 * merges new categories into the stored slot and returns it, so the
 * single daily send covers every planned section. The plan_key is
 * stable (`gratitude:{recipient_id}:{local_day}`) and doubles as the
 * managed communication idempotency key, so crash recovery adopts
 * instead of duplicating sends. Payloads are coarse (section counts +
 * window), recomputed before dispatch; corrections cancel or recalculate
 * pending material instead of sending stale thanks.
 *
 * Lifecycle: planned → sending → sent, or → cancelled; sending may
 * return to planned when a failed send or a correction must redeliver
 * with a recalculated payload. Sent and cancelled are terminal.
 *
 * @property array<int, string> $categories
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $cancelled_at
 */
class RewardDigestSlot extends Model
{
    use HasUuids;

    public const string STATUS_PLANNED = 'planned';

    public const string STATUS_SENDING = 'sending';

    public const string STATUS_SENT = 'sent';

    public const string STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'local_day',
        'plan_key',
        'status',
        'categories',
        'payload',
        'timezone',
        'due_at',
        'sent_at',
        'cancelled_at',
        'communication_id',
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'payload' => 'array',
            'due_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (RewardDigestSlot $slot): void {
            foreach (['recipient_type', 'recipient_id', 'local_day', 'plan_key', 'timezone', 'due_at'] as $field) {
                if ($slot->isDirty($field)) {
                    throw new LogicException("Reward digest slot [{$field}] is frozen at plan time.");
                }
            }

            foreach (['sent_at', 'cancelled_at'] as $field) {
                if ($slot->getOriginal($field) !== null && $slot->isDirty($field)) {
                    throw new LogicException("Reward digest slot [{$field}] is an immutable transition timestamp.");
                }
            }

            if ($slot->isDirty('status')) {
                $from = (string) $slot->getOriginal('status');
                $allowed = match ($from) {
                    self::STATUS_PLANNED => [self::STATUS_SENDING, self::STATUS_CANCELLED],
                    self::STATUS_SENDING => [self::STATUS_SENT, self::STATUS_CANCELLED, self::STATUS_PLANNED],
                    default => [],
                };

                if (! in_array((string) $slot->status, $allowed, true)) {
                    throw new LogicException('Invalid reward digest slot status transition.');
                }
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Reward digest slots cannot be deleted; cancel them instead.');
        });
    }
}
