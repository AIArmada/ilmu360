<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Models;

use AIArmada\Addressing\Features\Geography\Models\AddressCountry;
use App\Features\DonationChannels\Api\Enums\DonationBankStatus;
use App\Platform\Auditing\AuditsModelChanges;
use App\Shared\Cache\SelectionCatalog;
use Carbon\CarbonImmutable;
use Database\Factories\DonationBankFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One country-scoped, admin-managed bank catalog entry.
 *
 * The code is the stable identity scoped to the canonical addressing country
 * UUID and the name is the catalog display name derived onto every new
 * donation channel. An entry referenced by a stored donation channel can be
 * renamed or retired, but never re-identified or deleted; retiring it removes
 * it from new selection while stored references keep resolving.
 *
 * @property DonationBankStatus $status
 * @property CarbonImmutable|null $retired_at
 * @property CarbonImmutable|null $reactivated_at
 * @property CarbonImmutable|null $last_state_change_at
 */
class DonationBank extends Model implements AuditableContract
{
    /** @use HasFactory<DonationBankFactory> */
    use AuditsModelChanges, HasFactory, HasUuids;

    #[\Override]
    protected $table = 'donation_banks';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    #[\Override]
    protected $fillable = [
        'country_id',
        'code',
        'name',
        'source_url',
        'status',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => DonationBankStatus::class,
            'retired_at' => 'immutable_datetime',
            'reactivated_at' => 'immutable_datetime',
            'last_state_change_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AddressCountry, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(AddressCountry::class, 'country_id');
    }

    /**
     * Bank-account donation channels that store this catalog code.
     *
     * The relation keys on the stored code alone: donation_channels does not
     * record the entry country and catalog codes are unique per country in
     * practice, so any matching stored code keeps the entry referenced.
     *
     * @return HasMany<DonationChannel, $this>
     */
    public function referencedChannels(): HasMany
    {
        return $this->hasMany(DonationChannel::class, 'bank_code', 'code')
            ->where('method', 'bank_account');
    }

    #[\Override]
    protected static function booted(): void
    {
        parent::booted();

        static::saved(static fn () => app(SelectionCatalog::class)->bustDonationBanks());
        static::deleted(static fn () => app(SelectionCatalog::class)->bustDonationBanks());
    }

    protected static function newFactory(): DonationBankFactory
    {
        return DonationBankFactory::new();
    }
}
