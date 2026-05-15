<?php

namespace App\Models;

use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\DonationChannelFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
        'status',
        'verified_at',
        'verified_by',
        'is_default',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'is_default' => 'boolean',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
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
            ->width(200)
            ->height(200)
            ->format('webp');
    }
}
