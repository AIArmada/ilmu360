<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Services;

use App\Features\SlugRedirects\Actions\CreateSlugRedirectAction;
use App\Features\SlugRedirects\Actions\DeleteSlugRedirectAction;
use App\Features\SlugRedirects\Actions\DeleteSlugRedirectsAction;
use App\Features\SlugRedirects\Actions\UpdateSlugRedirectAction;
use App\Features\SlugRedirects\Api\SlugRedirectAdministration;

final readonly class SlugRedirectAdministrationService implements SlugRedirectAdministration
{
    public function __construct(
        private CreateSlugRedirectAction $createSlugRedirectAction,
        private UpdateSlugRedirectAction $updateSlugRedirectAction,
        private DeleteSlugRedirectAction $deleteSlugRedirectAction,
        private DeleteSlugRedirectsAction $deleteSlugRedirectsAction,
    ) {}

    public function create(
        ?string $redirectableType,
        ?string $redirectableId,
        ?string $sourceSlug
    ): string {
        return $this->createSlugRedirectAction->handle(
            $redirectableType,
            $redirectableId,
            $sourceSlug,
        );
    }

    public function update(
        string $redirectId,
        ?string $redirectableType,
        ?string $redirectableId,
        ?string $sourceSlug
    ): void {
        $this->updateSlugRedirectAction->handle(
            $redirectId,
            $redirectableType,
            $redirectableId,
            $sourceSlug,
        );
    }

    public function delete(string $redirectId): bool
    {
        return $this->deleteSlugRedirectAction->handle($redirectId);
    }

    public function deleteMany(array $redirectIds): int
    {
        return $this->deleteSlugRedirectsAction->handle($redirectIds);
    }
}
