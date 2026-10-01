<?php

namespace App\Models;

use App\Enums\DonationChannelStatus;
use App\Models\Concerns\AuditsModelChanges;
use Carbon\CarbonImmutable;
use Database\Factories\DonationChannelFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;
use LogicException;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property DonationChannelStatus $status
 * @property CarbonImmutable|null $inactive_at
 */
class DonationChannel extends Model implements AuditableContract, HasMedia
{
    /** @use HasFactory<DonationChannelFactory> */
    use AuditsModelChanges, HasFactory, HasUuids, InteractsWithMedia;

    protected $table = 'donation_channels';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $hidden = [
        'account_number',
        'duitnow_value',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'donatable_type',
        'donatable_id',
        'label',
        'recipient',
        'method',
        'bank_code',
        'bank_name',
        'account_number',
        'duitnow_type',
        'duitnow_value',
        'ewallet_provider',
        'ewallet_handle',
        'ewallet_qr_payload',
        'reference_note',
        'verified_by',
        'is_default',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'status' => DonationChannelStatus::class,
            'verified_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'inactive_at' => 'immutable_datetime',
            'last_state_change_at' => 'immutable_datetime',
            'duitnow_value' => 'encrypted',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Transition the donation channel and record the matching lifecycle timestamp.
     */
    public function transitionStatus(DonationChannelStatus $status): static
    {
        if (! $this->exists) {
            throw new LogicException('A new donation channel must be saved before it can transition.');
        }

        $currentStatus = $this->status;

        if (! $currentStatus instanceof DonationChannelStatus) {
            throw new LogicException('A persisted donation channel must have a valid status before it can transition.');
        }

        if ($status === DonationChannelStatus::Verified) {
            $this->verified_by ??= auth()->id();
        }

        if ($currentStatus === $status) {
            $now = CarbonImmutable::now();
            $this->applyStatusTimestamp($status, $now);
            $this->last_state_change_at ??= $now;

            if ($this->isDirty()) {
                $this->save();
            }

            return $this;
        }

        $now = CarbonImmutable::now();
        $this->setAttribute('status', $status);
        $this->applyStatusTimestamp($status, $now, true);
        $this->last_state_change_at = $now;
        $this->save();

        return $this;
    }

    /**
     * Verify the donation channel and record the verifying actor when available.
     */
    public function verify(?User $verifier = null): static
    {
        $this->verified_by ??= $verifier?->getKey() ?? auth()->id();

        return $this->transitionStatus(DonationChannelStatus::Verified);
    }

    /**
     * Reject the donation channel.
     */
    public function reject(): static
    {
        return $this->transitionStatus(DonationChannelStatus::Rejected);
    }

    /**
     * Deactivate the donation channel and record the deactivation timestamp.
     */
    public function deactivate(): static
    {
        return $this->transitionStatus(DonationChannelStatus::Inactive);
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $channel): void {
            if (($channel->getAttributes()['status'] ?? null) === null) {
                $channel->setAttribute('status', DonationChannelStatus::Pending);
            }

            $status = $channel->resolveStatus($channel->getAttributes()['status'] ?? null);

            $now = CarbonImmutable::now();
            $channel->applyStatusTimestamp($status, $now);
            $channel->last_state_change_at ??= $now;

            if ($status === DonationChannelStatus::Verified) {
                $channel->verified_by ??= auth()->id();
            }
        });

        static::saved(function (self $channel): void {
            if (! $channel->is_default) {
                return;
            }

            if (blank($channel->donatable_type) || blank($channel->donatable_id)) {
                return;
            }

            self::query()
                ->where('donatable_type', $channel->donatable_type)
                ->where('donatable_id', $channel->donatable_id)
                ->whereKeyNot($channel->getKey())
                ->where('is_default', true)
                ->update([
                    'is_default' => false,
                    'updated_at' => now(),
                ]);
        });
    }

    private function resolveStatus(mixed $status): DonationChannelStatus
    {
        if ($status instanceof DonationChannelStatus) {
            return $status;
        }

        $resolved = is_string($status) ? DonationChannelStatus::tryFrom($status) : null;

        if (! $resolved instanceof DonationChannelStatus) {
            throw new InvalidArgumentException(sprintf('The donation channel status [%s] is invalid.', is_scalar($status) ? (string) $status : gettype($status)));
        }

        return $resolved;
    }

    private function applyStatusTimestamp(DonationChannelStatus $status, CarbonImmutable $at, bool $overwrite = false): void
    {
        $attribute = match ($status) {
            DonationChannelStatus::Verified => 'verified_at',
            DonationChannelStatus::Rejected => 'rejected_at',
            DonationChannelStatus::Inactive => 'inactive_at',
            default => null,
        };

        if ($attribute === null) {
            return;
        }

        if ($overwrite || $this->getAttribute($attribute) === null) {
            $this->setAttribute($attribute, $at);
        }
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function donatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return MorphMany<Report, $this>
     */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'entity');
    }

    /**
     * Get the account name (alias for recipient).
     */
    public function getAccountNameAttribute(): string
    {
        return $this->recipient;
    }

    /**
     * Get display name for the payment method.
     */
    public function getMethodDisplayAttribute(): string
    {
        return match ($this->method) {
            'bank_account' => 'Bank Account',
            'duitnow' => 'DuitNow',
            'ewallet' => 'E-Wallet',
            default => $this->method,
        };
    }

    /**
     * Get the payment details based on method.
     */
    public function getPaymentDetailsAttribute(): string
    {
        return match ($this->method) {
            'bank_account' => "{$this->bank_name} - {$this->account_number}",
            'duitnow' => "{$this->duitnow_type}: {$this->duitnow_value}",
            'ewallet' => "{$this->ewallet_provider}: {$this->ewallet_handle}",
            default => '',
        };
    }

    /**
     * Register media collections for Spatie Media Library.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('qr')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->singleFile();
    }

    /**
     * Register media conversions for optimized image delivery.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('qr')
            ->fit(Fit::Max, 1080, 1080)
            ->format('webp');
    }
}
