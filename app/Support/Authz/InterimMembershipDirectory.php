<?php

declare(strict_types=1);

namespace App\Support\Authz;

use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipRecord;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Support\Facades\DB;

/**
 * Interim membership read adapter over the current member pivots.
 *
 * The Membership capability will own these pivots once extracted. Until then
 * this adapter is the shared read surface for membership state; it must not
 * become a second write path.
 */
final readonly class InterimMembershipDirectory implements MembershipDirectory
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

    public function subjectIdsFor(
        string $accountId,
        MembershipSubjectType $subjectType,
        ?string $role = null,
    ): array {
        [$table, $subjectKey] = $this->subjectTable($subjectType);
        $query = DB::table($table)->where('user_id', $accountId);

        if (is_string($role) && $role !== '') {
            $query->where('role', $role);
        }

        return $query
            ->pluck($subjectKey)
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function membershipsFor(
        string $accountId,
        ?MembershipSubjectType $subjectType = null,
    ): array {
        $subjectTypes = $subjectType instanceof MembershipSubjectType
            ? [$subjectType]
            : MembershipSubjectType::cases();

        $records = [];

        foreach ($subjectTypes as $type) {
            [$table, $subjectKey] = $this->subjectTable($type);

            foreach (DB::table($table)->where('user_id', $accountId)->get([$subjectKey, 'role']) as $row) {
                $role = $this->stringValue($row->role ?? null);

                if ($role === null) {
                    continue;
                }

                $records[] = new MembershipRecord(
                    subjectType: $type,
                    subjectId: (string) ($row->{$subjectKey} ?? ''),
                    accountId: $accountId,
                    role: $role,
                );
            }
        }

        return $records;
    }

    public function members(MembershipSubjectType $subjectType, string $subjectId): array
    {
        [$table, $subjectKey] = $this->subjectTable($subjectType);

        $records = [];

        foreach (DB::table($table)->where($subjectKey, $subjectId)->get(['user_id', 'role']) as $row) {
            $role = $this->stringValue($row->role ?? null);

            if ($role === null) {
                continue;
            }

            $records[] = new MembershipRecord(
                subjectType: $subjectType,
                subjectId: $subjectId,
                accountId: (string) ($row->user_id ?? ''),
                role: $role,
            );
        }

        return $records;
    }

    public function hasRole(
        string $accountId,
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $role,
    ): bool {
        return $this->hasAnyRole($accountId, $subjectType, $subjectId, [$role]);
    }

    public function hasAnyRole(
        string $accountId,
        MembershipSubjectType $subjectType,
        string $subjectId,
        array $roles,
    ): bool {
        [$table, $subjectKey] = $this->subjectTable($subjectType);
        $roles = $this->normalizeRoles($roles);

        if ($roles === []) {
            return false;
        }

        return DB::table($table)
            ->where('user_id', $accountId)
            ->where($subjectKey, $subjectId)
            ->whereIn('role', $roles)
            ->exists();
    }

    public function hasAnyMembershipRole(
        string $accountId,
        MembershipSubjectType $subjectType,
        array $roles,
    ): bool {
        [$table] = $this->subjectTable($subjectType);
        $roles = $this->normalizeRoles($roles);

        if ($roles === []) {
            return false;
        }

        return DB::table($table)
            ->where('user_id', $accountId)
            ->whereIn('role', $roles)
            ->exists();
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

    /**
     * @param  array<int, mixed>  $roles
     * @return list<string>
     */
    private function normalizeRoles(array $roles): array
    {
        return array_values(array_filter(
            $roles,
            static fn (mixed $role): bool => is_string($role) && $role !== '',
        ));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
