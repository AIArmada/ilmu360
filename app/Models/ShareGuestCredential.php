<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShareGuestCredentialStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Server-issued guest share credential.
 *
 * Only a one-way verifier of the opaque credential is stored. The raw
 * credential is shown once at issuance and can travel over the guest
 * cookie (web) or an explicit header/private POST body (native API). It is
 * never derived from network fingerprints or caller-forged public values.
 *
 * @property string $id
 * @property string $affiliate_id
 * @property string $credential_verifier
 * @property ShareGuestCredentialStatus $status
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ShareGuestCredential extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'affiliate_id',
        'credential_verifier',
        'status',
        'issued_at',
        'expires_at',
        'last_used_at',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'credential_verifier',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShareGuestCredentialStatus::class,
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * Centralized atomic revocation transition. Expiry is a separate
     * deadline check and never mutates lifecycle state.
     */
    public function revoke(): bool
    {
        $revoked = static::query()
            ->whereKey($this->getKey())
            ->where('status', ShareGuestCredentialStatus::Active->value)
            ->update([
                'status' => ShareGuestCredentialStatus::Revoked->value,
                'revoked_at' => now(),
            ]) > 0;

        if ($revoked) {
            $this->refresh();
        }

        return $revoked;
    }
}
