<?php

declare(strict_types=1);

namespace App\Platform\Models\Support;

use App\Shared\Support\MorphAliasModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Typed accessors for cross-capability relation targets.
 *
 * Runtime targets resolve through the configured morph alias registry, so this
 * neutral helper never imports feature-owned model classes. Relations using
 * these accessors keep working when an owner moves a model class.
 */
final class RelatedModels
{
    /**
     * @return class-string<Model>
     */
    public static function event(): string
    {
        return self::classFor('event');
    }

    /**
     * @return class-string<Model>
     */
    public static function institution(): string
    {
        return self::classFor('institution');
    }

    /**
     * @return class-string<Model>
     */
    public static function person(): string
    {
        return self::classFor('person');
    }

    /**
     * @return class-string<Model>
     */
    public static function reference(): string
    {
        return self::classFor('reference');
    }

    /**
     * @return class-string<Model>
     */
    public static function venue(): string
    {
        return self::classFor('venue');
    }

    /**
     * @return class-string<Model>
     */
    public static function user(): string
    {
        return self::classFor('user');
    }

    /**
     * @return class-string<Model>
     */
    public static function eventKeyPerson(): string
    {
        return self::classFor('event_key_person');
    }

    /**
     * @return class-string<Model>
     */
    public static function memberInvitation(): string
    {
        return self::classFor('member_invitation');
    }

    /**
     * @return class-string<Model>
     */
    public static function donationChannel(): string
    {
        return self::classFor('donation_channel');
    }

    /**
     * @return class-string<Model>
     */
    public static function language(): string
    {
        return self::classFor('language');
    }

    /**
     * @return class-string<Model>
     */
    public static function classFor(string $alias): string
    {
        return MorphAliasModel::classFor($alias);
    }
}
