<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Services;

use App\Features\SlugRedirects\Actions\MarkSlugRedirectUsedAction;
use App\Features\SlugRedirects\Actions\PurgeSlugRedirectsAction;
use App\Features\SlugRedirects\Actions\RecordSlugRedirectAction;
use App\Features\SlugRedirects\Api\SlugRedirectCommands;

final readonly class SlugRedirectCommandsService implements SlugRedirectCommands
{
    public function __construct(
        private RecordSlugRedirectAction $recordSlugRedirectAction,
        private PurgeSlugRedirectsAction $purgeSlugRedirectsAction,
        private MarkSlugRedirectUsedAction $markSlugRedirectUsedAction,
    ) {}

    public function record(string $redirectableType, string $redirectableId, string $currentSlug, ?string $previousSlug): bool
    {
        return $this->recordSlugRedirectAction->handle(
            $redirectableType,
            $redirectableId,
            $currentSlug,
            $previousSlug,
        );
    }

    public function purge(string $redirectableType, string $redirectableId): void
    {
        $this->purgeSlugRedirectsAction->handle($redirectableType, $redirectableId);
    }

    public function markUsed(string $redirectId): void
    {
        $this->markSlugRedirectUsedAction->handle($redirectId);
    }
}
