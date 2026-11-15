<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Integration;

use AIArmada\CommerceSupport\Contracts\SlugRedirectRecorder;
use App\Features\SlugRedirects\Api\SlugRedirectCommands;
use Illuminate\Database\Eloquent\Model;

final readonly class CanonicalSlugRedirectRecorder implements SlugRedirectRecorder
{
    public function __construct(
        private SlugRedirectCommands $slugRedirectCommands,
    ) {}

    public function record(Model $model, ?string $previousSlug): bool
    {
        $id = $model->getKey();
        $currentSlug = $model->getAttribute('slug');

        if ($id === null || ! is_string($currentSlug)) {
            return false;
        }

        return $this->slugRedirectCommands->record(
            $model->getMorphClass(),
            (string) $id,
            $currentSlug,
            $previousSlug,
        );
    }
}
