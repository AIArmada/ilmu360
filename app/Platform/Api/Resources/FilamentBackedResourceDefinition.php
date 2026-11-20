<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use Filament\Resources\Resource;

/**
 * A resource definition backed by exactly one Filament resource class.
 */
interface FilamentBackedResourceDefinition extends ResourceReader
{
    /** @return class-string<resource> */
    public function resourceClass(): string;

    public function writer(): ?ResourceWriter;
}
