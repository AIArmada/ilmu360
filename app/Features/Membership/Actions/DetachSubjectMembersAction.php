<?php

declare(strict_types=1);

namespace App\Features\Membership\Actions;

use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Support\Facades\DB;

/**
 * Remove every member pivot for one subject without revoking mapped roles.
 *
 * This preserves the existing event-deletion behaviour: pivot rows are removed
 * alongside the subject, while account lifecycle hooks and scoped roles are not
 * replayed for members who were never individually removed.
 */
final readonly class DetachSubjectMembersAction
{
    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private const array MEMBER_TABLES = [
        'institution' => ['institution_members', 'institution_id'],
        'person' => ['person_members', 'person_id'],
        'reference' => ['reference_members', 'reference_id'],
        'event' => ['event_members', 'event_id'],
    ];

    public function handle(MembershipSubjectType $subjectType, string $subjectId): void
    {
        [$table, $subjectKey] = $this->subjectTable($subjectType);

        DB::table($table)->where($subjectKey, $subjectId)->delete();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function subjectTable(MembershipSubjectType $subjectType): array
    {
        if ($subjectType === MembershipSubjectType::Organization) {
            return [
                (string) config('organizations.database.tables.members', 'organization_members'),
                'organization_id',
            ];
        }

        return self::MEMBER_TABLES[$subjectType->value];
    }
}
