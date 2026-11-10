<?php

declare(strict_types=1);

namespace App\Shared\Directory;

/**
 * Capability boundary for models with language assignments.
 */
interface SyncsLanguages
{
    /**
     * @param  list<string>|string  $languages
     */
    public function syncLanguages(array|string $languages): void;
}
