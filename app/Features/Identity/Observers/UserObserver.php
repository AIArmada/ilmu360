<?php

declare(strict_types=1);

namespace App\Features\Identity\Observers;

use App\Features\Identity\Actions\SyncAccountVerificationEffectsAction;
use App\Models\User;

/** Eloquent notification adapter; the Identity Action owns verification effects. */
final readonly class UserObserver
{
    public function __construct(private SyncAccountVerificationEffectsAction $effects) {}

    public function updated(User $user): void
    {
        $this->effects->handle($user);
    }
}
