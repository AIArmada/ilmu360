<?php

declare(strict_types=1);

namespace App\Features\Persons\Actions;

use App\Features\Persons\Models\Person;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class PreparePersonSaveAction
{
    public function handle(Person $person): void
    {
        if ($person->isDirty('status')) {
            $now = now();
            $person->last_state_change_at = $now;

            match ($person->statusValue()) {
                'verified' => $person->verified_at ??= $now,
                'rejected' => $person->rejected_at ??= $now,
                'inactive' => $person->published_at ??= $now,
                default => null,
            };

            if ($person->statusValue() === 'verified') {
                $person->verified_by ??= auth()->id();
            }
        }

        if ((($person->exists && ($person->isDirty('is_featured') || $person->isDirty('order_column')))
            || (! $person->exists && ($person->is_featured || $person->order_column !== null)))
            && auth()->user() instanceof User
            && ! auth()->user()->hasGlobalAdminAccess()) {
            throw new AuthorizationException('Only global administrators may curate featured people.');
        }

        if ($person->is_featured
            && ($person->order_column === null || ($person->exists && $person->isDirty('is_featured')))) {
            $person->setHighestOrderNumber();
        }
    }
}
