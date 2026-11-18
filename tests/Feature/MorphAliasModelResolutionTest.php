<?php

declare(strict_types=1);

use App\Features\DonationChannels\Models\DonationChannel;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\Institutions\Models\Institution;
use App\Platform\Language\Models\Language;
use App\Features\Membership\Models\MemberInvitation;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Models\User;
use App\Features\Places\Models\Venue;
use App\Shared\Support\MorphAliasModel;
use App\Platform\Models\Support\RelatedModels;

it('resolves every cross-feature relation target through its morph alias', function (): void {
    expect(RelatedModels::event())->toBe(Event::class)
        ->and(RelatedModels::institution())->toBe(Institution::class)
        ->and(RelatedModels::person())->toBe(Person::class)
        ->and(RelatedModels::reference())->toBe(Reference::class)
        ->and(RelatedModels::venue())->toBe(Venue::class)
        ->and(RelatedModels::user())->toBe(User::class)
        ->and(RelatedModels::eventKeyPerson())->toBe(EventKeyPerson::class)
        ->and(RelatedModels::memberInvitation())->toBe(MemberInvitation::class)
        ->and(RelatedModels::donationChannel())->toBe(DonationChannel::class)
        ->and(RelatedModels::language())->toBe(Language::class);
});

it('rejects an unregistered morph alias', function (): void {
    MorphAliasModel::classFor('missing_relation_target');
})->throws(RuntimeException::class, 'The [missing_relation_target] morph alias must resolve to an Eloquent model class.');

it('rejects a morph alias that resolves to a different model', function (): void {
    RelatedModels::classFor('event', Institution::class);
})->throws(RuntimeException::class, 'The [event] morph alias must resolve to [App\Features\Institutions\Models\Institution].');
