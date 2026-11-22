<?php

declare(strict_types=1);

namespace App\Platform\Admin\Filament\RelationManagers;

use AIArmada\FilamentOrganizations\RelationManagers\AbstractMemberInvitationsRelationManager;
use AIArmada\Membership\Actions\InviteMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Membership\Models\MembershipInvitation;
use App\Features\Membership\Api\MemberInvitationAccess;
use App\Features\Membership\Api\MembershipInvitations;
use App\Features\Membership\Api\MemberSubjectType;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared member-invitations relation manager for membership subjects.
 *
 * Subject features extend this class with their subject type and owner; the
 * invitation gate crosses the Membership API boundary.
 */
abstract class MemberInvitationsRelationManager extends AbstractMemberInvitationsRelationManager
{
    #[\Override]
    protected static string $relationship = 'memberInvitations';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $type = MemberSubjectType::tryFrom($ownerRecord->getMorphClass());

        return $type instanceof MemberSubjectType
            && parent::canViewForRecord($ownerRecord, $pageClass)
            && app(MemberInvitationAccess::class)->canInvite($user, $type, (string) $ownerRecord->getKey());
    }

    abstract protected function getSubjectType(): MemberSubjectType;

    abstract protected function getSubjectOwner(): Model;

    #[\Override]
    protected function getInvitationColumns(): array
    {
        return [
            ...parent::getInvitationColumns(),
            TextColumn::make('delivery')
                ->label('Delivery')
                ->state('Email only'),
        ];
    }

    protected function createInvitation(
        Model $owner,
        string $email,
        string $role,
        Model $actor,
        ?CarbonInterface $expiresAt,
    ): void {
        app(InviteMemberAction::class)->handle(
            $this->getSubjectOwner(),
            $email,
            MemberRole::tryFrom($role) ?? MemberRole::Viewer,
            $actor,
            $expiresAt,
        );
    }

    protected function revokeInvitation(
        Model $owner,
        MembershipInvitation $invitation,
        Model $actor,
    ): void {
        app(MembershipInvitations::class)->revoke(
            (string) $invitation->getKey(),
            (string) $actor->getKey(),
        );
    }
}
