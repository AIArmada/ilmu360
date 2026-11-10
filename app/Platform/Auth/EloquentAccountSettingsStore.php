<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use App\Models\User;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountProfile;
use App\Shared\Auth\AccountProfileUpdate;
use App\Shared\Auth\AccountSettingsStore;
use App\Shared\Auth\AccountToken;
use DateTimeImmutable;
use DateTimeInterface;
use Laravel\Sanctum\PersonalAccessToken;

final readonly class EloquentAccountSettingsStore implements AccountSettingsStore
{
    public function __construct(private AccountDirectory $accounts) {}

    public function updateProfile(string $accountId, AccountProfileUpdate $update): void
    {
        $user = $this->user($accountId);
        $emailChanged = $update->email !== $this->nullableString($user->getAttribute('email'));
        $phoneChanged = $update->phone !== $this->nullableString($user->getAttribute('phone'));

        $attributes = [
            'name' => $update->name,
            'email' => $update->email,
            'phone' => $update->phone,
            'timezone' => $update->timezone,
            'email_verified_at' => $emailChanged ? null : $user->getAttribute('email_verified_at'),
            'phone_verified_at' => $phoneChanged ? null : $user->getAttribute('phone_verified_at'),
        ];

        if ($update->genderProvided) {
            $attributes['gender'] = $update->gender;
        }

        if ($update->dateOfBirthProvided) {
            $attributes['date_of_birth'] = $update->dateOfBirth;
        }

        if ($update->password !== null) {
            $attributes['password'] = $update->password;
        }

        $user->forceFill($attributes)->save();
    }

    public function updateAdministrative(string $accountId, array $attributes): void
    {
        $this->user($accountId)->forceFill($attributes)->save();
    }

    public function createAdministrative(array $attributes): string
    {
        $user = new User;
        $user->forceFill($attributes)->save();

        return (string) $user->getKey();
    }

    public function delete(string $accountId): void
    {
        $this->user($accountId)->delete();
    }

    public function restore(string $deletedKey): AccountProfile
    {
        $user = User::restoreDeletedUser($deletedKey);

        return $this->accounts->profile((string) $user->getKey())
            ?? throw new \RuntimeException('The restored account could not be loaded.');
    }

    public function tokens(string $accountId): array
    {
        return $this->user($accountId)
            ->tokens()
            ->latest('created_at')
            ->get(['id', 'name', 'created_at', 'last_used_at'])
            ->map(fn (PersonalAccessToken $token): AccountToken => new AccountToken(
                id: $token->getKey(),
                name: (string) $token->name,
                createdAt: $this->immutable($token->created_at),
                lastUsedAt: $this->immutable($token->last_used_at),
            ))
            ->all();
    }

    public function createToken(string $accountId, string $name): string
    {
        return $this->user($accountId)->createToken($name)->plainTextToken;
    }

    public function revokeToken(string $accountId, int|string $tokenId): bool
    {
        return $this->user($accountId)->tokens()->whereKey($tokenId)->delete() > 0;
    }

    private function user(string $accountId): User
    {
        return User::query()->findOrFail($accountId);
    }

    private function immutable(mixed $value): ?DateTimeImmutable
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($value);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
