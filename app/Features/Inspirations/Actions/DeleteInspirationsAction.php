<?php

declare(strict_types=1);

namespace App\Features\Inspirations\Actions;

use App\Features\Inspirations\Models\Inspiration;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DeleteInspirationsAction
{
    use AsAction;

    public function __construct(
        private DeleteInspirationAction $deleteInspiration,
    ) {}

    /**
     * @param  list<int|string>  $inspirationIds
     */
    public function handle(array $inspirationIds): void
    {
        Inspiration::query()
            ->whereKey($inspirationIds)
            ->get()
            ->each(function (Inspiration $inspiration): void {
                $this->deleteInspiration->handle($inspiration);
            });
    }
}
