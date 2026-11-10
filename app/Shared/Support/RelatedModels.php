<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Language;
use App\Models\MemberInvitation;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Typed accessors for cross-capability relation targets.
 *
 * The runtime class always comes from the morph alias registry, while the
 * declared return types keep relation generics precise for callers and static
 * analysis. This temporary adapter disappears as the owning features expose
 * their public APIs.
 */
final class RelatedModels
{
    /**
     * @return class-string<Event>
     */
    public static function event(): string
    {
        return self::classFor('event', Event::class);
    }

    /**
     * @return class-string<Institution>
     */
    public static function institution(): string
    {
        return self::classFor('institution', Institution::class);
    }

    /**
     * @return class-string<Person>
     */
    public static function person(): string
    {
        return self::classFor('person', Person::class);
    }

    /**
     * @return class-string<Reference>
     */
    public static function reference(): string
    {
        return self::classFor('reference', Reference::class);
    }

    /**
     * @return class-string<Venue>
     */
    public static function venue(): string
    {
        return self::classFor('venue', Venue::class);
    }

    /**
     * @return class-string<User>
     */
    public static function user(): string
    {
        return self::classFor('user', User::class);
    }

    /**
     * @return class-string<EventKeyPerson>
     */
    public static function eventKeyPerson(): string
    {
        return self::classFor('event_key_person', EventKeyPerson::class);
    }

    /**
     * @return class-string<MemberInvitation>
     */
    public static function memberInvitation(): string
    {
        return self::classFor('member_invitation', MemberInvitation::class);
    }

    /**
     * @return class-string<DonationChannel>
     */
    public static function donationChannel(): string
    {
        return self::classFor('donation_channel', DonationChannel::class);
    }

    /**
     * @return class-string<Language>
     */
    public static function language(): string
    {
        return self::classFor('language', Language::class);
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

        return $class;
    }
}
