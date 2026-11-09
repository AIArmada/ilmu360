<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use App\Shared\Auth\AccountDirectory;
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
        return $this->withGlobalRoles(['super_admin', 'admin']);
    }

    public function admins(): array
    {
        return $this->withGlobalRoles(['super_admin', 'admin', 'moderator']);
    }

    public function exists(string $accountId): bool
    {
        return User::query()->whereKey($accountId)->exists();
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
}
