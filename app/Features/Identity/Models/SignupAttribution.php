<?php

declare(strict_types=1);

namespace App\Features\Identity\Models;

use App\Features\Identity\Api\SignupAttributionSourceType;
use App\Features\Identity\Api\SignupAttributionStatus;
use Database\Factories\SignupAttributionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Immutable acquisition source for an account.
 *
 * The account association stays the conventional `user_id` column. Identity
 * intentionally does not declare User relationships so account facts cross
 * its boundary through AccountDirectory and the Identity API.
 */
class SignupAttribution extends Model
{
    /** @use HasFactory<SignupAttributionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'signup_attributions';

    protected $fillable = [
        'user_id', 'referrer_user_id', 'source_type', 'status',
        'affiliate_id', 'affiliate_link_id', 'affiliate_attribution_id',
        'referral_invite_id', 'invitee_contact_hash', 'signed_up_at', 'confirmed_at',
    ];

    protected $hidden = ['invitee_contact_hash'];

    protected static function newFactory(): SignupAttributionFactory
    {
        return SignupAttributionFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Signup sources are immutable. Use the signup confirmation or guest linking action.');
        });
    }

    protected function casts(): array
    {
        return [
            'source_type' => SignupAttributionSourceType::class,
            'status' => SignupAttributionStatus::class,
            'signed_up_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
