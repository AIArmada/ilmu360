<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use App\Features\Communications\Support\Recipient\NotificationRecipientSnapshot;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\UserMorphType;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveNotificationAccountAction
{
    use AsAction;

    public function __construct(
        private readonly AccountDirectory $accounts,
        private readonly UserMorphType $userMorphType,
    ) {}

    public function handle(string $accountId): NotificationRecipientSnapshot
    {
        $account = $this->accounts->resolveNotifiable($accountId);

        if (! $account instanceof Model) {
            throw (new ModelNotFoundException)->setModel(Model::class, [$accountId]);
        }

        $attributes = $account->getAttributes();
        $requiredAttributes = ['email', 'email_verified_at', 'timezone'];

        if (array_diff($requiredAttributes, array_keys($attributes)) !== []) {
            $freshAccount = $account->newQuery()
                ->select([$account->getKeyName(), ...$requiredAttributes])
                ->whereKey($account->getKey())
                ->first();
            $attributes = $freshAccount?->getAttributes() ?? $attributes;
        }

        $verifiedAt = $attributes['email_verified_at'] ?? null;

        return new NotificationRecipientSnapshot(
            id: (string) $account->getKey(),
            recipientType: $this->userMorphType->morphClass(),
            email: $this->nullableString($attributes['email'] ?? null),
            emailVerifiedAt: $verifiedAt instanceof DateTimeInterface
                ? $verifiedAt->format('Y-m-d H:i:s')
                : $this->nullableString($verifiedAt),
            timezone: $this->nullableString($attributes['timezone'] ?? null),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
