<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Actions;

use App\Features\SlugRedirects\Models\SlugRedirect;
use Lorisleiva\Actions\Concerns\AsAction;

final class DeleteSlugRedirectAction
{
    use AsAction;

    public function handle(string $redirectId): bool
    {
        $slugRedirect = SlugRedirect::query()->findOrFail($redirectId);

        return (bool) $slugRedirect->delete();
    }
}
