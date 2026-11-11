<?php

declare(strict_types=1);

namespace App\Support\Authz;

use App\Shared\Auth\ScopedMembershipRoleStore;
use App\Shared\Auth\UserMorphType;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Authz adapter for the account lifecycle contributor.
 *
 * Only team-scoped rows are handled here. Global (team-null) role and
 * permission rows remain in the conventional restoration path in
 * HasUserRestoration. Authz tables are never claimed by a feature.
 */
final readonly class EloquentScopedMembershipRoleStore implements ScopedMembershipRoleStore
{
    public function __construct(
        private UserMorphType $userMorphType,
    ) {}

    public function snapshot(string $accountId): array
    {
        return [
            'scoped_roles' => $this->scopedPermissionRows('model_has_roles', $accountId),
            'scoped_permissions' => $this->scopedPermissionRows('model_has_permissions', $accountId),
        ];
    }

    public function detach(string $accountId): void
    {
        $this->deleteScopedPermissionRows('model_has_roles', $accountId);
        $this->deleteScopedPermissionRows('model_has_permissions', $accountId);
    }

    public function restore(array $snapshot): void
    {
        $this->restoreScopedPermissionRows('model_has_roles', $snapshot['scoped_roles'] ?? []);
        $this->restoreScopedPermissionRows('model_has_permissions', $snapshot['scoped_permissions'] ?? []);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

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
}
