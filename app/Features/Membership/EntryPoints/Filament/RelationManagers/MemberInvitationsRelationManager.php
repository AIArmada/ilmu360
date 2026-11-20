<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Filament\RelationManagers;

use App\Platform\Admin\Filament\RelationManagers\MemberInvitationsRelationManager as BaseMemberInvitationsRelationManager;

/**
 * Membership-owned extension point for subject relation managers.
 */
abstract class MemberInvitationsRelationManager extends BaseMemberInvitationsRelationManager {}
