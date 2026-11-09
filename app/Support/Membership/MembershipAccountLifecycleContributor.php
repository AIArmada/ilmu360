<?php

declare(strict_types=1);

namespace App\Support\Membership;

use App\Models\MembershipApplication;
use App\Shared\Auth\AccountLifecycleContributor;
use App\Shared\Auth\UserMorphType;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Preserves membership state across account deletion and restoration.
 *
 * This contributor owns the institution/person/reference/event member pivots,
 * the membership-application applicant/reviewer attribution, and the
 * team-scoped authorization rows that mirror a membership role. Global
 * (team-null) role and permission rows remain in the conventional Authz
 * restoration path in HasUserRestoration.
 *
 * The applicant restoration key mismatch (`membership_claim_ids` captured but
 * `membership_application_ids` read) is intentionally preserved here because
 * fixing it is scheduled for I4 with its own regression test. Only reviewer
 * attribution is restored.
 *
 * Member pivot snapshots keep their existing column set (subject id, user id
 * and timestamps; no role column), so the pre-existing restoration shape and
 * default-role behaviour are unchanged.
 */
final readonly class MembershipAccountLifecycleContributor implements AccountLifecycleContributor
{
    /**
     * @var array<string, string>
     */
    private const array MEMBER_TABLES = [
        'institution_members' => 'institution_id',
        'person_members' => 'person_id',
        'reference_members' => 'reference_id',
        'event_members' => 'event_id',
    ];

    public function __construct(
        private UserMorphType $userMorphType,
    ) {}

    public function key(): string
    {
        return 'membership';
    }

    public function priority(): int
    {
        return 90;
    }

    public function snapshot(string $accountId): array
    {
        $snapshot = [];

        foreach (self::MEMBER_TABLES as $table => $subjectKey) {
            $snapshot[$table] = DB::table($table)
                ->where('user_id', $accountId)
                ->get([$subjectKey, 'user_id', 'joined_at', 'created_at', 'updated_at'])
                ->map(static fn (object $row): array => (array) $row)
                ->values()
                ->all();
        }

        $snapshot['membership_claim_ids'] = MembershipApplication::query()
            ->where('applicant_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
        $snapshot['membership_application_reviewer_ids'] = MembershipApplication::query()
            ->where('reviewer_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
        $snapshot['scoped_roles'] = $this->scopedPermissionRows('model_has_roles', $accountId);
        $snapshot['scoped_permissions'] = $this->scopedPermissionRows('model_has_permissions', $accountId);

        return $snapshot;
    }

    public function detach(string $accountId): void
    {
        foreach (array_keys(self::MEMBER_TABLES) as $table) {
            DB::table($table)->where('user_id', $accountId)->delete();
        }

        MembershipApplication::query()
            ->where('applicant_id', $accountId)
            ->update(['applicant_id' => null]);
        MembershipApplication::query()
            ->where('reviewer_id', $accountId)
            ->update(['reviewer_id' => null]);

        $this->deleteScopedPermissionRows('model_has_roles', $accountId);
        $this->deleteScopedPermissionRows('model_has_permissions', $accountId);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        foreach (array_keys(self::MEMBER_TABLES) as $table) {
            $rows = $this->rows($snapshot[$table] ?? []);

            if ($rows !== []) {
                DB::table($table)->insertOrIgnore($rows);
            }
        }

        // Applicant ids remain unrestored until the I4 key-mismatch fix.
        $reviewerIds = $this->stringIds($snapshot['membership_application_reviewer_ids'] ?? []);

        if ($reviewerIds !== []) {
            MembershipApplication::query()
                ->whereKey($reviewerIds)
                ->whereNull('reviewer_id')
                ->update(['reviewer_id' => $accountId]);
        }

        $this->restoreScopedPermissionRows('model_has_roles', $snapshot['scoped_roles'] ?? []);
        $this->restoreScopedPermissionRows('model_has_permissions', $snapshot['scoped_permissions'] ?? []);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function afterRestore(string $accountId): void {}

    /**
     * @return list<array<string, mixed>>
     */
    private function scopedPermissionRows(string $tableKey, string $accountId): array
    {
        return DB::table($this->permissionTable($tableKey))
            ->where($this->permissionModelKeyName(), $accountId)
            ->where('model_type', $this->userMorphType->morphClass())
            ->whereNotNull($this->permissionTeamKey())
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->values()
            ->all();
    }

    private function deleteScopedPermissionRows(string $tableKey, string $accountId): void
    {
        DB::table($this->permissionTable($tableKey))
            ->where($this->permissionModelKeyName(), $accountId)
            ->where('model_type', $this->userMorphType->morphClass())
            ->whereNotNull($this->permissionTeamKey())
            ->delete();
    }

    private function restoreScopedPermissionRows(string $tableKey, mixed $rows): void
    {
        $records = $this->rows($rows);

        if ($records !== []) {
            DB::table($this->permissionTable($tableKey))->insertOrIgnore($records);
        }
    }

    private function permissionTable(string $tableKey): string
    {
        $table = config("permission.table_names.{$tableKey}");

        return is_string($table) && $table !== '' ? $table : $tableKey;
    }

    private function permissionModelKeyName(): string
    {
        $key = config('permission.column_names.model_morph_key');

        return is_string($key) && $key !== '' ? $key : 'model_id';
    }

    private function permissionTeamKey(): string
    {
        $key = config('permission.column_names.team_foreign_key');

        return is_string($key) && $key !== '' ? $key : 'team_id';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function stringIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(static fn (mixed $id): bool => is_string($id) || is_int($id))
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
