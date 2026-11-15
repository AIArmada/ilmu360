<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Actions;

use App\Features\SlugRedirects\Models\SlugRedirect;
use Lorisleiva\Actions\Concerns\AsAction;

final class UpdateSlugRedirectAction
{
    use AsAction;

    public function __construct(
        private readonly PrepareSlugRedirectInputAction $prepareSlugRedirectInputAction,
    ) {}

    public function handle(
        string $redirectId,
        ?string $redirectableType,
        ?string $redirectableId,
        ?string $sourceSlug
    ): void {
        $slugRedirect = SlugRedirect::query()->findOrFail($redirectId);
        $input = $this->prepareSlugRedirectInputAction->handle(
            $redirectableType,
            $redirectableId,
            $sourceSlug,
            $redirectId,
        );

        $slugRedirect->update($input);
    }
}
