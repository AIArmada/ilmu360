<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Account;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Events\Support\EventModel;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

final class RestoreEventAccountOwnershipAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function handle(string $accountId, array $snapshot): void
    {
        $user = EventModel::query('user')->find($accountId);

        if (! $user instanceof Model) {
            return;
        }

        $userType = $user->getMorphClass();
        $eventIds = $this->stringIds($snapshot['owned_event_ids'] ?? []);
        $submissionIds = $this->stringIds($snapshot['event_submission_ids'] ?? []);
        $eventClass = EventModel::event();

        if ($eventIds !== []) {
            OwnerContext::withOwner($user, function () use ($eventClass, $eventIds, $user, $userType): void {
                $eventClass::query()
                    ->whereKey($eventIds)
                    ->whereNull('owner_id')
                    ->get()
                    ->each(function (Model $event) use ($user, $userType): void {
                        $event->owner_type = $userType;
                        $event->owner_id = $user->getKey();
                        $event->saveQuietly();
                    });
            });
        }

        if ($submissionIds !== []) {
            EventModel::query('event_submission')
                ->whereKey($submissionIds)
                ->whereNull('submitter_id')
                ->update([
                    'submitter_type' => $userType,
                    'submitter_id' => $accountId,
                ]);
        }
    }

    /**
     * @return list<string>
     */
    private function stringIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(static fn (mixed $id): bool => is_string($id) || is_int($id))
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
