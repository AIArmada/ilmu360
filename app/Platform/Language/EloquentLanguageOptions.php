<?php

declare(strict_types=1);

namespace App\Platform\Language;

use App\Platform\Language\Api\LanguageOptions;
use App\Shared\Cache\SelectionCatalog;

final readonly class EloquentLanguageOptions implements LanguageOptions
{
    public function __construct(
        private SelectionCatalog $selectionCatalog,
    ) {}

    public function options(): array
    {
        return $this->selectionCatalog->languageCatalogOptions();
    }
}
