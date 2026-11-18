<?php

declare(strict_types=1);

namespace App\Platform\Language;

use App\Platform\Language\Api\LanguageOptions;
use App\Platform\Language\Models\Language;

final readonly class EloquentLanguageOptions implements LanguageOptions
{
    public function options(): array
    {
        return Language::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Language $language): array => [
                'id' => (string) $language->id,
                'label' => (string) $language->name,
            ])
            ->all();
    }
}
