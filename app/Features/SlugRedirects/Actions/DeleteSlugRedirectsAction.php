<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final readonly class DeleteSlugRedirectsAction
{
    use AsAction;

    public function __construct(
        private DeleteSlugRedirectAction $deleteSlugRedirectAction,
    ) {}

    /** @param list<string> $redirectIds */
    public function handle(array $redirectIds): int
    {
        $deleted = 0;
        $exceptionReported = false;

        foreach (array_values(array_unique($redirectIds)) as $redirectId) {
            try {
                if ($this->deleteSlugRedirectAction->handle($redirectId)) {
                    $deleted++;
                }
            } catch (Throwable $exception) {
                if (! $exceptionReported) {
                    report($exception);
                    $exceptionReported = true;
                }
            }
        }

        return $deleted;
    }
}
