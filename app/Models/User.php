<?php

namespace App\Models;

use AIArmada\Authz\Facades\Authz;
use AIArmada\Authz\Models\Permission;
use AIArmada\Authz\Models\Role;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Contracts\CanInteract;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Engagement\Traits\CanBookmark;
use AIArmada\Engagement\Traits\CanFollow;
use AIArmada\Engagement\Traits\CanRespond;
use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Features\Communications\Api\PushDestinationManagement;
use App\Features\Communications\Api\PushDestinationSnapshot;
use App\Models\Concerns\HasUserRestoration;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\Channels\DigestMailChannel;
use App\Notifications\Channels\InboxChannel;
use App\Platform\Auditing\AuditsModelChanges;
use App\Shared\Auth\AccountActor;
use App\Shared\Auth\IntegrationFeedbackIdentity;
use App\Shared\Auth\IntegrationFeedbackUser;
use App\Shared\Auth\ResourceOwner;
use App\Shared\Feedback\FeedbackActor;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\DeletedModels\Models\Concerns\KeepsDeletedModels;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements AccountActor, AuditableContract, CanInteract, FeedbackActor, FilamentUser, HasLocalePreference, IntegrationFeedbackUser, MustVerifyEmailContract, ResourceOwner
{
    public function actorId(): string
    {
        return (string) $this->getKey();
    }

    public function resourceOwnerType(): string
    {
        return $this->getMorphClass();
    }

    public function resourceOwnerId(): string
    {
        return (string) $this->getKey();
    }

    /** @use HasFactory<UserFactory> */
    use AuditsModelChanges, CanBookmark, CanRespond, HasApiTokens, HasFactory, HasRoles, HasUserRestoration, HasUuids, KeepsDeletedModels, MustVerifyEmail, TwoFactorAuthenticatable {
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
     * Keep Laravel's notification routing while the account inbox is served by
     * the communications boundary rather than a User relation.
     */
    use Notifiable {
        Notifiable::routeNotificationFor as traitRouteNotificationFor;
    }

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    #[\Override]
    protected $fillable = [
        'name',
        'email',
        'phone',
        'gender',
        'date_of_birth',
        'timezone',
        'current_team_id',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    #[\Override]
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
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
            'email_verified_at' => 'immutable_datetime',
            'phone_verified_at' => 'immutable_datetime',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'date_of_birth' => 'date',
            'password' => 'hashed',
        ];
    }

    public function canSubmitDirectoryFeedback(): bool
    {
        return ! $this->isDirectoryFeedbackBlocked();
    }

    public function canSubmitAppFeedback(): bool
    {
        return ! $this->isDirectoryFeedbackBlocked();
    }

    public function integrationFeedbackIdentity(): IntegrationFeedbackIdentity
    {
        return new IntegrationFeedbackIdentity(
            id: (string) $this->getKey(),
            name: (string) $this->name,
            isAdmin: $this->hasApplicationAdminAccess(),
            hasMemberMcpAccess: $this->hasMemberMcpAccess(),
        );
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
        if (! $subject instanceof Model) {
            return false;
        }

        return OwnerContext::withOwner(null, fn (): bool => $this->traitIsFollowing($subject));
    }

    public function interactionDisplayName(): string
    {
        return (string) ($this->name ?? $this->email);
    }

    public function interactionNotificationRoute(?string $channel = null): mixed
    {
        return $channel === null ? $this->email : $this->routeNotificationFor($channel);
    }

    /**
     * Route inbox-channel notifications to this user.
     *
     * The communications dispatcher only plans deliveries (and runs the send
     * job) for channels with a resolvable destination. Inbox delivery targets
     * the user itself, so the user key acts as the destination address.
     */
    /**
     * @param  mixed  $driver
     * @param  mixed  $notification
     * @return mixed
     */
    public function routeNotificationFor($driver, $notification = null)
    {
        if ($driver === InboxChannel::class) {
            return (string) $this->getKey();
        }

        if ($driver === DigestMailChannel::class) {
            return $this->routeNotificationForMail($notification instanceof Notification ? $notification : null);
        }

        return $this->traitRouteNotificationFor($driver, $notification);
    }

    public function preferredLocale(): string
    {
        return app(AccountNotificationPreferences::class)->preferredLocale((string) $this->getKey());
    }

    public function preferredTimezone(): string
    {
        // Auth guards may hand over partially loaded instances; a missing
        // key falls back instead of fataling under strict attributes.
        $stored = $this->attributes['timezone'] ?? null;

        return app(AccountNotificationPreferences::class)->preferredTimezone(
            (string) $this->getKey(),
            is_string($stored) ? $stored : null,
        );
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

    /** @return list<PushDestinationSnapshot> */
    public function routeNotificationForPush(?Notification $notification = null): array
    {
        return app(PushDestinationManagement::class)->forNotification((string) $this->getKey());
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'ahli') {
            return $this->hasAhliPanelAccess();
        }

        if ($panel->getId() === 'admin') {
            return $this->hasApplicationAdminAccess();
        }

        return false;
    }

    public function hasAhliPanelAccess(): bool
    {
        $accountId = (string) $this->getKey();
        $memberships = app(MembershipDirectory::class);

        return array_any([
            MembershipSubjectType::Institution,
            MembershipSubjectType::Person,
            MembershipSubjectType::Reference,
            MembershipSubjectType::Event,
        ], fn ($subjectType) => $memberships->subjectIdsFor($accountId, $subjectType) !== []);
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
