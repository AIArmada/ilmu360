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
use App\Platform\Models\Support\RelatedModels;
use App\Shared\Support\MorphAliasModel;

it('resolves every cross-feature relation target through its morph alias', function (): void {
    expect(RelatedModels::event(Event::class))->toBe(Event::class)
        ->and(RelatedModels::institution(Institution::class))->toBe(Institution::class)
        ->and(RelatedModels::person(Person::class))->toBe(Person::class)
        ->and(RelatedModels::reference(Reference::class))->toBe(Reference::class)
        ->and(RelatedModels::venue(Venue::class))->toBe(Venue::class)
        ->and(RelatedModels::user(User::class))->toBe(User::class)
        ->and(RelatedModels::eventKeyPerson(EventKeyPerson::class))->toBe(EventKeyPerson::class)
        ->and(RelatedModels::memberInvitation(MemberInvitation::class))->toBe(MemberInvitation::class)
        ->and(RelatedModels::donationChannel(DonationChannel::class))->toBe(DonationChannel::class)
        ->and(RelatedModels::language(Language::class))->toBe(Language::class);
});

it('rejects an unregistered morph alias', function (): void {
    MorphAliasModel::classFor('missing_relation_target');
})->throws(RuntimeException::class, 'The [missing_relation_target] morph alias must resolve to an Eloquent model class.');

it('rejects a morph alias that resolves to a different model', function (): void {
    RelatedModels::classFor('event', Institution::class);
})->throws(RuntimeException::class, 'The [event] morph alias must resolve to [App\Features\Institutions\Models\Institution].');
