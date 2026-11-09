<?php

declare(strict_types=1);

namespace App\Features\Inspirations\Actions;

use App\Features\Inspirations\Models\Inspiration;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DeleteInspirationAction
{
    use AsAction;

    public function handle(Inspiration $inspiration): void
    {
        $inspiration->delete();
    }
}
