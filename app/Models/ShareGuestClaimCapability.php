<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShareGuestClaimCapabilityStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Server-issued guest claim capability.
 *
 * A single-purpose bearer secret issued once alongside the reusable guest
 * share credential when anonymous sharing begins. Only a one-way verifier
 * is stored. Presentation plus authenticated explicit consent proves guest
 * ownership exactly once: a successful claim consumes the capability, and
 * a lost or expired capability is permanently unverifiable. It never
 * travels in a GET query, a shared URL, or a log.
 *
 * @property string $id
 * @property string $guest_affiliate_id
 * @property string $capability_verifier
 * @property ShareGuestClaimCapabilityStatus $status
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ShareGuestClaimCapability extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'guest_affiliate_id',
        'capability_verifier',
        'status',
        'issued_at',
        'expires_at',
        'consumed_at',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'capability_verifier',
    ];

    protected static function booted(): void
    {
        // Consumed or revoked proof can never be reactivated and issuance
        // fields can never be rewritten through ordinary model mutation.
        // The only allowed transitions are the centralized atomic
        // consume() and revoke(), which run below model events.
        static::updating(static function (): never {
            throw new \LogicException('ShareGuestClaimCapability rows mutate only through consume() and revoke().');
        });

        static::deleting(static function (): never {
            throw new \LogicException('ShareGuestClaimCapability rows are permanent issuance evidence and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShareGuestClaimCapabilityStatus::class,
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === ShareGuestClaimCapabilityStatus::Active
            && $this->revoked_at === null
            && $this->consumed_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public static function verifierFor(string $capability): string
    {
        return hash('sha256', 'dawah-guest-claim-v1|'.$capability);
    }

    /**
     * Centralized atomic single-use transition. Only an active, unconsumed,
     * unrevoked capability flips to consumed; expiry never mutates state.
     */
    public function consume(): bool
    {
        $consumed = static::query()
            ->whereKey($this->getKey())
            ->where('status', ShareGuestClaimCapabilityStatus::Active->value)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update([
                'status' => ShareGuestClaimCapabilityStatus::Consumed->value,
                'consumed_at' => now(),
            ]) > 0;

        if ($consumed) {
            $this->refresh();
        }

        return $consumed;
    }

    /**
     * Centralized atomic revocation transition.
     */
    public function revoke(): bool
    {
        $revoked = static::query()
            ->whereKey($this->getKey())
            ->where('status', ShareGuestClaimCapabilityStatus::Active->value)
            ->update([
                'status' => ShareGuestClaimCapabilityStatus::Revoked->value,
                'revoked_at' => now(),
            ]) > 0;

        if ($revoked) {
            $this->refresh();
        }

        return $revoked;
    }
}
