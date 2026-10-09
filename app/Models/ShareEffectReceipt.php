<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShareEffectStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ShareEffectReceiptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property ShareEffectStatus $status
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable|null $lease_expires_at
 * @property CarbonImmutable|null $succeeded_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $cancelled_at
 */
class ShareEffectReceipt extends Model
{
    /** @use HasFactory<ShareEffectReceiptFactory> */
    use HasFactory;

    use HasUuids;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_LEASED = 'leased';

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'effect_key',
        'source_type',
        'source_id',
        'effect_type',
        'status',
        'payload',
        'attempts',
        'lease_token',
        'leased_by',
        'lease_expires_at',
        'succeeded_at',
        'failed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShareEffectStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'lease_expires_at' => 'immutable_datetime',
            'succeeded_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function isTerminal(): bool
    {
        return $this->status instanceof ShareEffectStatus
            && in_array($this->status, [
                ShareEffectStatus::Succeeded,
                ShareEffectStatus::Failed,
                ShareEffectStatus::Cancelled,
            ], true);
    }

    public function isLeaseExpired(): bool
    {
        $expiresAt = $this->lease_expires_at;

        if (! $expiresAt instanceof CarbonInterface) {
            return true;
        }

        return $expiresAt->lte(now());
    }

    protected static function newFactory(): ShareEffectReceiptFactory
    {
        return ShareEffectReceiptFactory::new();
    }
}
