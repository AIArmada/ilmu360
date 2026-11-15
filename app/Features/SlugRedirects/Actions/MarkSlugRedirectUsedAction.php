<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Actions;

use App\Features\SlugRedirects\Models\SlugRedirect;
use Lorisleiva\Actions\Concerns\AsAction;

final class MarkSlugRedirectUsedAction
{
    use AsAction;

    public function handle(string $redirectId): void
    {
        $slugRedirect = SlugRedirect::query()->find($redirectId);

        if (! $slugRedirect instanceof SlugRedirect) {
            return;
        }

        $slugRedirect->forceFill([
            'last_redirected_at' => now(),
            'redirect_count' => $slugRedirect->redirect_count + 1,
        ])->saveQuietly();
    }
}
