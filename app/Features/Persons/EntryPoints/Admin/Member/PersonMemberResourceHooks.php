<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Admin\Member;

use App\Features\Persons\Models\Person;
use App\Platform\Api\Member\Contracts\MemberResourceHooks;
use App\Platform\Location\AddressAssignments;
use App\Shared\Auth\AccountActor;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

final readonly class PersonMemberResourceHooks implements MemberResourceHooks
{
    public function resources(): array
    {
        return [\App\Features\Persons\EntryPoints\Filament\Ahli\Resources\Persons\PersonResource::class];
    }

    public function canAccess(string $resourceClass, AccountActor $user, MembershipDirectory $memberships): ?bool
    {
        if (! in_array($resourceClass, $this->resources(), true)) {
            return null;
        }

        return $memberships->subjectIdsFor($user->actorId(), MembershipSubjectType::Person) !== [];
    }

    public function adminResourceClass(string $resourceClass): ?string
    {
        return in_array($resourceClass, $this->resources(), true) ? \App\Features\Persons\EntryPoints\Filament\Resources\Persons\PersonResource::class : null;
    }

    public function canWriteResource(string $resourceClass, AccountActor $user, \App\Features\Membership\Api\MemberPermissions $permissions): ?bool
    {
        if (! in_array($resourceClass, $this->resources(), true)) {
            return null;
        }

        return $permissions->hasAnyPersonPermission($user, 'person.update');
    }

    public function canWriteRecord(string $resourceClass, AccountActor $user, Model $record): ?bool
    {
        return null;
    }

    /** @return list<string> */
    public function extraRelations(string $resourceClass): array
    {
        return [];
    }

    /** @return array<string, mixed>|null */
    public function dateSemantics(string $resourceClass): ?array
    {
        return null;
    }

    /** @return Builder<Model>|null */
    public function queryFor(string $resourceClass): ?Builder
    {
        return null;
    }

    public function recordAttributes(Model $record): ?array
    {
        return null;
    }

    public function recordTitle(Model $record): ?string
    {
        return null;
    }

    public function augmentAttributes(Model $record, array $attributes): array
    {
        if (! $record instanceof Person || ! is_array($attributes['address'] ?? null)) {
            return $attributes;
        }

        $address = $record->primaryAddress();
        $attributes['address'] = Arr::only($attributes['address'], ['country_id', 'state_id', 'city_id']);
        $attributes['address']['area_assignments'] = AddressAssignments::forAddress($address);

        return $attributes;
    }
}
