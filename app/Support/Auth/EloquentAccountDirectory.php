<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountProfile;
use App\Shared\Auth\GlobalRole;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

final class EloquentAccountDirectory implements AccountDirectory
{
    public function resolveNotifiable(string $accountId): ?Authenticatable
    {
        return User::query()->whereKey($accountId)->first();
    }

    public function maintainers(): array
    {
        return $this->withGlobalRoles([GlobalRole::SUPER_ADMIN, GlobalRole::ADMIN]);
    }

    public function admins(): array
    {
        return $this->withGlobalRoles([GlobalRole::SUPER_ADMIN, GlobalRole::ADMIN, GlobalRole::MODERATOR]);
    }

    public function exists(string $accountId): bool
    {
        return User::query()->whereKey($accountId)->exists();
    }

    public function profile(string $accountId): ?AccountProfile
    {
        $user = User::query()->whereKey($accountId)->first();

        if (! $user instanceof User) {
            return null;
        }

        $createdAt = $user->getAttribute('created_at');

        return new AccountProfile(
            id: (string) $user->getKey(),
            name: (string) $user->getAttribute('name'),
            email: $this->nullableString($user->getAttribute('email')),
            phone: $this->nullableString($user->getAttribute('phone')),
            emailVerified: $user->getAttribute('email_verified_at') !== null,
            phoneVerified: $user->getAttribute('phone_verified_at') !== null,
            locale: $user->preferredLocale(),
            timezone: $user->preferredTimezone(),
            createdAt: $createdAt instanceof DateTimeInterface
                ? CarbonImmutable::instance($createdAt)
                : CarbonImmutable::now(),
        );
    }

    public function accountType(string $accountId): ?string
    {
        return $this->exists($accountId) ? (new User)->getMorphClass() : null;
    }

    public function lockForUpdate(string $accountId): void
    {
        User::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<string>  $roles
     * @return list<Authenticatable>
     */
    private function withGlobalRoles(array $roles): array
    {
        /** @var list<User> $accounts */
        $accounts = array_values(
            User::query()
                ->whereHas('globalRoles', static function (Builder $query) use ($roles): void {
                    $query->whereIn('name', $roles);
                })
                ->get()
                ->all(),
        );

        return $accounts;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
