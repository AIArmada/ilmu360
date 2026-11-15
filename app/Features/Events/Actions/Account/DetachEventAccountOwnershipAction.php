<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Account;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Events\Support\EventModel;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

final class DetachEventAccountOwnershipAction
{
    use AsAction;

    public function handle(string $accountId): void
    {
        $user = EventModel::query('user')->find($accountId);

        if (! $user instanceof Model) {
            return;
        }

        $userType = $user->getMorphClass();
        $eventClass = EventModel::event();

        OwnerContext::withOwner($user, function () use ($eventClass, $userType, $accountId): void {
            $eventClass::query()
                ->where('owner_type', $userType)
                ->where('owner_id', $accountId)
                ->get()
                ->each(function (Model $event): void {
                    $event->owner_type = null;
                    $event->owner_id = null;
                    $event->saveQuietly();
                });
        });

        EventModel::query('event_submission')
            ->where('submitter_type', $userType)
            ->where('submitter_id', $accountId)
            ->update(['submitter_type' => null, 'submitter_id' => null]);
    }
}
