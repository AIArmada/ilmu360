<?php

namespace App\Features\Identity\Models;

use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Social login link for an application account.
 *
 * The account association stays the conventional `user_id` column. Identity
 * intentionally does not declare a User relationship: conventional account
 * facts cross this boundary through AccountDirectory and the Identity API.
 */
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'socialite';

    protected static function newFactory(): SocialAccountFactory
    {
        return SocialAccountFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'avatar_url',
    ];
}
