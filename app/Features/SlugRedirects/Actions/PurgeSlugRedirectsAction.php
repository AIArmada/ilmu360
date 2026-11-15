<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Actions;

use App\Features\SlugRedirects\Models\SlugRedirect;
use Lorisleiva\Actions\Concerns\AsAction;

final class PurgeSlugRedirectsAction
{
    use AsAction;

    public function handle(string $redirectableType, string $redirectableId): void
    {
        SlugRedirect::query()
            ->where('redirectable_type', $redirectableType)
            ->where('redirectable_id', $redirectableId)
            ->delete();
    }
}
