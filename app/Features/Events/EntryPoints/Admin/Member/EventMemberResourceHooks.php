<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Admin\Member;

use AIArmada\Organizations\Models\Organization;
use App\Features\Events\EntryPoints\Http\Data\EventPayloadData;
use App\Features\Events\Models\Event;
use App\Platform\Api\Member\Contracts\MemberResourceHooks;
use App\Shared\Auth\AccountActor;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
use App\Shared\Support\MorphAliasModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class EventMemberResourceHooks implements MemberResourceHooks
{
    public function resources(): array
    {
        return [\App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource::class];
    }

    public function canAccess(string $resourceClass, AccountActor $user, MembershipDirectory $memberships): ?bool
    {
        if (! in_array($resourceClass, $this->resources(), true)) {
            return null;
        }

        $accountId = $user->actorId();

        return $memberships->subjectIdsFor($accountId, MembershipSubjectType::Institution) !== []
            || $memberships->subjectIdsFor($accountId, MembershipSubjectType::Person) !== []
            || $memberships->subjectIdsFor($accountId, MembershipSubjectType::Event) !== [];
    }

    public function adminResourceClass(string $resourceClass): ?string
    {
        return in_array($resourceClass, $this->resources(), true) ? \App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource::class : null;
    }

    public function canWriteResource(string $resourceClass, AccountActor $user, \App\Features\Membership\Api\MemberPermissions $permissions): ?bool
    {
        if (! in_array($resourceClass, $this->resources(), true)) {
            return null;
        }

        return $permissions->hasAnyEventPermission($user, 'event.update')
            || $permissions->hasAnyInstitutionPermission($user, 'event.update')
            || $permissions->hasAnyPersonPermission($user, 'event.update')
            || $permissions->hasAnyOrganizationPermission($user, 'event.update');
    }

    public function canWriteRecord(string $resourceClass, AccountActor $user, Model $record): ?bool
    {
        if (! in_array($resourceClass, $this->resources(), true) || $record->getMorphClass() !== 'event' || ! method_exists($record, 'userHasScopedEventPermission')) {
            return null;
        }

        return $record->userHasScopedEventPermission($user, 'event.update');
    }

    /** @return list<string> */
    public function extraRelations(string $resourceClass): array
    {
        return in_array($resourceClass, $this->resources(), true) ? ['child_events'] : [];
    }

    /** @return array<string, mixed>|null */
    public function dateSemantics(string $resourceClass): ?array
    {
        if (! in_array($resourceClass, $this->resources(), true)) {
            return null;
        }

        return [
            'storage_timezone' => 'UTC',
            'viewer_timezone' => 'resolved at request time',
            'local_fields' => ['starts_at_local', 'starts_on_local_date', 'ends_at_local'],
            'local_date_filter' => 'starts_on_local_date',
        ];
    }

    /** @return Builder<Model>|null */
    public function queryFor(string $resourceClass): ?Builder
    {
        if (! in_array($resourceClass, $this->resources(), true)) {
            return null;
        }

        /** @var Builder<Model> $query */
        $query = Event::query()->withCount('occurrences');

        $this->scopeToCurrentMember($query);

        return $query;
    }

    public function recordAttributes(Model $record): ?array
    {
        return $record instanceof Event ? EventPayloadData::fromModel($record)->toArray() : null;
    }

    public function recordTitle(Model $record): ?string
    {
        return $record instanceof Event ? (string) ($record->title ?? '') : null;
    }

    public function augmentAttributes(Model $record, array $attributes): array
    {
        return $attributes;
    }

    /** @param Builder<Model> $query */
    private function scopeToCurrentMember(Builder $query): void
    {
        $user = auth()->user();

        if (! $user instanceof AccountActor) {
            $query->whereRaw('1 = 0');

            return;
        }

        $memberships = app(MembershipDirectory::class);
        $accountId = $user->actorId();
        $institutionIds = $memberships->subjectIdsFor($accountId, MembershipSubjectType::Institution);
        $personIds = $memberships->subjectIdsFor($accountId, MembershipSubjectType::Person);
        $eventIds = $memberships->subjectIdsFor($accountId, MembershipSubjectType::Event);
        $organizationIds = $memberships->subjectIdsFor($accountId, MembershipSubjectType::Organization);
        $institutionMorph = $this->morphType('institution');
        $personMorph = $this->morphType('person');

        $query->where(function (Builder $eventQuery) use ($institutionIds, $personIds, $eventIds, $organizationIds, $institutionMorph, $personMorph): void {
            $eventQuery
                ->whereIn('institution_id', $institutionIds)
                ->orWhereIn('events.id', $eventIds)
                ->orWhere(function (Builder $organizationEventQuery) use ($organizationIds): void {
                    $organizationEventQuery
                        ->where('events.owner_type', (new Organization)->getMorphClass())
                        ->whereIn('events.owner_id', $organizationIds);
                })
                ->orWhereHas('persons', function (Builder $personQuery) use ($personIds): void {
                    $personQuery->whereIn('persons.id', $personIds);
                })
                ->orWhereHas('involvements', function (Builder $involvementQuery) use ($institutionIds, $personIds, $institutionMorph, $personMorph): void {
                    $involvementQuery
                        ->where('role_code', 'organizer')
                        ->where('is_primary', true)
                        ->where(function (Builder $organizerQuery) use ($institutionIds, $personIds, $institutionMorph, $personMorph): void {
                            $organizerQuery
                                ->where(function (Builder $institutionQuery) use ($institutionIds, $institutionMorph): void {
                                    $institutionQuery
                                        ->where('involveable_type', $institutionMorph)
                                        ->whereIn('involveable_id', $institutionIds);
                                })
                                ->orWhere(function (Builder $personQuery) use ($personIds, $personMorph): void {
                                    $personQuery
                                        ->where('involveable_type', $personMorph)
                                        ->whereIn('involveable_id', $personIds);
                                });
                        });
                });
        });
    }

    private function morphType(string $alias): string
    {
        $class = MorphAliasModel::classFor($alias);

        return (string) (new $class)->getMorphClass();
    }
}
