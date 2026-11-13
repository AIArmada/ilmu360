<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions\Inbox;

use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\UserMorphType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveNotificationInboxRecipientAction
{
    use AsAction;

    public function __construct(
        private readonly AccountDirectory $accounts,
        private readonly UserMorphType $userMorphType,
    ) {}

    public function handle(string $accountId): Model
    {
        $recipient = $this->accounts->resolveNotifiable($accountId);

        if (! $recipient instanceof Model || $recipient->getMorphClass() !== $this->userMorphType->morphClass()) {
            throw (new ModelNotFoundException)->setModel(Model::class, [$accountId]);
        }

        return $recipient;
    }
}
