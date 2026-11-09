<?php

declare(strict_types=1);

namespace App\Features\Inspirations\Actions;

use App\Features\Inspirations\Models\Inspiration;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class ToggleInspirationStatusAction
{
    use AsAction;

    public function handle(Inspiration $inspiration): void
    {
        $inspiration->update([
            'status' => (string) $inspiration->status === 'active' ? 'inactive' : 'active',
        ]);
    }
}
