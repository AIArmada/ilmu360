<?php

declare(strict_types=1);

namespace App\Platform\Models\Support;

use App\Shared\Support\MorphAliasModel;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Typed accessors for cross-capability relation targets.
 *
 * Runtime targets resolve through the configured morph alias registry. Callers
 * pass the owning model class they expect so the generic relation type stays
 * precise without this neutral helper importing any feature model.
 */
final class RelatedModels
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return class-string<TModel>
     */
    public static function event(string $expected): string
    {
        return self::classFor('event', $expected);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return class-string<TModel>
     */
    public static function institution(string $expected): string
    {
        return self::classFor('institution', $expected);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return class-string<TModel>
     */
    public static function person(string $expected): string
    {
        return self::classFor('person', $expected);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return class-string<TModel>
     */
    public static function reference(string $expected): string
    {
        return self::classFor('reference', $expected);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return class-string<TModel>
     */
    public static function venue(string $expected): string
    {
        return self::classFor('venue', $expected);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return class-string<TModel>
     */
    public static function user(string $expected): string
    {
        return self::classFor('user', $expected);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return class-string<TModel>
     */
    public static function eventKeyPerson(string $expected): string
    {
        return self::classFor('event_key_person', $expected);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return class-string<TModel>
     */
    public static function memberInvitation(string $expected): string
    {
        return self::classFor('member_invitation', $expected);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return class-string<TModel>
     */
    public static function donationChannel(string $expected): string
    {
        return self::classFor('donation_channel', $expected);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return class-string<TModel>
     */
    public static function language(string $expected): string
    {
        return self::classFor('language', $expected);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return class-string<TModel>
     */
    public static function classFor(string $alias, string $expected): string
    {
        $class = MorphAliasModel::classFor($alias);

        if (! is_a($class, $expected, true)) {
            throw new RuntimeException(sprintf(
                'The [%s] morph alias must resolve to [%s].',
                $alias,
                $expected,
            ));
        }

        /** @var class-string<TModel> $class */
        return $class;
    }
}
