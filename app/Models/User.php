<?php

namespace App\Models;

use AIArmada\CommerceSupport\Models\Permission;
use AIArmada\CommerceSupport\Models\Role;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Models\CommunicationDestination;
use AIArmada\Communications\Models\CommunicationPreference;
use AIArmada\Communications\Traits\HasInbox;
use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Engagement\Traits\CanBookmark;
use AIArmada\Engagement\Traits\CanFollow;
use AIArmada\Engagement\Traits\CanRespond;
use AIArmada\FilamentAuthz\Facades\Authz;
use App\Enums\NotificationChannel;
use App\Models\Concerns\AuditsModelChanges;
use App\Models\Concerns\HasUserRestoration;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Support\Submission\PublicSubmissionLockService;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\DeletedModels\Models\Concerns\KeepsDeletedModels;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements AuditableContract, FilamentUser, HasLocalePreference, MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use AuditsModelChanges, CanBookmark, CanRespond, HasApiTokens, HasFactory, HasRoles, HasUserRestoration, HasUuids, KeepsDeletedModels, MustVerifyEmail {
        HasUserRestoration::attributesToKeep insteadof KeepsDeletedModels;
        HasUserRestoration::afterRestoringModel insteadof KeepsDeletedModels;
        KeepsDeletedModels::attributesToKeep as protected deletedModelsAttributesToKeep;
    }

    use CanFollow {
        CanFollow::follow as traitFollow;
        CanFollow::unfollow as traitUnfollow;
        CanFollow::isFollowing as traitIsFollowing;
    }

    /**
     * Package inbox + Laravel Notifiable both define unreadNotifications().
     * Prefer package NotificationInbox semantics; expose database notifications under a distinct name.
     */
    use HasInbox, Notifiable {
        HasInbox::unreadNotifications insteadof Notifiable;
        Notifiable::unreadNotifications as unreadDatabaseNotifications;
    }

    public $incrementing = false;

    protected $keyType = 'string';

    #[\Override]
    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if (! $user->wasChanged('phone_verified_at')) {
                return;
            }

            app(PublicSubmissionLockService::class)->syncForUser($user);
        });
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
        return $this->belongsToMany(Institution::class, 'institution_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Person, $this>
     */
    public function persons(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'person_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Person, $this>
     */
    public function speakers(): BelongsToMany
    {
        return $this->persons();
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
        return $this->belongsToMany(Reference::class, 'reference_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return MorphMany<Follow, $this>
     */
    public function venues(): MorphMany
    {
        return $this->follows()->where('followable_type', Venue::class);
    }

    /**
     * @return HasManyThrough<Event, EventSubmission, $this>
     */
    public function submittedEvents(): HasManyThrough
    {
        return $this->hasManyThrough(Event::class, EventSubmission::class, 'submitter_id', 'id', 'id', 'event_id')
            ->where((new EventSubmission)->getTable().'.submitter_type', $this->getMorphClass())
            ->select((new Event)->getTable().'.*');
    }

    /**
     * @return HasMany<EventSubmission, $this>
     */
    public function eventSubmissions(): HasMany
    {
        return $this->hasMany(EventSubmission::class, 'submitter_id')
            ->where('submitter_type', $this->getMorphClass());
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
     * @return HasMany<MembershipApplication, $this>
     */
    public function membershipApplications(): HasMany
    {
        return $this->hasMany(MembershipApplication::class, 'applicant_id');
    }

    /**
     * @return HasMany<MembershipApplication, $this>
     */
    public function reviewedMembershipApplications(): HasMany
    {
        return $this->hasMany(MembershipApplication::class, 'reviewer_id');
    }

    /**
     * @return HasMany<ModerationReview, $this>
     */
    public function moderationReviews(): HasMany
    {
        return $this->hasMany(ModerationReview::class, 'actioned_by_id');
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
     * @return MorphMany<Registration, $this>
     */
    public function registrations(): MorphMany
    {
        return $this->morphMany(Registration::class, 'registrant');
    }

    /**
     * @return HasMany<EventCheckin, $this>
     */
    public function eventCheckins(): HasMany
    {
        return $this->hasMany(EventCheckin::class, 'attendee_id');
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
     * @return MorphMany<SavedSearch, $this>
     */
    public function savedSearches(): MorphMany
    {
        return $this->morphMany(SavedSearch::class, 'user');
    }

    /**
     * @return HasMany<AiUsageLog, $this>
     */
    public function aiUsageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
    }

    /**
     * @return HasMany<Bookmark, $this>
     */
    public function eventBookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class, 'bookmarker_id')
            ->where('bookmarker_type', $this->getMorphClass())
            ->active()
            ->where('bookmarkable_type', (new Event)->getMorphClass());
    }

    /**
     * @return Collection<int, string>
     */
    public function savedEventIds(): Collection
    {
        return $this->eventBookmarks()->pluck('bookmarkable_id');
    }

    /**
     * @return Builder<Event>
     */
    public function savedEvents(): Builder
    {
        return Event::query()->whereIn('id', $this->savedEventIds());
    }

    /**
     * @return Collection<int, Event>
     */
    public function getSavedEventsAttribute(): Collection
    {
        return $this->savedEvents()->get();
    }

    /**
     * @param  list<string>  $eventIds
     */
    /**
     * @return MorphToMany<Person, $this>
     */
    public function followingPersons(): MorphToMany
    {
        $table = (new Follow)->getTable();

        return $this->morphedByMany(Person::class, 'followable', $table, 'follower_id', 'followable_id')
            ->where("{$table}.status", 'active');
    }

    /**
     * @return MorphToMany<Person, $this>
     */
    public function followingSpeakers(): MorphToMany
    {
        return $this->followingPersons();
    }

    /**
     * @return MorphToMany<Institution, $this>
     */
    public function followingInstitutions(): MorphToMany
    {
        $table = (new Follow)->getTable();

        return $this->morphedByMany(Institution::class, 'followable', $table, 'follower_id', 'followable_id')
            ->where("{$table}.status", 'active');
    }

    /**
     * @return MorphToMany<Reference, $this>
     */
    public function followingReferences(): MorphToMany
    {
        $table = (new Follow)->getTable();

        return $this->morphedByMany(Reference::class, 'followable', $table, 'follower_id', 'followable_id')
            ->where("{$table}.status", 'active');
    }

    /**
     * @return MorphToMany<Series, $this>
     */
    public function followingSeries(): MorphToMany
    {
        $table = (new Follow)->getTable();

        return $this->morphedByMany(Series::class, 'followable', $table, 'follower_id', 'followable_id')
            ->where("{$table}.status", 'active');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function follow(mixed $subject, array $options = []): Follow
    {
        return OwnerContext::withOwner(null, fn (): Follow => $this->traitFollow($subject, $options));
    }

    public function unfollow(mixed $subject): void
    {
        OwnerContext::withOwner(null, fn () => $this->traitUnfollow($subject));
    }

    public function isFollowing(mixed $subject): bool
    {
        return OwnerContext::withOwner(null, fn (): bool => $this->traitIsFollowing($subject));
    }

    /**
     * @return BelongsToMany<Event, $this>
     */
    public function goingEvents(): BelongsToMany
    {
        $responsesTable = config('engagement.database.tables.responses', 'responses');

        return $this->belongsToMany(
            Event::class,
            $responsesTable,
            'responder_id',
            'respondable_id',
            'id',
            'id',
        )
            ->where("{$responsesTable}.responder_type", $this->getMorphClass())
            ->where("{$responsesTable}.respondable_type", (new Event)->getMorphClass())
            ->where("{$responsesTable}.response_type", 'going')
            ->where("{$responsesTable}.status", 'active');
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function ownedEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'owner_id')->where('owner_type', $this->getMorphClass());
    }

    /**
     * @return HasMany<DonationChannel, $this>
     */
    public function verifiedDonationChannels(): HasMany
    {
        return $this->hasMany(DonationChannel::class, 'verified_by');
    }

    /**
     * @return HasMany<Person, $this>
     */
    public function verifiedPersons(): HasMany
    {
        return $this->hasMany(Person::class, 'verified_by');
    }

    /**
     * @return HasMany<Institution, $this>
     */
    public function verifiedInstitutions(): HasMany
    {
        return $this->hasMany(Institution::class, 'verified_by');
    }

    /**
     * @return HasMany<Reference, $this>
     */
    public function verifiedReferences(): HasMany
    {
        return $this->hasMany(Reference::class, 'verified_by');
    }

    /**
     * @return HasMany<Venue, $this>
     */
    public function verifiedVenues(): HasMany
    {
        return $this->hasMany(Venue::class, 'verified_by');
    }

    /**
     * @return BelongsToMany<Event, $this>
     */
    public function memberEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return MorphOne<CommunicationPreference, $this>
     */
    public function notificationSetting(): MorphOne
    {
        return $this->morphOne(CommunicationPreference::class, 'recipient')
            ->whereNull('channel')
            ->whereNull('category')
            ->whereNull('scope_type');
    }

    /**
     * @return MorphMany<CommunicationDestination, $this>
     */
    public function notificationDestinations(): MorphMany
    {
        return $this->morphMany(CommunicationDestination::class, 'recipient');
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
    public function routeNotificationForMail(?Notification $notification = null): array|string|null
    {
        return $this->email;
    }

    /**
     * @return Collection<int, CommunicationDestination>
     */
    public function routeNotificationForPush(?Notification $notification = null): Collection
    {
        return $this->notificationDestinations()
            ->where('channel', NotificationChannel::Push->value)
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->get();
    }

    /**
     * @return Collection<int, CommunicationDestination>
     */
    public function routeNotificationForWhatsapp(?Notification $notification = null): Collection
    {
        return $this->notificationDestinations()
            ->where('channel', NotificationChannel::Whatsapp->value)
            ->where('status', 'active')
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
            || $this->persons()->exists()
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
