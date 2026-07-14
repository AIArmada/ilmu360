<?php

namespace App\Support\Authz;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class MemberPermissionGate
{
    private const array ROLE_WEIGHT = [
        'owner' => 100,
        'admin' => 80,
        'editor' => 50,
        'viewer' => 10,
    ];

    private const array PERMISSION_THRESHOLD = [
        'view' => 10,
        'update' => 80,
        'delete' => 100,
        'manage-members' => 80,
        'approve' => 80,
        'manage-donation-channels' => 80,
    ];

    public function canInstitution(User $user, string $permission, Institution $institution): bool
    {
        return $this->memberCan($institution, $user, $permission);
    }

    public function canSpeaker(User $user, string $permission, Speaker $speaker): bool
    {
        return $this->memberCan($speaker, $user, $permission);
    }

    public function canEvent(User $user, string $permission, Event $event): bool
    {
        return $this->memberCan($event, $user, $permission);
    }

    public function canReference(User $user, string $permission, Reference $reference): bool
    {
        return $this->memberCan($reference, $user, $permission);
    }

    public function hasAnyInstitutionPermission(User $user, string $permission): bool
    {
        $shortName = $this->shortPermissionName($permission);

        return $user->institutions()->get()->contains(
            fn (Institution $i): bool => $this->memberCan($i, $user, $shortName),
        );
    }

    public function hasAnyEventPermission(User $user, string $permission): bool
    {
        $shortName = $this->shortPermissionName($permission);

        return $user->memberEvents()->get()->contains(
            fn (Event $e): bool => $this->memberCan($e, $user, $shortName),
        );
    }

    public function hasAnySpeakerPermission(User $user, string $permission): bool
    {
        $shortName = $this->shortPermissionName($permission);

        return $user->speakers()->get()->contains(
            fn (Speaker $s): bool => $this->memberCan($s, $user, $shortName),
        );
    }

    public function hasAnyReferencePermission(User $user, string $permission): bool
    {
        $shortName = $this->shortPermissionName($permission);

        return $user->references()->get()->contains(
            fn (Reference $r): bool => $this->memberCan($r, $user, $shortName),
        );
    }

    /**
     * @return Collection<int, User>
     */
    public function institutionMembersWithPermission(Institution $institution, string $permission): Collection
    {
        return $this->membersWithPermission($institution, $permission);
    }

    /**
     * @return Collection<int, User>
     */
    public function speakerMembersWithPermission(Speaker $speaker, string $permission): Collection
    {
        return $this->membersWithPermission($speaker, $permission);
    }

    /**
     * @return Collection<int, User>
     */
    public function eventMembersWithPermission(Event $event, string $permission): Collection
    {
        return $this->membersWithPermission($event, $permission);
    }

    private function memberCan(Model $subject, User $user, string $permission): bool
    {
        if (! method_exists($subject, 'members')) {
            return false;
        }

        $shortName = $this->shortPermissionName($permission);
        $threshold = self::PERMISSION_THRESHOLD[$shortName] ?? null;

        if ($threshold === null) {
            return false;
        }

        $role = $subject->members()->whereKey($user->getKey())->value('role');
        $weight = self::ROLE_WEIGHT[$role] ?? 0;

        return $weight >= $threshold;
    }

    /**
     * @return Collection<int, User>
     */
    private function membersWithPermission(Model $subject, string $permission): Collection
    {
        $shortName = $this->shortPermissionName($permission);
        $threshold = self::PERMISSION_THRESHOLD[$shortName] ?? null;

        if ($threshold === null) {
            return collect();
        }

        if (! method_exists($subject, 'members')) {
            return collect();
        }

        /** @var Collection<int, User> $members */
        $members = $subject->members()->get();

        return $members->filter(
            fn (User $member): bool => (self::ROLE_WEIGHT[$member->pivot->role ?? ''] ?? 0) >= $threshold,
        )->values();
    }

    private function shortPermissionName(string $permission): string
    {
        $parts = explode('.', $permission);

        return end($parts);
    }
}
