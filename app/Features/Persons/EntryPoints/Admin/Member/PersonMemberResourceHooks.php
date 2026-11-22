<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Admin\Member;

use AIArmada\Addressing\Support\AddressAssignments;
use App\Features\Membership\Api\MembershipDirectory;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Persons\Models\Person;
use App\Platform\Api\Member\Contracts\MemberResourceHooks;
use App\Shared\Auth\AccountActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

final readonly class PersonMemberResourceHooks implements MemberResourceHooks
{
    public function resources(): array
    {
        return [\App\Features\Persons\EntryPoints\Filament\Ahli\Resources\Persons\PersonResource::class];
    }

    public function supports(string $resourceClass): bool
    {
        return in_array($resourceClass, $this->resources(), true);
    }

    public function canAccess(string $resourceClass, AccountActor $user, MembershipDirectory $memberships): ?bool
    {
        if (! in_array($resourceClass, $this->resources(), true)) {
            return null;
        }

        return $memberships->subjectIdsFor($user->actorId(), MemberSubjectType::Person) !== [];
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

    /**
     * @param  class-string  $resourceClass
     * @return list<array{key: string, label: string, type: string, options?: array<string, string>}>
     */
    public function filters(string $resourceClass): array
    {
        return [];
    }

    /**
     * @param  Builder<Model>  $query
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, string $resourceClass, array $filters): bool
    {
        return false;
    }

    /**
     * @param  class-string  $resourceClass
     * @return array<string, array<string, mixed>>
     */
    public function imageUploadMcpTools(string $resourceClass, string $key): array
    {
        return [];
    }

    /** @return list<string> */
    public function imageUploadMcpToolKeys(): array
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
