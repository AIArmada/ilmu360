<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\RelationManagers;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\FilamentOrganizations\RelationManagers\AbstractMembersRelationManager;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Api\MembershipCommands;
use App\Features\Membership\Api\MemberSubjectType;
use App\Models\User;
use App\Platform\Admin\AdminResourceLink;
use App\Shared\Submission\PublicSubmissionUiEvents;
use Illuminate\Database\Eloquent\Model;

class MembersRelationManager extends AbstractMembersRelationManager
{
    protected function addMember(Model $owner, Model $member, string $role): void
    {
        /** @var Institution $owner */
        OwnerContext::withOwner($owner, function () use ($owner, $member, $role): void {
            $resolvedRole = MemberRole::tryFrom($role) ?? MemberRole::Owner;

            app(MembershipCommands::class)->addMember(
                MemberSubjectType::Institution,
                (string) $owner->getKey(),
                (string) $member->getKey(),
                $resolvedRole->value,
            );
        });
    }

    protected function changeMemberRole(Model $owner, Model $member, string $role): void
    {
        $resolvedRole = MemberRole::tryFrom($role) ?? MemberRole::Viewer;

        app(MembershipCommands::class)->changeMemberRole(
            MemberSubjectType::Institution,
            (string) $owner->getKey(),
            (string) $member->getKey(),
            $resolvedRole->value,
        );
    }

    protected function removeMember(Model $owner, Model $member): void
    {
        app(MembershipCommands::class)->removeMember(
            MemberSubjectType::Institution,
            (string) $owner->getKey(),
            (string) $member->getKey(),
        );
    }

    protected function defaultAddRole(): string
    {
        return MemberRole::Owner->value;
    }

    protected function memberNameUrl(Model $record): ?string
    {
        if (! $record instanceof User) {
            return null;
        }

        return (auth()->user()?->can('update', $record) ?? false)
            ? AdminResourceLink::toSlug('authz/users', 'edit', (string) $record->getKey())
            : null;
    }

    protected function getMemberRoleValue(Model $member): ?string
    {
        $role = parent::getMemberRoleValue($member);

        if ($role !== null) {
            return $role;
        }

        /** @var Institution $institution */
        $institution = $this->getOwnerRecord();
        $loaded = $institution->members()->whereKey($member->getKey())->first();

        return $loaded instanceof User ? parent::getMemberRoleValue($loaded) : null;
    }

    protected function afterMemberMutated(): void
    {
        $this->dispatch(PublicSubmissionUiEvents::REFRESH_TOGGLE)->to($this->getPageClass());
    }
}
