<?php

namespace App\Models;

use AIArmada\Membership\Models\MembershipInvitation as PackageMembershipInvitation;
use App\Enums\MemberSubjectType;
use App\Models\Concerns\AuditsModelChanges;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property MemberSubjectType|null $subject_type
 * @property string|null $role_slug
 * @property string|null $subject_id
 * @property string|null $email
 * @property string|null $token
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
class MemberInvitation extends PackageMembershipInvitation implements AuditableContract
{
    use AuditsModelChanges;

    protected static string $ownerScopeConfigKey = '';

    protected static bool $ownerScopeEnabledByDefault = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subject_type',
        'subject_id',
        'email',
        'role',
        'role_slug',
        'token',
        'invited_by',
        'expires_at',
        'accepted_at',
        'accepted_by',
        'revoked_at',
        'revoked_by',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'subject_type' => MemberSubjectType::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    #[\Override]
    public function setAttribute($key, $value): mixed
    {
        return match ($key) {
            'role_slug' => parent::setAttribute('role', $value),
            default => parent::setAttribute($key, $value),
        };
    }

    #[\Override]
    public function getAttribute($key): mixed
    {
        return match ($key) {
            'role_slug' => parent::getAttribute('role'),
            default => parent::getAttribute($key),
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof CarbonInterface && $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    #[\Override]
    public function matchesToken(string $token): bool
    {
        $storedToken = (string) $this->getRawOriginal('token', $this->token);

        return hash_equals($storedToken, static::tokenForStorage($token))
            || hash_equals($storedToken, $token);
    }
}
