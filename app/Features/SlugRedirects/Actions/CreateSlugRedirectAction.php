<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Actions;

use App\Features\SlugRedirects\Models\SlugRedirect;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class CreateSlugRedirectAction
{
    use AsAction;

    public function __construct(
        private PrepareSlugRedirectInputAction $prepareSlugRedirectInputAction,
    ) {}

    public function handle(
        ?string $redirectableType,
        ?string $redirectableId,
        ?string $sourceSlug
    ): string {
        $input = $this->prepareSlugRedirectInputAction->handle(
            $redirectableType,
            $redirectableId,
            $sourceSlug,
        );

        $slugRedirect = SlugRedirect::query()->create([
            ...$input,
            'first_visited_at' => null,
            'last_redirected_at' => null,
            'redirect_count' => 0,
        ]);

        return (string) $slugRedirect->getKey();
    }
}
