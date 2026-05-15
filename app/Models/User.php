<?php

namespace App\Models;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateBalance;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateDailyStat;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliatePayout;
use AIArmada\Affiliates\Models\AffiliatePayoutHold;
use AIArmada\Affiliates\Models\AffiliatePayoutMethod;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\Permission;
use AIArmada\FilamentAuthz\Models\Role;
use App\Concerns\HasTeams;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDestinationStatus;
use App\Models\Concerns\AuditsModelChanges;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\NotificationCenterMessage;
use App\Services\ShareTrackingService;
use App\Support\Submission\PublicSubmissionLockService;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\DeletedModels\Models\Concerns\KeepsDeletedModels;
use Spatie\DeletedModels\Models\DeletedModel;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements AuditableContract, FilamentUser, HasLocalePreference, MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use AuditsModelChanges, HasApiTokens, HasFactory, HasRoles, HasTeams, HasUuids, KeepsDeletedModels, MustVerifyEmail, Notifiable {
        HasTeams::teams insteadof HasRoles;
        KeepsDeletedModels::attributesToKeep as protected deletedModelsAttributesToKeep;
    }

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var array<string, mixed>
     */
    protected array $deletedRelationsSnapshot = [];

    /**
     * @var array<string, mixed>
     */
    protected array $deletedAffiliateTrackingSnapshot = [];

    #[\Override]
    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if (! $user->wasChanged('phone_verified_at')) {
                return;
            }

            app(PublicSubmissionLockService::class)->syncForUser($user);
        });

        static::deleting(function (User $user) {
            if ($user->deletedRelationsSnapshot === []) {
                $user->captureDeletedRelationsSnapshot();
            }

            $savedEventIds = $user->snapshotEventIds($user->deletedRelationsSnapshot, 'event_saves');
            $goingEventIds = $user->snapshotEventIds($user->deletedRelationsSnapshot, 'event_attendees');

            $user->socialAccounts()->each(fn ($account) => $account->delete());
            $user->deleteAuthenticationState();
            $user->institutions()->detach();
            $user->speakers()->detach();
            $user->references()->detach();
            DB::table('user_venue')->where('user_id', $user->id)->delete();
            $user->memberEvents()->detach();
            $user->savedEvents()->detach();
            $user->goingEvents()->detach();

            $user->syncEventEngagementCounts($savedEventIds, 'event_saves', 'saves_count');
            $user->syncEventEngagementCounts($goingEventIds, 'event_attendees', 'going_count');

            $user->ownedEvents()->update(['user_id' => null]);
            $user->submittedEvents()->update(['submitter_id' => null]);
            $user->eventSubmissions()->update(['submitted_by' => null]);
            $user->contributionRequests()->update(['proposer_id' => null]);
            $user->reviewedContributionRequests()->update(['reviewer_id' => null]);
            $user->membershipClaims()->update(['claimant_id' => null]);
            $user->reviewedMembershipClaims()->update(['reviewer_id' => null]);
            $user->moderationReviews()->update(['moderator_id' => null]);
            $user->reports()->update(['reporter_id' => null]);
            $user->handledReports()->update(['handled_by' => null]);
            $user->verifiedDonationChannels()->update(['verified_by' => null]);
            $user->verifiedEventCheckins()->update(['verified_by_user_id' => null]);
            $user->registrations()->each(fn ($reg) => $reg->delete());
            $user->eventCheckins()->each(fn ($checkin) => $checkin->delete());
            $user->savedSearches()->each(fn ($search) => $search->delete());
            $user->aiUsageLogs()->each(fn ($log) => $log->delete());
            app(ShareTrackingService::class)->deleteUserTracking($user);
            $user->notificationSetting()->delete();
            $user->notificationRules()->each(fn ($rule) => $rule->delete());
            $user->notificationDestinations()->each(fn ($destination) => $destination->delete());
            $user->pendingNotifications()->each(fn ($notification) => $notification->delete());
            $user->notificationMessages()->each(fn ($message) => $message->delete());
            $user->notificationDeliveries()->each(fn ($delivery) => $delivery->delete());

            DB::table('followings')->where('user_id', $user->id)->delete();
        });
    }

    /**
     * Capture relationships before package-level deleting hooks detach permission rows.
     */
    #[\Override]
    public function delete()
    {
        if ($this->exists && $this->deletedRelationsSnapshot === []) {
            $this->captureDeletedRelationsSnapshot();
        }

        return parent::delete();
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
            'deleted_affiliate_tracking_snapshot' => $this->deletedAffiliateTrackingSnapshot,
        ]);
    }

    public static function restoreDeletedUser(mixed $key): self
    {
        /** @var self $restoredUser */
        $restoredUser = DB::transaction(static fn (): Model => self::restore($key, static function (Model $restoredModel, DeletedModel $deletedModel): void {
            unset($deletedModel);

            unset($restoredModel->deleted_relations_snapshot);
            unset($restoredModel->deleted_affiliate_tracking_snapshot);
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

        $restoredModel->restoreManyToManyRelations($snapshot);
        $restoredModel->restoreReassignedRelations($snapshot);
        $restoredModel->restoreDeletedAffiliateTrackingSnapshot($deletedModel->value('deleted_affiliate_tracking_snapshot'));
        $restoredModel->restoreDeletedChildModels($snapshot);
    }

    protected function captureDeletedRelationsSnapshot(): void
    {
        $this->deletedRelationsSnapshot = [
            'institution_user' => $this->institutions()->get()->map(fn (Institution $institution): array => [
                'institution_id' => $institution->getKey(),
                'user_id' => $this->getKey(),
                'joined_at' => $this->pivotTimestamp($institution, 'joined_at'),
                'created_at' => $this->pivotTimestamp($institution, 'created_at'),
                'updated_at' => $this->pivotTimestamp($institution, 'updated_at'),
            ])->all(),
            'speaker_user' => $this->speakers()->get()->map(fn (Speaker $speaker): array => [
                'speaker_id' => $speaker->getKey(),
                'user_id' => $this->getKey(),
                'joined_at' => $this->pivotTimestamp($speaker, 'joined_at'),
                'created_at' => $this->pivotTimestamp($speaker, 'created_at'),
                'updated_at' => $this->pivotTimestamp($speaker, 'updated_at'),
            ])->all(),
            'reference_user' => $this->references()->get()->map(fn (Reference $reference): array => [
                'reference_id' => $reference->getKey(),
                'user_id' => $this->getKey(),
                'joined_at' => $this->pivotTimestamp($reference, 'joined_at'),
                'created_at' => $this->pivotTimestamp($reference, 'created_at'),
                'updated_at' => $this->pivotTimestamp($reference, 'updated_at'),
            ])->all(),
            'user_venue' => DB::table('user_venue')
                ->where('user_id', $this->id)
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'event_saves' => $this->savedEvents()->get()->map(fn (Event $event): array => [
                'event_id' => $event->getKey(),
                'user_id' => $this->getKey(),
                'created_at' => $this->pivotTimestamp($event, 'created_at'),
                'updated_at' => $this->pivotTimestamp($event, 'updated_at'),
            ])->all(),
            'event_attendees' => $this->goingEvents()->get()->map(fn (Event $event): array => [
                'event_id' => $event->getKey(),
                'user_id' => $this->getKey(),
                'created_at' => $this->pivotTimestamp($event, 'created_at'),
                'updated_at' => $this->pivotTimestamp($event, 'updated_at'),
            ])->all(),
            'event_user' => $this->memberEvents()->get()->map(fn (Event $event): array => [
                'event_id' => $event->getKey(),
                'user_id' => $this->getKey(),
                'joined_at' => $this->pivotTimestamp($event, 'joined_at'),
                'created_at' => $this->pivotTimestamp($event, 'created_at'),
                'updated_at' => $this->pivotTimestamp($event, 'updated_at'),
            ])->all(),
            'model_has_roles' => $this->permissionRows('model_has_roles'),
            'model_has_permissions' => $this->permissionRows('model_has_permissions'),
            'owned_event_ids' => $this->ownedEvents()->pluck('id')->all(),
            'submitted_event_ids' => $this->submittedEvents()->pluck('id')->all(),
            'event_submission_ids' => $this->eventSubmissions()->pluck('id')->all(),
            'contribution_request_proposer_ids' => $this->contributionRequests()->pluck('id')->all(),
            'contribution_request_reviewer_ids' => $this->reviewedContributionRequests()->pluck('id')->all(),
            'membership_claim_ids' => $this->membershipClaims()->pluck('id')->all(),
            'membership_claim_reviewer_ids' => $this->reviewedMembershipClaims()->pluck('id')->all(),
            'moderation_review_ids' => $this->moderationReviews()->pluck('id')->all(),
            'report_ids' => $this->reports()->pluck('id')->all(),
            'handled_report_ids' => $this->handledReports()->pluck('id')->all(),
            'verified_donation_channel_ids' => $this->verifiedDonationChannels()->pluck('id')->all(),
            'verified_event_checkin_ids' => $this->verifiedEventCheckins()->pluck('id')->all(),
            'social_accounts' => $this->socialAccounts()->get()->map->attributesToArray()->all(),
            'registrations' => $this->registrations()->get()->map->attributesToArray()->all(),
            'event_checkins' => $this->eventCheckins()->get()->map->attributesToArray()->all(),
            'saved_searches' => $this->savedSearches()->get()->map->attributesToArray()->all(),
            'ai_usage_logs' => $this->aiUsageLogs()->get()->map->attributesToArray()->all(),
            'notification_setting' => $this->notificationSetting?->attributesToArray(),
            'notification_rules' => $this->notificationRules()->get()->map->attributesToArray()->all(),
            'notification_destinations' => $this->notificationDestinations()->get()->map->attributesToArray()->all(),
            'pending_notifications' => $this->pendingNotifications()->get()->map->attributesToArray()->all(),
            'notification_messages' => $this->notificationMessages()->get()->map->attributesToArray()->all(),
            'notification_deliveries' => $this->notificationDeliveries()->get()->map->attributesToArray()->all(),
            'followings' => DB::table('followings')
                ->where('user_id', $this->id)
                ->get()
                ->map(fn (object $row): array => [
                    'followable_id' => $row->followable_id,
                    'followable_type' => $row->followable_type,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])
                ->all(),
        ];

        $this->captureDeletedAffiliateTrackingSnapshot();
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

    protected function captureDeletedAffiliateTrackingSnapshot(): void
    {
        OwnerContext::withOwner($this, function (): void {
            /** @var Affiliate|null $affiliate */
            $affiliate = Affiliate::query()
                ->where('owner_type', $this->getMorphClass())
                ->where('owner_id', $this->getKey())
                ->first();

            if (! $affiliate instanceof Affiliate) {
                $this->deletedAffiliateTrackingSnapshot = [];

                return;
            }

            $this->deletedAffiliateTrackingSnapshot = [
                'affiliate' => $affiliate->getAttributes(),
                'links' => $affiliate->links()->get()->map(fn (AffiliateLink $link): array => $link->getAttributes())->all(),
                'attributions' => $affiliate->attributions()->get()->map(fn (AffiliateAttribution $attribution): array => $attribution->getAttributes())->all(),
                'touchpoints' => AffiliateTouchpoint::query()
                    ->where('affiliate_id', $affiliate->id)
                    ->get()
                    ->map(fn (AffiliateTouchpoint $touchpoint): array => $touchpoint->getAttributes())
                    ->all(),
                'conversions' => $affiliate->conversions()->get()->map(fn (AffiliateConversion $conversion): array => $conversion->getAttributes())->all(),
                'payouts' => $affiliate->payouts()->get()->map(fn (AffiliatePayout $payout): array => $payout->getAttributes())->all(),
                'fraud_signals' => $affiliate->fraudSignals()->get()->map(fn (AffiliateFraudSignal $fraudSignal): array => $fraudSignal->getAttributes())->all(),
                'daily_stats' => $affiliate->dailyStats()->get()->map(fn (AffiliateDailyStat $dailyStat): array => $dailyStat->getAttributes())->all(),
                'balance' => $affiliate->balance?->getAttributes(),
                'payout_methods' => $affiliate->payoutMethods()->get()->map(fn (AffiliatePayoutMethod $payoutMethod): array => $payoutMethod->getAttributes())->all(),
                'payout_holds' => $affiliate->payoutHolds()->get()->map(fn (AffiliatePayoutHold $payoutHold): array => $payoutHold->getAttributes())->all(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function restoreManyToManyRelations(array $snapshot): void
    {
        $savedEventIds = $this->snapshotEventIds($snapshot, 'event_saves');
        $goingEventIds = $this->snapshotEventIds($snapshot, 'event_attendees');

        DB::table('institution_user')->insertOrIgnore($this->snapshotRows($snapshot, 'institution_user'));
        DB::table('speaker_user')->insertOrIgnore($this->snapshotRows($snapshot, 'speaker_user'));
        DB::table('reference_user')->insertOrIgnore($this->snapshotRows($snapshot, 'reference_user'));
        DB::table('user_venue')->insertOrIgnore($this->snapshotRows($snapshot, 'user_venue'));
        DB::table('event_saves')->insertOrIgnore($this->snapshotRows($snapshot, 'event_saves'));
        DB::table('event_attendees')->insertOrIgnore($this->snapshotRows($snapshot, 'event_attendees'));
        DB::table('event_user')->insertOrIgnore($this->snapshotRows($snapshot, 'event_user'));
        DB::table($this->permissionTable('model_has_roles'))->insertOrIgnore($this->snapshotRows($snapshot, 'model_has_roles'));
        DB::table($this->permissionTable('model_has_permissions'))->insertOrIgnore($this->snapshotRows($snapshot, 'model_has_permissions'));
        DB::table('followings')->insertOrIgnore(collect($this->snapshotRows($snapshot, 'followings'))->map(fn (array $row): array => array_merge([
            'user_id' => $this->id,
        ], $row))->all());

        $this->syncEventEngagementCounts($savedEventIds, 'event_saves', 'saves_count');
        $this->syncEventEngagementCounts($goingEventIds, 'event_attendees', 'going_count');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function snapshotEventIds(array $snapshot, string $key): array
    {
        return collect($this->snapshotRows($snapshot, $key))
            ->pluck('event_id')
            ->filter(fn (mixed $eventId): bool => is_string($eventId) || is_int($eventId))
            ->map(static fn (mixed $eventId): string => (string) $eventId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $eventIds
     */
    private function syncEventEngagementCounts(array $eventIds, string $pivotTable, string $column): void
    {
        foreach ($eventIds as $eventId) {
            $count = (int) DB::table($pivotTable)
                ->where('event_id', $eventId)
                ->count();

            Event::query()
                ->whereKey($eventId)
                ->update([$column => $count]);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function restoreReassignedRelations(array $snapshot): void
    {
        $this->restoreForeignKeyRelation('ownedEvents', 'user_id', $this->snapshotIds($snapshot, 'owned_event_ids'));
        $this->restoreForeignKeyRelation('submittedEvents', 'submitter_id', $this->snapshotIds($snapshot, 'submitted_event_ids'));
        $this->restoreForeignKeyRelation('eventSubmissions', 'submitted_by', $this->snapshotIds($snapshot, 'event_submission_ids'));
        $this->restoreForeignKeyRelation('contributionRequests', 'proposer_id', $this->snapshotIds($snapshot, 'contribution_request_proposer_ids'));
        $this->restoreForeignKeyRelation('reviewedContributionRequests', 'reviewer_id', $this->snapshotIds($snapshot, 'contribution_request_reviewer_ids'));
        $this->restoreForeignKeyRelation('membershipClaims', 'claimant_id', $this->snapshotIds($snapshot, 'membership_claim_ids'));
        $this->restoreForeignKeyRelation('reviewedMembershipClaims', 'reviewer_id', $this->snapshotIds($snapshot, 'membership_claim_reviewer_ids'));
        $this->restoreForeignKeyRelation('moderationReviews', 'moderator_id', $this->snapshotIds($snapshot, 'moderation_review_ids'));
        $this->restoreForeignKeyRelation('reports', 'reporter_id', $this->snapshotIds($snapshot, 'report_ids'));
        $this->restoreForeignKeyRelation('handledReports', 'handled_by', $this->snapshotIds($snapshot, 'handled_report_ids'));
        $this->restoreForeignKeyRelation('verifiedDonationChannels', 'verified_by', $this->snapshotIds($snapshot, 'verified_donation_channel_ids'));
        $this->restoreForeignKeyRelation('verifiedEventCheckins', 'verified_by_user_id', $this->snapshotIds($snapshot, 'verified_event_checkin_ids'));
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

            $affiliate = $this->restoreRawModel(Affiliate::class, $affiliateAttributes);

            if (! $affiliate instanceof Affiliate) {
                return;
            }

            if (! $affiliate->belongsToOwner($this)) {
                $affiliate->assignOwner($this)->saveQuietly();
            }

            $this->restoreRawModels(AffiliateLink::class, $record['links'] ?? []);
            $this->restoreRawModels(AffiliateAttribution::class, $record['attributions'] ?? []);
            $this->restoreRawModels(AffiliateTouchpoint::class, $record['touchpoints'] ?? []);
            $this->restoreRawModels(AffiliateConversion::class, $record['conversions'] ?? []);
            $this->restoreRawModels(AffiliatePayout::class, $record['payouts'] ?? []);
            $this->restoreRawModels(AffiliateFraudSignal::class, $record['fraud_signals'] ?? []);
            $this->restoreRawModels(AffiliateDailyStat::class, $record['daily_stats'] ?? []);

            if (is_array($record['balance'] ?? null)) {
                $this->restoreRawModel(AffiliateBalance::class, $record['balance']);
            }

            $this->restoreRawModels(AffiliatePayoutMethod::class, $record['payout_methods'] ?? []);
            $this->restoreRawModels(AffiliatePayoutHold::class, $record['payout_holds'] ?? []);
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function restoreDeletedChildModels(array $snapshot): void
    {
        $this->restoreChildModels('socialAccounts', $this->snapshotRows($snapshot, 'social_accounts'));
        $this->restoreChildModels('registrations', $this->snapshotRows($snapshot, 'registrations'));
        $this->restoreChildModels('eventCheckins', $this->snapshotRows($snapshot, 'event_checkins'));
        $this->restoreChildModels('savedSearches', $this->snapshotRows($snapshot, 'saved_searches'));
        $this->restoreChildModels('aiUsageLogs', $this->snapshotRows($snapshot, 'ai_usage_logs'));
        $this->restoreSingleChildModel('notificationSetting', $snapshot['notification_setting'] ?? null);
        $this->restoreChildModels('notificationRules', $this->snapshotRows($snapshot, 'notification_rules'));
        $this->restoreChildModels('notificationDestinations', $this->snapshotRows($snapshot, 'notification_destinations'));
        $this->restoreChildModels('pendingNotifications', $this->snapshotRows($snapshot, 'pending_notifications'));
        $this->restoreChildModels('notificationMessages', $this->snapshotRows($snapshot, 'notification_messages'));
        $this->restoreChildModels('notificationDeliveries', $this->snapshotRows($snapshot, 'notification_deliveries'));
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    protected function restoreChildModels(string $relationName, array $records): void
    {
        if ($records === []) {
            return;
        }

        $modelClass = $this->{$relationName}()->getRelated()::class;

        foreach ($records as $attributes) {
            if (! is_array($attributes)) {
                continue;
            }

            /** @var Model $model */
            $model = new $modelClass;
            $model->timestamps = false;
            $model->forceFill($attributes);
            $model->saveQuietly();
        }
    }

    protected function restoreSingleChildModel(string $relationName, mixed $record): void
    {
        if (! is_array($record)) {
            return;
        }

        $modelClass = $this->{$relationName}()->getRelated()::class;

        /** @var Model $model */
        $model = new $modelClass;
        $model->timestamps = false;
        $model->forceFill($record);
        $model->saveQuietly();
    }

    /**
     * @param  list<int|string>  $ids
     */
    protected function restoreForeignKeyRelation(string $relationName, string $foreignKey, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $modelClass = $this->{$relationName}()->getRelated()::class;

        $modelClass::query()
            ->whereKey($ids)
            ->whereNull($foreignKey)
            ->update([$foreignKey => $this->id]);
    }

    private function pivotTimestamp(Model $model, string $attribute): ?string
    {
        $pivot = $model->getRelationValue('pivot');

        if (! $pivot instanceof Pivot) {
            return null;
        }

        $value = $pivot->getAttribute($attribute);

        if ($value instanceof CarbonInterface) {
            return $value->toDateTimeString();
        }

        return is_string($value) && $value !== '' ? $value : null;
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
     * @param  array<string, mixed>  $snapshot
     * @return list<int|string>
     */
    private function snapshotIds(array $snapshot, string $key): array
    {
        $ids = $snapshot[$key] ?? [];

        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(fn (mixed $id): bool => is_int($id) || is_string($id))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function permissionRows(string $tableKey): array
    {
        return DB::table($this->permissionTable($tableKey))
            ->where($this->permissionModelKeyName(), $this->getKey())
            ->where('model_type', $this->getMorphClass())
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

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $attributes
     */
    protected function restoreRawModel(string $modelClass, array $attributes): Model
    {
        $primaryKey = (new $modelClass)->getKeyName();
        $model = $modelClass::query()->find($attributes[$primaryKey] ?? null) ?? new $modelClass;
        $model->timestamps = false;
        $model->setRawAttributes($attributes, true);
        $model->saveQuietly();

        return $model;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, array<string, mixed>>  $records
     */
    protected function restoreRawModels(string $modelClass, array $records): void
    {
        foreach ($records as $attributes) {
            if (! is_array($attributes)) {
                continue;
            }

            $this->restoreRawModel($modelClass, $attributes);
        }
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'timezone',
        'current_team_id',
        'daily_prayer_institution_id',
        'friday_prayer_institution_id',
        'password',
        'email_verified_at',
        'phone_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canSubmitDirectoryFeedback(): bool
    {
        return ! $this->isDirectoryFeedbackBlocked();
    }

    public function canSubmitIntegrationFeedback(): bool
    {
        return $this->canSubmitDirectoryFeedback();
    }

    public function isDirectoryFeedbackBlocked(): bool
    {
        return Authz::withScope(null, function (): bool {
            $permission = Permission::query()
                ->where('name', 'feedback.blocked')
                ->where('guard_name', $this->getDefaultGuardName())
                ->first();

            return $permission instanceof Permission && $this->hasDirectPermission($permission);
        }, $this);
    }

    public function directoryFeedbackBanMessage(): string
    {
        return __('Akaun anda tidak dibenarkan menghantar cadangan kemaskini, tuntutan keahlian, atau laporan buat masa ini.');
    }

    public function integrationFeedbackBanMessage(): string
    {
        return $this->directoryFeedbackBanMessage();
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function dailyPrayerInstitution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'daily_prayer_institution_id');
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function fridayPrayerInstitution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'friday_prayer_institution_id');
    }

    /**
     * @return BelongsToMany<Institution, $this>
     */
    public function institutions(): BelongsToMany
    {
        return $this->belongsToMany(Institution::class, 'institution_user')
            ->withPivot(['joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Speaker, $this>
     */
    public function speakers(): BelongsToMany
    {
        return $this->belongsToMany(Speaker::class, 'speaker_user')
            ->withPivot(['joined_at'])
            ->withTimestamps();
    }

    /**
     * @return MorphToMany<Role, $this, MorphPivot, 'pivot'>
     */
    public function globalRoles(): MorphToMany
    {
        $registrar = app(PermissionRegistrar::class);

        if (! $registrar->teams) {
            /** @var MorphToMany<Role, $this, MorphPivot, 'pivot'> $relation */
            $relation = $this->roles();

            return $relation;
        }

        $teamsKey = $registrar->teamsKey;
        $rolesTable = config('permission.table_names.roles', 'roles');
        /** @var class-string<Role> $roleModel */
        $roleModel = config('permission.models.role', Role::class);

        return $this->morphToMany(
            $roleModel,
            'model',
            config('permission.table_names.model_has_roles'),
            config('permission.column_names.model_morph_key'),
            $registrar->pivotRole,
        )
            ->withPivot($teamsKey)
            ->wherePivot($teamsKey)
            ->whereNull("{$rolesTable}.{$teamsKey}");
    }

    /**
     * @return BelongsToMany<Reference, $this>
     */
    public function references(): BelongsToMany
    {
        return $this->belongsToMany(Reference::class, 'reference_user')
            ->withPivot(['joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Venue, $this>
     */
    public function venues(): BelongsToMany
    {
        return $this->belongsToMany(Venue::class, 'user_venue')
            ->withPivot(['joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function submittedEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'submitter_id');
    }

    /**
     * @return HasMany<EventSubmission, $this>
     */
    public function eventSubmissions(): HasMany
    {
        return $this->hasMany(EventSubmission::class, 'submitted_by');
    }

    /**
     * @return HasMany<ContributionRequest, $this>
     */
    public function contributionRequests(): HasMany
    {
        return $this->hasMany(ContributionRequest::class, 'proposer_id');
    }

    /**
     * @return HasMany<ContributionRequest, $this>
     */
    public function reviewedContributionRequests(): HasMany
    {
        return $this->hasMany(ContributionRequest::class, 'reviewer_id');
    }

    /**
     * @return HasMany<MembershipClaim, $this>
     */
    public function membershipClaims(): HasMany
    {
        return $this->hasMany(MembershipClaim::class, 'claimant_id');
    }

    /**
     * @return HasMany<MembershipClaim, $this>
     */
    public function reviewedMembershipClaims(): HasMany
    {
        return $this->hasMany(MembershipClaim::class, 'reviewer_id');
    }

    /**
     * @return HasMany<ModerationReview, $this>
     */
    public function moderationReviews(): HasMany
    {
        return $this->hasMany(ModerationReview::class, 'moderator_id');
    }

    /**
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    /**
     * @return HasMany<Report, $this>
     */
    public function handledReports(): HasMany
    {
        return $this->hasMany(Report::class, 'handled_by');
    }

    /**
     * @return HasMany<Registration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /**
     * @return HasMany<EventCheckin, $this>
     */
    public function eventCheckins(): HasMany
    {
        return $this->hasMany(EventCheckin::class);
    }

    /**
     * @return HasMany<EventCheckin, $this>
     */
    public function verifiedEventCheckins(): HasMany
    {
        return $this->hasMany(EventCheckin::class, 'verified_by_user_id');
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * @return HasMany<SavedSearch, $this>
     */
    public function savedSearches(): HasMany
    {
        return $this->hasMany(SavedSearch::class);
    }

    /**
     * @return HasMany<AiUsageLog, $this>
     */
    public function aiUsageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
    }

    /**
     * @return BelongsToMany<Event, $this>
     */
    public function savedEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_saves')->withTimestamps();
    }

    /**
     * @return MorphToMany<Speaker, $this>
     */
    public function followingSpeakers(): MorphToMany
    {
        return $this->morphedByMany(Speaker::class, 'followable', 'followings')
            ->withTimestamps();
    }

    /**
     * @return MorphToMany<Institution, $this>
     */
    public function followingInstitutions(): MorphToMany
    {
        return $this->morphedByMany(Institution::class, 'followable', 'followings')
            ->withTimestamps();
    }

    /**
     * @return MorphToMany<Reference, $this>
     */
    public function followingReferences(): MorphToMany
    {
        return $this->morphedByMany(Reference::class, 'followable', 'followings')
            ->withTimestamps();
    }

    /**
     * @return MorphToMany<Series, $this>
     */
    public function followingSeries(): MorphToMany
    {
        return $this->morphedByMany(Series::class, 'followable', 'followings')
            ->withTimestamps();
    }

    public function follow(Model $followable): void
    {
        DB::table('followings')->insertOrIgnore([
            'user_id' => $this->id,
            'followable_id' => $followable->getKey(),
            'followable_type' => $followable->getMorphClass(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function unfollow(Model $followable): void
    {
        DB::table('followings')
            ->where('user_id', $this->id)
            ->where('followable_id', $followable->getKey())
            ->where('followable_type', $followable->getMorphClass())
            ->delete();
    }

    public function isFollowing(Model $followable): bool
    {
        return DB::table('followings')
            ->where('user_id', $this->id)
            ->where('followable_id', $followable->getKey())
            ->where('followable_type', $followable->getMorphClass())
            ->exists();
    }

    /**
     * @return BelongsToMany<Event, $this>
     */
    public function goingEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_attendees')->withTimestamps();
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function ownedEvents(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<DonationChannel, $this>
     */
    public function verifiedDonationChannels(): HasMany
    {
        return $this->hasMany(DonationChannel::class, 'verified_by');
    }

    /**
     * @return BelongsToMany<Event, $this, EventUser>
     */
    public function memberEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_user')
            ->using(EventUser::class)
            ->withPivot(['joined_at'])
            ->withTimestamps();
    }

    /**
     * @return MorphMany<NotificationMessage, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(NotificationMessage::class, 'notifiable')
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at');
    }

    /**
     * @return HasOne<NotificationSetting, $this>
     */
    public function notificationSetting(): HasOne
    {
        return $this->hasOne(NotificationSetting::class);
    }

    /**
     * @return HasMany<NotificationRule, $this>
     */
    public function notificationRules(): HasMany
    {
        return $this->hasMany(NotificationRule::class);
    }

    /**
     * @return HasMany<NotificationDestination, $this>
     */
    public function notificationDestinations(): HasMany
    {
        return $this->hasMany(NotificationDestination::class);
    }

    /**
     * @return HasMany<PendingNotification, $this>
     */
    public function pendingNotifications(): HasMany
    {
        return $this->hasMany(PendingNotification::class)->orderByDesc('occurred_at')->orderByDesc('created_at');
    }

    /**
     * @return MorphMany<NotificationMessage, $this>
     */
    public function notificationMessages(): MorphMany
    {
        return $this->notifications();
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function preferredLocale(): string
    {
        $locale = $this->notificationSetting()->value('locale');

        return is_string($locale) && $locale !== ''
            ? $locale
            : config('app.locale');
    }

    public function preferredTimezone(): string
    {
        $timezone = $this->notificationSetting()->value('timezone');

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        return is_string($this->timezone) && $this->timezone !== ''
            ? $this->timezone
            : (string) config('app.timezone', 'UTC');
    }

    #[\Override]
    public function sendEmailVerificationNotification(): void
    {
        if (! is_string($this->email) || trim($this->email) === '') {
            return;
        }

        $this->notify(new VerifyEmailNotification);
    }

    #[\Override]
    public function sendPasswordResetNotification($token): void
    {
        if (! is_string($this->email) || trim($this->email) === '') {
            return;
        }

        $this->notify(new ResetPasswordNotification((string) $token));
    }

    /**
     * @return array<int, string>|string|null
     */
    public function routeNotificationForMail(Notification $notification): array|string|null
    {
        if (! $notification instanceof NotificationCenterMessage) {
            return $this->email;
        }

        return $this->notificationDestinations()
            ->where('channel', NotificationChannel::Email->value)
            ->where('status', NotificationDestinationStatus::Active->value)
            ->orderByDesc('is_primary')
            ->value('address');
    }

    /**
     * @return Collection<int, NotificationDestination>
     */
    public function routeNotificationForPush(Notification $notification): Collection
    {
        return $this->notificationDestinations()
            ->where('channel', NotificationChannel::Push->value)
            ->where('status', NotificationDestinationStatus::Active->value)
            ->orderByDesc('is_primary')
            ->get();
    }

    /**
     * @return Collection<int, NotificationDestination>
     */
    public function routeNotificationForWhatsapp(Notification $notification): Collection
    {
        return $this->notificationDestinations()
            ->where('channel', NotificationChannel::Whatsapp->value)
            ->where('status', NotificationDestinationStatus::Active->value)
            ->orderByDesc('is_primary')
            ->get();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'ahli') {
            return $this->hasAhliPanelAccess();
        }

        if ($panel->getId() === 'admin') {
            return $this->hasApplicationAdminAccess();
        }

        return $this->roles()->exists();
    }

    public function hasAhliPanelAccess(): bool
    {
        return $this->institutions()->exists()
            || $this->speakers()->exists()
            || $this->references()->exists()
            || $this->memberEvents()->exists();
    }

    public function hasApplicationAdminAccess(): bool
    {
        return $this->hasGlobalRoleAssignment();
    }

    public function hasAdminMcpAccess(): bool
    {
        return $this->hasApplicationAdminAccess();
    }

    public function hasMemberMcpAccess(): bool
    {
        return $this->hasAhliPanelAccess();
    }

    public function hasAnyMcpAccess(): bool
    {
        return $this->hasAdminMcpAccess() || $this->hasMemberMcpAccess();
    }

    public function hasGlobalAdminAccess(): bool
    {
        return $this->globalRoles()
            ->whereIn('name', ['super_admin', 'admin'])
            ->exists();
    }

    private function hasGlobalRoleAssignment(): bool
    {
        $modelHasRolesTable = (string) (config('permission.table_names.model_has_roles') ?? 'model_has_roles');
        $modelMorphKey = (string) (config('permission.column_names.model_morph_key') ?? 'model_id');
        $teamForeignKey = (string) (config('permission.column_names.team_foreign_key') ?? 'team_id');

        $query = DB::table($modelHasRolesTable)
            ->where($modelMorphKey, $this->getKey())
            ->where('model_type', $this->getMorphClass());

        if (config('permission.teams')) {
            $query->whereNull($teamForeignKey);
        }

        return $query->exists();
    }
}
