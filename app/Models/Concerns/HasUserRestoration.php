<?php

namespace App\Models\Concerns;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use App\Features\Identity\Api\AccountLifecycle;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Support\ShareTracking\ShareTrackingFinance;
use App\Support\ShareTracking\ShareTrackingProfile;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
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
                // Conventional authentication state, share tracking, and global
                // authorization rows remain in this trait.
                app(AccountLifecycle::class)->detach((string) $user->getKey());
                $user->deleteAuthenticationState();
                // Share cleanup revokes this claimant's guest-claim associations
                // (rows preserved as revoked) and detaches their published
                // short links. Claim rows and capability verifiers are
                // deliberately never snapshotted here: a later restore
                // reactivates no claim, restores no secret, and replays no
                // historical award or notification.
                app(AffiliatesShareTrackingService::class)->deleteUserTracking($user);
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
        $restoredUser = DB::transaction(static function () use ($key): Model {
            $restoredUser = self::restore($key, static function (Model $restoredModel, DeletedModel $deletedModel): void {
                unset($deletedModel);

                unset($restoredModel->deleted_relations_snapshot);
            });

            self::deletedModels()->where('key', $key)->delete();

            return $restoredUser;
        });

        DB::table((new DeletedModel)->getTable())
            ->where('key', (string) $key)
            ->where('model', (new self)->getMorphClass())
            ->delete();

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

        $legacy = $snapshot['legacy'] ?? [];
        $legacy = is_array($legacy) ? $legacy : [];
        $contributorSnapshot = array_filter($snapshot, is_array(...));
        $accountId = (string) $restoredModel->getKey();

        OwnerContext::withOwner(null, function () use ($restoredModel, $snapshot, $contributorSnapshot, $legacy, $accountId): void {
            app(AccountLifecycle::class)->restore($accountId, $contributorSnapshot);
            $restoredModel->restoreDeletedAffiliateTrackingSnapshot($snapshot['share_tracking'] ?? null);
            $restoredModel->restoreGlobalPermissionRows($legacy);
            app(AccountLifecycle::class)->afterRestore($accountId);
        });
    }

    protected function captureDeletedRelationsSnapshot(): void
    {
        $legacy = [
            'model_has_roles' => $this->permissionRows('model_has_roles'),
            'model_has_permissions' => $this->permissionRows('model_has_permissions'),
        ];

        $this->deletedRelationsSnapshot = app(AccountLifecycle::class)->snapshot((string) $this->getKey()) + [
            'legacy' => $legacy,
            'share_tracking' => $this->captureDeletedAffiliateTrackingSnapshot(),
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
     * @return array<string, mixed>
     */
    protected function captureDeletedAffiliateTrackingSnapshot(): array
    {
        return OwnerContext::withOwner($this, function (): array {
            // Canonical owned share records only. An unmarked commercial
            // profile is never snapshotted, and financial rows are preserved
            // by non-deletion rather than snapshot replay.
            $affiliate = Affiliate::query()
                ->where('owner_type', $this->getMorphClass())
                ->where('owner_id', $this->getKey())
                ->get()
                ->first(fn (Affiliate $candidate): bool => ShareTrackingProfile::isMarkedMemberFor($candidate, $this));

            if (! $affiliate instanceof Affiliate) {
                return [];
            }

            return [
                'affiliate' => $affiliate->getAttributes(),
                'links' => $affiliate->links()->get()->map(fn (AffiliateLink $link): array => $link->getAttributes())->all(),
                'attributions' => $affiliate->attributions()->get()->map(fn (AffiliateAttribution $attribution): array => $attribution->getAttributes())->all(),
                'touchpoints' => AffiliateTouchpoint::query()
                    ->where('affiliate_id', $affiliate->id)
                    ->get()
                    ->map(fn (AffiliateTouchpoint $touchpoint): array => $touchpoint->getAttributes())
                    ->all(),
                'conversions' => $affiliate->conversions()->get()->map(fn (AffiliateConversion $conversion): array => $conversion->getAttributes())->all(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    /**
     * Global (team-null) authorization rows are conventional Authz state and
     * remain outside the account lifecycle contributors.
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected function restoreGlobalPermissionRows(array $snapshot): void
    {
        DB::table($this->permissionTable('model_has_roles'))->insertOrIgnore($this->snapshotRows($snapshot, 'model_has_roles'));
        DB::table($this->permissionTable('model_has_permissions'))->insertOrIgnore($this->snapshotRows($snapshot, 'model_has_permissions'));

        OwnerContext::withOwner(null, function (): void {});

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function restoreDeletedAffiliateTrackingSnapshot(mixed $record): void
    {
        if (! is_array($record)) {
            return;
        }

        OwnerContext::withOwner($this, function () use ($record): void {
            $affiliateAttributes = $record['affiliate'] ?? null;

            if (! is_array($affiliateAttributes)) {
                return;
            }

            // Marker plus owner match, validated before any write. A foreign
            // or tampered snapshot is skipped, never force-assigned.
            if (! $this->snapshotAffiliateBelongsToRestoredUser($affiliateAttributes)) {
                return;
            }

            $affiliate = $this->restoreShareAffiliate($affiliateAttributes);

            if (! $affiliate instanceof Affiliate) {
                return;
            }

            $affiliateId = (string) $affiliate->getKey();

            $this->restoreShareChildren(AffiliateLink::class, $this->snapshotRowsForAffiliate($record['links'] ?? [], $affiliateId), $affiliateId);
            $this->restoreShareChildren(AffiliateAttribution::class, $this->snapshotRowsForAffiliate($record['attributions'] ?? [], $affiliateId), $affiliateId);
            $this->restoreShareChildren(AffiliateTouchpoint::class, $this->snapshotRowsForAffiliate($record['touchpoints'] ?? [], $affiliateId), $affiliateId);
            $this->restoreShareChildren(AffiliateConversion::class, $this->snapshotRowsForAffiliate($record['conversions'] ?? [], $affiliateId), $affiliateId);
        });
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
    private function permissionRows(string $tableKey): array
    {
        return DB::table($this->permissionTable($tableKey))
            ->where($this->permissionModelKeyName(), $this->getKey())
            ->where('model_type', $this->getMorphClass())
            ->whereNull($this->permissionTeamKey())
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
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
     * @param  array<string, mixed>  $affiliateAttributes
     */
    private function snapshotAffiliateBelongsToRestoredUser(array $affiliateAttributes): bool
    {
        if (($affiliateAttributes['owner_type'] ?? null) !== $this->getMorphClass()
            || (string) ($affiliateAttributes['owner_id'] ?? '') !== (string) $this->getKey()) {
            return false;
        }

        $metadata = $affiliateAttributes['metadata'] ?? null;

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        return is_array($metadata)
            && ($metadata[ShareTrackingProfile::MARKER_KEY] ?? null) === true
            && ($metadata[ShareTrackingProfile::KIND_KEY] ?? null) === ShareTrackingProfile::KIND_MEMBER;
    }

    /**
     * @param  array<int, mixed>  $records
     * @return list<array<string, mixed>>
     */
    private function snapshotRowsForAffiliate(array $records, string $affiliateId): array
    {
        return collect($records)
            ->filter(fn (mixed $row): bool => is_array($row)
                && (string) ($row['affiliate_id'] ?? '') === $affiliateId)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function restoreShareAffiliate(array $attributes): ?Affiliate
    {
        $primaryKey = (new Affiliate)->getKeyName();
        $existing = Affiliate::query()->withoutGlobalScope(OwnerScope::class)->find($attributes[$primaryKey] ?? null);

        if ($existing instanceof Affiliate) {
            // A retained live finance/profile row is never overwritten from
            // a stale snapshot. It must still be the restored user's own
            // marked profile; a foreign row under this id is left untouched
            // and the share restore stops.
            if (! ShareTrackingProfile::isMarkedMemberFor($existing, $this)) {
                return null;
            }

            return $existing;
        }

        // A missing profile is recreated disabled: no automatic reactivation
        // of referral tracking until an explicit authorized fresh activation.
        $attributes['deactivated_at'] ??= now()->toDateTimeString();

        $model = new Affiliate;
        $model->timestamps = false;
        $model->setRawAttributes($attributes, true);
        $model->saveQuietly();

        return $model;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, mixed>  $records
     */
    private function restoreShareChildren(string $modelClass, array $records, string $affiliateId): void
    {
        $primaryKey = (new $modelClass)->getKeyName();

        foreach ($records as $attributes) {
            if (! is_array($attributes)) {
                continue;
            }

            // Monetary conversions are preserved by non-deletion, never by
            // snapshot replay. A snapshot copy is skipped even when missing.
            if ($modelClass === AffiliateConversion::class
                && ShareTrackingFinance::isMonetaryConversionAttributes($attributes)) {
                continue;
            }

            $id = $attributes[$primaryKey] ?? null;

            if ($id === null || $id === '') {
                continue;
            }

            $existing = $modelClass::query()->withoutGlobalScope(OwnerScope::class)->find($id);

            if ($existing instanceof Model) {
                // Never overwrite retained current state from an old
                // snapshot, and never reassociate a foreign existing row:
                // an existing id always wins over the snapshot copy.
                continue;
            }

            $model = new $modelClass;
            $model->timestamps = false;
            $model->setRawAttributes($this->sanitizeShareSnapshotAttributes($modelClass, $attributes), true);
            $model->saveQuietly();
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function sanitizeShareSnapshotAttributes(string $modelClass, array $attributes): array
    {
        if ($modelClass === AffiliateLink::class) {
            // Restored links stay disabled: the public token survives as an
            // identifier but earns nothing until a fresh activation.
            $attributes['deactivated_at'] ??= now()->toDateTimeString();
        }

        if ($modelClass === AffiliateAttribution::class) {
            // Expired/deleted private cookie capabilities are never restored
            // as usable capabilities: a missing or future expiry is clamped
            // to now while the historical ids/evidence are retained.
            $expiresAt = $attributes['expires_at'] ?? null;
            $isUsable = $expiresAt === null
                || ($expiresAt instanceof CarbonInterface && $expiresAt->isFuture())
                || (is_string($expiresAt) && strtotime($expiresAt) > time());

            if ($isUsable) {
                $attributes['expires_at'] = now()->toDateTimeString();
            }
        }

        return $attributes;
    }
}
