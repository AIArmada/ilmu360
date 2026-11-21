<?php

namespace App\Features\Identity\Persistence;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Identity\Api\AccountLifecycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Spatie\DeletedModels\Models\DeletedModel;
use Spatie\Permission\PermissionRegistrar;

trait HasUserRestoration
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $deletedRelationsSnapshot = [];

    public static function bootHasUserRestoration(): void
    {
        static::deleting(function (User $user): void {
            OwnerContext::withOwner(null, function () use ($user): void {
                if ($user->deletedRelationsSnapshot === []) {
                    $user->captureDeletedRelationsSnapshot();
                }

                // Contributors are ordered by priority. They cover social
                // accounts (300), reports (200), saved searches (100),
                // membership pivots and scoped roles (90), Directory verifier
                // links (80), event ownership/submission/attendance links (70),
                // contribution attribution (60), moderation attribution (50),
                // engagement (40), AI usage (30), and communications (20).
                // Authentication and global authorization remain in Identity.
                // Feature-specific cleanup is delegated to lifecycle contributors.
                app(AccountLifecycle::class)->detach((string) $user->getKey());
                $user->deleteAuthenticationState();
            });
        });
    }

    #[\Override]
    public function delete()
    {
        // One canonical deletion boundary: the user-row lock is taken
        // first and held through snapshot capture, the deletion-hook
        // cleanup, and the physical row removal in a single transaction
        // on this model's connection. A guest-claim racing this deletion
        // takes the same first lock, so it either commits before (and is
        // revoked inside the cleanup) or observes the deleted user —
        // never a new active association on a removed claimant. The
        // hook's own transactions nest as savepoints on the same
        // connection; snapshot, restore, authentication, and financial
        // behavior below is unchanged.
        return DB::transaction(function (): mixed {
            User::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($this->exists && $this->deletedRelationsSnapshot === []) {
                $this->captureDeletedRelationsSnapshot();
            }

            return parent::delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesToKeep(): array
    {
        $attributes = $this->deletedModelsAttributesToKeep();

        unset($attributes['password'], $attributes['remember_token']);

        return array_merge($attributes, [
            'deleted_relations_snapshot' => $this->deletedRelationsSnapshot,
        ]);
    }

    public static function restoreDeletedUser(mixed $key): self
    {
        /** @var self $restoredUser */
        $restoredUser = DB::transaction(static fn (): Model => self::restore($key, static function (Model $restoredModel, DeletedModel $deletedModel): void {
            unset($deletedModel);

            unset($restoredModel->deleted_relations_snapshot);
        }));

        return $restoredUser;
    }

    public static function afterRestoringModel(Model $restoredModel, DeletedModel $deletedModel): void
    {
        if (! $restoredModel instanceof self) {
            return;
        }

        $snapshot = $deletedModel->value('deleted_relations_snapshot');

        if (! is_array($snapshot)) {
            return;
        }

        $authorization = $snapshot['authorization'] ?? [];
        $authorization = is_array($authorization) ? $authorization : [];
        $contributorSnapshot = array_filter($snapshot, is_array(...));
        $accountId = (string) $restoredModel->getKey();

        OwnerContext::withOwner(null, function () use ($contributorSnapshot, $authorization, $accountId): void {
            app(AccountLifecycle::class)->restore($accountId, $contributorSnapshot);
            $restoredModel->restoreGlobalPermissionRows($authorization);
            app(AccountLifecycle::class)->afterRestore($accountId);
        });
    }

    protected function captureDeletedRelationsSnapshot(): void
    {
        $authorization = [
            'model_has_roles' => $this->permissionRows(DB::table((string) (config('permission.table_names.model_has_roles') ?: 'model_has_roles'))),
            'model_has_permissions' => $this->permissionRows(DB::table((string) (config('permission.table_names.model_has_permissions') ?: 'model_has_permissions'))),
        ];

        $this->deletedRelationsSnapshot = app(AccountLifecycle::class)->snapshot((string) $this->getKey()) + [
            'authorization' => $authorization,
        ];
    }

    protected function deleteAuthenticationState(): void
    {
        $this->tokens()->delete();

        DB::table('sessions')->where('user_id', $this->id)->delete();
        DB::table('password_reset_tokens')->where('email', $this->email)->delete();
        DB::table('oauth_auth_codes')->where('user_id', $this->id)->delete();
        DB::table('oauth_device_codes')->where('user_id', $this->id)->delete();

        $passportAccessTokenIds = DB::table('oauth_access_tokens')
            ->where('user_id', $this->id)
            ->pluck('id')
            ->all();

        if ($passportAccessTokenIds !== []) {
            DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $passportAccessTokenIds)
                ->delete();
        }

        DB::table('oauth_access_tokens')->where('user_id', $this->id)->delete();
    }

    /**
     * Global (team-null) authorization rows are conventional Authz state and
     * remain outside the account lifecycle contributors.
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected function restoreGlobalPermissionRows(array $snapshot): void
    {
        DB::table((string) (config('permission.table_names.model_has_roles') ?: 'model_has_roles'))
            ->insertOrIgnore($this->snapshotRows($snapshot, 'model_has_roles'));
        DB::table((string) (config('permission.table_names.model_has_permissions') ?: 'model_has_permissions'))
            ->insertOrIgnore($this->snapshotRows($snapshot, 'model_has_permissions'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function snapshotRows(array $snapshot, string $key): array
    {
        $rows = $snapshot[$key] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values()
            ->all();
    }

    /**
     * Global (team-null) authorization rows only.
     *
     * Team-scoped role and permission rows are restored by the Membership
     * account lifecycle contributor; the conventional Authz path keeps the
     * application-wide rows.
     *
     * @return list<array<string, mixed>>
     */
    private function permissionRows(QueryBuilder $query): array
    {
        return $query
            ->where($this->permissionModelKeyName(), $this->getKey())
            ->where('model_type', $this->getMorphClass())
            ->whereNull($this->permissionTeamKey())
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
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
}
