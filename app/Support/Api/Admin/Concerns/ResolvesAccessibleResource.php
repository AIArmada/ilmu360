<?php

namespace App\Support\Api\Admin\Concerns;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait ResolvesAccessibleResource
{
    /**
     * @return class-string<\Filament\Resources\Resource>
     */
    protected function resolveAccessibleResource(string $resourceKey): string
    {
        $resourceClass = $this->registry->resolve($resourceKey);

        if (! is_string($resourceClass)) {
            throw new NotFoundHttpException;
        }

        abort_unless($this->registry->canAccessResource($resourceClass), 403);

        return $resourceClass;
    }
}
