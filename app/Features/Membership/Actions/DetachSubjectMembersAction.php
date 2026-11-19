<?php

declare(strict_types=1);

namespace App\Features\Membership\Actions;

use App\Features\Membership\Api\MemberSubjectType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Remove an app-owned member pivot without revoking mapped roles.
 *
 * This preserves the existing event-deletion behaviour: pivot rows are removed
 * alongside the subject, while account lifecycle hooks and scoped roles are not
 * replayed for members who were never individually removed.
 * Organization pivots remain owned by the Organizations package.
 */
final readonly class DetachSubjectMembersAction
{
    public function handle(MemberSubjectType $subjectType, string $subjectId): void
    {
        match ($subjectType) {
            MemberSubjectType::Institution => DB::table('institution_members')->where('institution_id', $subjectId)->delete(),
            MemberSubjectType::Person => DB::table('person_members')->where('person_id', $subjectId)->delete(),
            MemberSubjectType::Reference => DB::table('reference_members')->where('reference_id', $subjectId)->delete(),
            MemberSubjectType::Event => DB::table('event_members')->where('event_id', $subjectId)->delete(),
            MemberSubjectType::Organization => throw new InvalidArgumentException(
                'Organization membership is owned by the Organizations package.',
            ),
        };
    }
}
