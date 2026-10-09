<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShareGuestClaimStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable guest-to-member claim association.
 *
 * Exactly one row per guest profile (unique guest_affiliate_id), preserved
 * even after the claimant is deleted: a revoked row blocks every future
 * claim for that history, including by the original claimant after a
 * restore. The guest affiliate row itself is never touched (owner stays
 * null, kind stays guest); the future reward recipient is the claimant's
 * distinct member affiliate. No secret is restorable from this row.
 */
class ShareGuestClaim extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'guest_affiliate_id',
        'claimant_user_id',
        'capability_verifier',
        'status',
        'claimed_at',
        'revoked_at',
    ];

    protected static function booted(): void
    {
        // Permanent immutable ownership evidence: ordinary model mutation
        // can never reassign history, rewrite identities or timestamps, or
        // delete the row. The only allowed transition is the centralized
        // atomic revoke(), which runs below model events.
        static::updating(static function (): never {
            throw new \LogicException('ShareGuestClaim rows are immutable. Use revoke() to detach a claim.');
        });

        static::deleting(static function (): never {
            throw new \LogicException('ShareGuestClaim rows are permanent ownership evidence and cannot be deleted.');
        });
    }

    /**
     * @var list<string>
     */
    protected $hidden = [
        'capability_verifier',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShareGuestClaimStatus::class,
            'claimed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === ShareGuestClaimStatus::Active
            && $this->revoked_at === null;
    }

    /**
     * Centralized atomic detach transition for claimant deletion. The row
     * is preserved as the permanent ownership record; nothing is deleted
     * and no secret, award, or notification is replayed.
     */
    public function revoke(): bool
    {
        $revoked = static::query()
            ->whereKey($this->getKey())
            ->where('status', ShareGuestClaimStatus::Active->value)
            ->update([
                'status' => ShareGuestClaimStatus::Revoked->value,
                'revoked_at' => now(),
            ]) > 0;

        if ($revoked) {
            $this->refresh();
        }

        return $revoked;
    }

    /**
     * @return list<string>
     */
    public static function activeGuestAffiliateIdsForClaimant(string $claimantUserId): array
    {
        return static::query()
            ->where('claimant_user_id', $claimantUserId)
            ->where('status', ShareGuestClaimStatus::Active->value)
            ->whereNull('revoked_at')
            ->pluck('guest_affiliate_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }
}
