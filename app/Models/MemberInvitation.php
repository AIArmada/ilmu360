<?php

namespace App\Models;

use AIArmada\Membership\Enums\InvitationStatus;
use AIArmada\Membership\Models\MembershipInvitation as PackageMembershipInvitation;
use App\Enums\MemberSubjectType;
use App\Models\Concerns\AuditsModelChanges;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property MemberSubjectType|null $subject_type
 * @property string|null $role
 * @property string|null $subject_id
 * @property string|null $email
 * @property string|null $token
 * @property InvitationStatus $status
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $expired_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $last_state_change_at
 */
class MemberInvitation extends PackageMembershipInvitation implements Auditable
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
        'invited_by',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'subject_type' => MemberSubjectType::class,
            'status' => InvitationStatus::class,
            'expires_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_state_change_at' => 'immutable_datetime',
        ];
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
}
