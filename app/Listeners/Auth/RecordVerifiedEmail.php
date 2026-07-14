<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Models\User;
use App\Services\Signals\ProductSignalsService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class RecordVerifiedEmail implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private ProductSignalsService $productSignalsService,
    ) {}

    public function handle(Verified $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->productSignalsService->recordEmailVerified($event->user, request());
    }
}
