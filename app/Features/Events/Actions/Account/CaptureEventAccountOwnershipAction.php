<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Account;

use App\Features\Events\Support\EventModel;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

final class CaptureEventAccountOwnershipAction
{
    use AsAction;

    /**
     * @return array{owned_event_ids: list<string>, event_submission_ids: list<string>}
     */
    public function handle(string $accountId): array
    {
        $user = EventModel::query('user')->find($accountId);

        if (! $user instanceof Model) {
            return ['owned_event_ids' => [], 'event_submission_ids' => []];
        }

        $userType = $user->getMorphClass();

        return [
            'owned_event_ids' => EventModel::query('event')
                ->where('owner_type', $userType)
                ->where('owner_id', $accountId)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all(),
            'event_submission_ids' => EventModel::query('event_submission')
                ->where('submitter_type', $userType)
                ->where('submitter_id', $accountId)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all(),
        ];
    }
}
