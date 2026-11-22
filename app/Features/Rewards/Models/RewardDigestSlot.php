<?php

declare(strict_types=1);

namespace App\Features\Rewards\Models;

use App\Features\Rewards\Actions\AssertRewardDigestSlotLifecycleAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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

    #[\Override]
    protected $table = 'reward_digest_slots';

    #[\Override]
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
        static::updating(static function (RewardDigestSlot $record): void {
            app(AssertRewardDigestSlotLifecycleAction::class)->updating($record);
        });

        static::deleting(static function (): void {
            app(AssertRewardDigestSlotLifecycleAction::class)->deleting();
        });
    }
}
