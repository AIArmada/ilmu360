<?php

declare(strict_types=1);

use App\Features\DonationChannels\Models\DonationChannel;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Models\MemberInvitation;
use App\Features\Persons\Models\Person;
use App\Features\Places\Models\Venue;
use App\Features\References\Models\Reference;
use App\Models\User;
use App\Platform\Language\Models\Language;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Support\MorphAliasModel;

it('resolves every cross-feature relation target through its morph alias', function (): void {
    expect(MorphModelResolver::resolve('event'))->toBe(Event::class)
        ->and(MorphModelResolver::resolve('institution'))->toBe(Institution::class)
        ->and(MorphModelResolver::resolve('person'))->toBe(Person::class)
        ->and(MorphModelResolver::resolve('reference'))->toBe(Reference::class)
        ->and(MorphModelResolver::resolve('venue'))->toBe(Venue::class)
        ->and(MorphModelResolver::resolve('user'))->toBe(User::class)
        ->and(MorphModelResolver::resolve('event_key_person'))->toBe(EventKeyPerson::class)
        ->and(MorphModelResolver::resolve('member_invitation'))->toBe(MemberInvitation::class)
        ->and(MorphModelResolver::resolve('donation_channel'))->toBe(DonationChannel::class)
        ->and(MorphModelResolver::resolve('language'))->toBe(Language::class);
});

it('rejects an unregistered morph alias', function (): void {
    MorphAliasModel::classFor('missing_relation_target');
})->throws(RuntimeException::class, 'The [missing_relation_target] morph alias must resolve to an Eloquent model class.');

it('rejects an alias with no registered morph model', function (): void {
    MorphModelResolver::resolve('missing_relation_target');
})->throws(RuntimeException::class, 'The [missing_relation_target] morph alias must resolve to an Eloquent model.');
