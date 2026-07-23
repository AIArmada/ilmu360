<?php

namespace App\Models\Concerns;

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
use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Engagement\Models\Response;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ShareTrackingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Spatie\DeletedModels\Models\DeletedModel;
use Spatie\Permission\PermissionRegistrar;

trait HasUserRestoration
{
    /**
     * @var array<string, mixed>
     */
    protected array $deletedRelationsSnapshot = [];

    /**
     * @var array<string, mixed>
     */
    protected array $deletedAffiliateTrackingSnapshot = [];

    public static function bootHasUserRestoration(): void
    {
        static::deleting(function (User $user): void {
            OwnerContext::withOwner(null, function () use ($user): void {
                if ($user->deletedRelationsSnapshot === []) {
                    $user->captureDeletedRelationsSnapshot();
                }

                $savedEventIds = collect((array) ($user->deletedRelationsSnapshot['event_saves'] ?? []))
                    ->pluck('bookmarkable_id')
                    ->filter(fn (mixed $id): bool => is_string($id) || is_int($id))
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->values()
                    ->all();
                $goingEventIds = $user->snapshotEventIds($user->deletedRelationsSnapshot, 'event_attendees');

                DB::table((new SocialAccount)->getTable())
                    ->where('user_id', $user->getKey())
                    ->delete();
                $user->deleteAuthenticationState();
                $user->institutions()->detach();
                $user->persons()->detach();
                $user->references()->detach();
                $user->venues()->each(fn (Follow $f): ?bool => $f->delete());
                $user->memberEvents()->detach();
                $user->eventBookmarks()->delete();
                $user->responses()->where('response_type', 'going')->get()->each->delete();

                $user->clearEventOwnership();
                $user->eventSubmissions()->update(['submitter_id' => null]);
                $user->contributionRequests()->update(['proposer_id' => null]);
                $user->reviewedContributionRequests()->update(['reviewer_id' => null]);
                $user->membershipApplications()->update(['applicant_id' => null]);
                $user->reviewedMembershipApplications()->update(['reviewer_id' => null]);
                $user->moderationReviews()->update([
                    'actioned_by_id' => null,
                ]);
                $user->reports()->update(['reporter_id' => null]);
                $user->handledReports()->update(['handled_by' => null]);
                $user->verifiedDonationChannels()->update(['verified_by' => null]);
                $user->verifiedEventCheckins()->update(['verified_by_user_id' => null]);
                $user->verifiedPersons()->update(['verified_by' => null]);
                $user->verifiedInstitutions()->update(['verified_by' => null]);
                $user->verifiedReferences()->update(['verified_by' => null]);
                $user->verifiedVenues()->update(['verified_by' => null]);
                $user->registrations()->each(fn ($reg) => $reg->delete());
                $user->eventCheckins()->each(fn ($checkin) => $checkin->delete());
                $user->savedSearches()->each(fn ($search) => $search->delete());
                $user->aiUsageLogs()->each(fn ($log) => $log->delete());
                app(ShareTrackingService::class)->deleteUserTracking($user);
                $user->notificationSetting()->delete();
                $user->notificationDestinations()->each(fn ($destination) => $destination->delete());
                $user->notificationInboxes()->each(fn ($inbox) => $inbox->delete());

                Follow::forFollower($user)->delete();
            });
        });
    }

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
        $restoredUser = DB::transaction(static function () use ($key): Model {
            $restoredUser = self::restore($key, static function (Model $restoredModel, DeletedModel $deletedModel): void {
                unset($deletedModel);

                unset($restoredModel->deleted_relations_snapshot);
                unset($restoredModel->deleted_affiliate_tracking_snapshot);
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

        OwnerContext::withOwner(null, function () use ($restoredModel, $snapshot, $deletedModel): void {
            $restoredModel->restoreManyToManyRelations($snapshot);
            $restoredModel->restoreReassignedRelations($snapshot);
            $restoredModel->restoreDeletedAffiliateTrackingSnapshot($deletedModel->value('deleted_affiliate_tracking_snapshot'));
            $restoredModel->restoreDeletedChildModels($snapshot);
        });
    }

    protected function captureDeletedRelationsSnapshot(): void
    {
        $this->deletedRelationsSnapshot = [
            'institution_members' => $this->institutions()->get()->map(fn (Institution $institution): array => [
                'institution_id' => $institution->getKey(),
                'user_id' => $this->getKey(),
                'joined_at' => $this->pivotTimestamp($institution, 'joined_at'),
                'created_at' => $this->pivotTimestamp($institution, 'created_at'),
                'updated_at' => $this->pivotTimestamp($institution, 'updated_at'),
            ])->all(),
            'person_members' => $this->persons()->get()->map(fn (Person $person): array => [
                'person_id' => $person->getKey(),
                'user_id' => $this->getKey(),
                'joined_at' => $this->pivotTimestamp($person, 'joined_at'),
                'created_at' => $this->pivotTimestamp($person, 'created_at'),
                'updated_at' => $this->pivotTimestamp($person, 'updated_at'),
            ])->all(),
            'reference_members' => $this->references()->get()->map(fn (Reference $reference): array => [
                'reference_id' => $reference->getKey(),
                'user_id' => $this->getKey(),
                'joined_at' => $this->pivotTimestamp($reference, 'joined_at'),
                'created_at' => $this->pivotTimestamp($reference, 'created_at'),
                'updated_at' => $this->pivotTimestamp($reference, 'updated_at'),
            ])->all(),
            'user_venue' => $this->venues()->get()->map(fn (Follow $f): array => [
                'follower_type' => $f->follower_type,
                'follower_id' => $f->follower_id,
                'followable_type' => $f->followable_type,
                'followable_id' => $f->followable_id,
                'status' => $f->status,
                'followed_at' => $f->followed_at?->toIso8601String(),
                'created_at' => $f->created_at?->toIso8601String(),
                'updated_at' => $f->updated_at?->toIso8601String(),
            ])->all(),
            'event_saves' => $this->eventBookmarks()->get()->map(fn (Bookmark $bookmark): array => [
                'bookmarkable_id' => $bookmark->bookmarkable_id,
                'bookmarker_type' => $bookmark->bookmarker_type,
                'bookmarker_id' => $bookmark->bookmarker_id,
                'bookmarked_at' => $bookmark->bookmarked_at?->toIso8601String(),
                'created_at' => $bookmark->created_at?->toIso8601String(),
                'updated_at' => $bookmark->updated_at?->toIso8601String(),
            ])->all(),
            'event_attendees' => $this->responses()->where('response_type', 'going')->get()->map(fn (Response $response): array => [
                'event_id' => $response->respondable_id,
                'response_type' => $response->response_type,
                'created_at' => $response->created_at?->toIso8601String(),
                'updated_at' => $response->updated_at?->toIso8601String(),
            ])->all(),
            'event_members' => $this->memberEvents()->get()->map(fn (Event $event): array => [
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
            'membership_claim_ids' => $this->membershipApplications()->pluck('id')->all(),
            'membership_application_reviewer_ids' => $this->reviewedMembershipApplications()->pluck('id')->all(),
            'moderation_review_ids' => $this->moderationReviews()->pluck('id')->all(),
            'report_ids' => $this->reports()->pluck('id')->all(),
            'handled_report_ids' => $this->handledReports()->pluck('id')->all(),
            'verified_donation_channel_ids' => $this->verifiedDonationChannels()->pluck('id')->all(),
            'verified_event_checkin_ids' => $this->verifiedEventCheckins()->pluck('id')->all(),
            'verified_person_ids' => $this->verifiedPersons()->pluck('id')->all(),
            'verified_institution_ids' => $this->verifiedInstitutions()->pluck('id')->all(),
            'verified_reference_ids' => $this->verifiedReferences()->pluck('id')->all(),
            'verified_venue_ids' => $this->verifiedVenues()->pluck('id')->all(),
            'social_accounts' => $this->socialAccounts()->get()->map->attributesToArray()->all(),
            'registrations' => $this->registrations()->get()->map->attributesToArray()->all(),
            'event_checkins' => $this->eventCheckins()->get()->map->attributesToArray()->all(),
            'saved_searches' => $this->savedSearches()->get()->map->attributesToArray()->all(),
            'ai_usage_logs' => $this->aiUsageLogs()->get()->map->attributesToArray()->all(),
            'notification_setting' => $this->notificationSetting?->attributesToArray(),
            'notification_destinations' => $this->notificationDestinations()->get()->map->attributesToArray()->all(),
            'notification_inboxes' => $this->notificationInboxes()->get()->map->attributesToArray()->all(),
            'followings' => Follow::forFollower($this)
                ->get()
                ->map(function (Follow $follow): array {
                    $status = $follow->getAttribute('status');

                    return [
                        'follower_type' => $follow->follower_type,
                        'follower_id' => $follow->follower_id,
                        'followable_id' => $follow->followable_id,
                        'followable_type' => $follow->followable_type,
                        'status' => $status,
                        'followed_at' => $follow->followed_at?->toISOString(),
                        'unfollowed_at' => $follow->unfollowed_at?->toISOString(),
                        'notification_level' => $follow->notification_level,
                        'source' => $follow->source,
                    ];
                })
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
        collect((array) ($snapshot['event_saves'] ?? []))
            ->pluck('bookmarkable_id')
            ->filter(fn (mixed $id): bool => is_string($id) || is_int($id))
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
        $this->snapshotEventIds($snapshot, 'event_attendees');

        DB::table('institution_members')->insertOrIgnore($this->snapshotRows($snapshot, 'institution_members'));
        DB::table('person_members')->insertOrIgnore($this->snapshotRows($snapshot, 'person_members'));
        DB::table('reference_members')->insertOrIgnore($this->snapshotRows($snapshot, 'reference_members'));
        foreach ($snapshot['user_venue'] ?? [] as $venueFollowData) {
            if (filled($venueFollowData['followable_id'] ?? null)) {
                OwnerContext::withOwner(null, function () use ($venueFollowData): void {
                    Follow::query()->firstOrCreate(
                        [
                            'follower_type' => $venueFollowData['follower_type'] ?? $this->getMorphClass(),
                            'follower_id' => $venueFollowData['follower_id'] ?? $this->getKey(),
                            'followable_type' => $venueFollowData['followable_type'],
                            'followable_id' => $venueFollowData['followable_id'],
                        ],
                        [
                            'status' => $venueFollowData['status'] ?? 'active',
                            'followed_at' => $venueFollowData['followed_at'] ?? now(),
                        ]
                    );
                });
            }
        }
        foreach ($snapshot['event_saves'] ?? [] as $data) {
            OwnerContext::withOwner(null, function () use ($data): void {
                Bookmark::query()->firstOrCreate(
                    [
                        'bookmarker_type' => $data['bookmarker_type'] ?? $this->getMorphClass(),
                        'bookmarker_id' => $data['bookmarker_id'] ?? $this->getKey(),
                        'bookmarkable_type' => (new Event)->getMorphClass(),
                        'bookmarkable_id' => $data['bookmarkable_id'],
                    ],
                    [
                        'status' => 'active',
                        'bookmarked_at' => $data['bookmarked_at'] ?? now(),
                    ]
                );
            });
        }
        foreach ($snapshot['event_attendees'] ?? [] as $goingData) {
            OwnerContext::withOwner(null, function () use ($goingData): void {
                $event = Event::query()->find($goingData['event_id'] ?? null);
                if ($event instanceof Event) {
                    $this->respond($event, $goingData['response_type'] ?? 'going');
                }
            });
        }
        DB::table('event_members')->insertOrIgnore($this->snapshotRows($snapshot, 'event_members'));
        DB::table($this->permissionTable('model_has_roles'))->insertOrIgnore($this->snapshotRows($snapshot, 'model_has_roles'));
        DB::table($this->permissionTable('model_has_permissions'))->insertOrIgnore($this->snapshotRows($snapshot, 'model_has_permissions'));
        if (($snapshot['followings'] ?? []) !== []) {
            OwnerContext::withOwner(null, function () use ($snapshot): void {
                $userId = $this->getKey();
                $morphClass = (new User)->getMorphClass();
                foreach ($snapshot['followings'] as $data) {
                    Follow::query()->firstOrCreate(
                        [
                            'follower_type' => $data['follower_type'] ?? $morphClass,
                            'follower_id' => $data['follower_id'] ?? $userId,
                            'followable_type' => $data['followable_type'],
                            'followable_id' => $data['followable_id'],
                        ],
                        [
                            'status' => $data['status'] ?? 'active',
                            'followed_at' => isset($data['followed_at']) ? CarbonImmutable::parse($data['followed_at']) : CarbonImmutable::now(),
                            'notification_level' => $data['notification_level'] ?? 'all',
                            'source' => $data['source'] ?? 'restore',
                        ]
                    );
                }
            });
        }

        OwnerContext::withOwner(null, function (): void {});

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
     * @param  array<string, mixed>  $snapshot
     */
    protected function restoreReassignedRelations(array $snapshot): void
    {
        $this->restoreEventOwnership($this->snapshotIds($snapshot, 'owned_event_ids'));
        $this->restoreForeignKeyRelation('eventSubmissions', 'submitter_id', $this->snapshotIds($snapshot, 'event_submission_ids'));
        $this->restoreForeignKeyRelation('contributionRequests', 'proposer_id', $this->snapshotIds($snapshot, 'contribution_request_proposer_ids'));
        $this->restoreForeignKeyRelation('reviewedContributionRequests', 'reviewer_id', $this->snapshotIds($snapshot, 'contribution_request_reviewer_ids'));
        $this->restoreForeignKeyRelation('membershipApplications', 'applicant_id', $this->snapshotIds($snapshot, 'membership_application_ids'));
        $this->restoreForeignKeyRelation('reviewedMembershipApplications', 'reviewer_id', $this->snapshotIds($snapshot, 'membership_application_reviewer_ids'));
        $this->restoreForeignKeyRelation('moderationReviews', 'actioned_by_id', $this->snapshotIds($snapshot, 'moderation_review_ids'));
        $this->restoreForeignKeyRelation('reports', 'reporter_id', $this->snapshotIds($snapshot, 'report_ids'));
        $this->restoreForeignKeyRelation('handledReports', 'handled_by', $this->snapshotIds($snapshot, 'handled_report_ids'));
        $this->restoreForeignKeyRelation('verifiedDonationChannels', 'verified_by', $this->snapshotIds($snapshot, 'verified_donation_channel_ids'));
        $this->restoreForeignKeyRelation('verifiedEventCheckins', 'verified_by_user_id', $this->snapshotIds($snapshot, 'verified_event_checkin_ids'));
        $this->restoreForeignKeyRelation('verifiedPersons', 'verified_by', $this->snapshotIds($snapshot, 'verified_person_ids'));
        $this->restoreForeignKeyRelation('verifiedInstitutions', 'verified_by', $this->snapshotIds($snapshot, 'verified_institution_ids'));
        $this->restoreForeignKeyRelation('verifiedReferences', 'verified_by', $this->snapshotIds($snapshot, 'verified_reference_ids'));
        $this->restoreForeignKeyRelation('verifiedVenues', 'verified_by', $this->snapshotIds($snapshot, 'verified_venue_ids'));
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
        $this->restoreChildModels('notificationDestinations', $this->snapshotRows($snapshot, 'notification_destinations'));
        $this->restoreChildModels('notificationInboxes', $this->snapshotRows($snapshot, 'notification_inboxes'));
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

            if ($model instanceof SocialAccount) {
                $existing = SocialAccount::query()->find($model->getKey());

                if ($existing instanceof SocialAccount
                    && ($existing->user_id === null || (string) $existing->user_id === (string) $this->getKey())) {
                    $existing->delete();
                }
            }

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

    private function clearEventOwnership(): void
    {
        OwnerContext::withOwner($this, function (): void {
            Event::query()
                ->where('owner_type', $this->getMorphClass())
                ->where('owner_id', $this->id)
                ->get()
                ->each(function (Event $event): void {
                    $event->owner_type = null;
                    $event->owner_id = null;
                    $event->saveQuietly();
                });
        });
    }

    /** @param array<int, string> $ids */
    private function restoreEventOwnership(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        OwnerContext::withOwner($this, function () use ($ids): void {
            Event::query()
                ->whereKey($ids)
                ->get()
                ->each(function (Event $event): void {
                    if ($event->owner_id !== null) {
                        return;
                    }

                    $event->owner_type = $this->getMorphClass();
                    $event->owner_id = $this->id;
                    $event->saveQuietly();
                });
        });
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
}
