<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationDestination;
use App\Features\Communications\Api\NotificationChannel;
use App\Shared\Auth\UserMorphType;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class ResolvePushDestinationsAction
{
    use AsAction;

    public function __construct(
        private UserMorphType $userMorphType,
    ) {}

    /**
     * @return Collection<int, CommunicationDestination>
     */
    public function handle(string $accountId, bool $orderByLastSeen): Collection
    {
        $query = CommunicationDestination::query()
            ->where('recipient_type', $this->userMorphType->morphClass())
            ->where('recipient_id', $accountId)
            ->where('channel', NotificationChannel::Push->value)
            ->where('status', 'active')
            ->orderByDesc('is_primary');

        if ($orderByLastSeen) {
            $query->orderByDesc('last_seen_at');
        }

        return $query->get();
    }
}
