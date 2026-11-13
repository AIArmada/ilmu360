<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationPreference;
use App\Shared\Auth\UserMorphType;
use Lorisleiva\Actions\Concerns\AsAction;

final class UpdateNotificationLocaleAction
{
    use AsAction;

    public function __construct(
        private readonly UserMorphType $userMorphType,
    ) {}

    public function handle(string $accountId, string $locale): void
    {
        CommunicationPreference::query()
            ->where('recipient_type', $this->userMorphType->morphClass())
            ->where('recipient_id', $accountId)
            ->whereNull('channel')
            ->whereNull('category')
            ->whereNull('scope_type')
            ->update(['locale' => $locale]);
    }
}
