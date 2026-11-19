<?php

declare(strict_types=1);

namespace App\Platform\Api\Frontend\Support;

use App\Platform\Language\Models\Language;

/**
 * Neutral lookups shared by frontend form-contract contributors.
 *
 * Keeps Platform model access out of owner Api namespaces.
 */
final class FrontendFormContractDefaults
{
    public function defaultLanguageId(): ?string
    {
        $languageId = Language::query()->where('code', 'ms')->value('id');

        return $languageId === null ? null : (string) $languageId;
    }
}
