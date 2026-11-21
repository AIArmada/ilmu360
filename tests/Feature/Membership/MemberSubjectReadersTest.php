<?php

declare(strict_types=1);

use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Api\AccountMembershipDirectory;
use App\Features\Membership\Api\MemberSubjectReader;
use App\Features\Membership\Api\MemberSubjectRecord;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Models\User;
use App\Platform\ExtensionRegistry;

it('registers an owner-provided immutable membership reader for each subject type', function (): void {
    $readers = collect(app(ExtensionRegistry::class)->resolve('membership.subject-records', MemberSubjectReader::class));

    expect($readers->map(static fn (MemberSubjectReader $reader): string => $reader->subjectType()->value)->all())
        ->toEqualCanonicalizing(array_map(static fn (MemberSubjectType $type): string => $type->value, MemberSubjectType::cases()));
});

it('projects owner records as immutable member subject values', function (): void {
    $institution = Institution::factory()->create(['name' => 'Masjid Member Summary']);
    $person = Person::factory()->create(['name' => 'Ustaz Member Summary']);
    $event = Event::factory()->create(['title' => 'Majlis Member Summary']);
    $reference = Reference::factory()->create(['title' => 'Kitab Member Summary']);

    $subjects = [
        [MemberSubjectType::Institution, $institution, 'Masjid Member Summary'],
        [MemberSubjectType::Person, $person, 'Ustaz Member Summary'],
        [MemberSubjectType::Event, $event, 'Majlis Member Summary'],
        [MemberSubjectType::Reference, $reference, $reference->displayTitle()],
    ];

    $readers = collect(app(ExtensionRegistry::class)->resolve('membership.subject-records', MemberSubjectReader::class))
        ->keyBy(static fn (MemberSubjectReader $reader): string => $reader->subjectType()->value);

    foreach ($subjects as [$type, $model, $name]) {
        $reader = $readers->get($type->value);
        expect($reader)->toBeInstanceOf(MemberSubjectReader::class);

        $items = $reader->forIds(
            [(string) $model->getKey()],
            $type === MemberSubjectType::Event ? 'created_at' : ($type === MemberSubjectType::Reference ? 'title' : 'name'),
            'asc',
        );

        expect($items)->toHaveCount(1)
            ->and($items[0])->toBeInstanceOf(MemberSubjectRecord::class)
            ->and($items[0]->id)->toBe((string) $model->getKey())
            ->and($items[0]->name)->toBe($name)
            ->and($items[0]->type)->toBe($type)
            ->and($items[0])->not->toBeInstanceOf(Illuminate\Database\Eloquent\Model::class);
    }
});

it('returns no models for account types without memberships', function (): void {
    $user = User::factory()->create();
    $directory = app(AccountMembershipDirectory::class);

    foreach (MemberSubjectType::cases() as $type) {
        expect($directory->subjects((string) $user->getKey(), $type))->toBeEmpty();
    }
});
