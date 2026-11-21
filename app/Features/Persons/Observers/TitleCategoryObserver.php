<?php

declare(strict_types=1);

namespace App\Features\Persons\Observers;

use App\Features\Persons\Actions\Projection\InvalidateTitleCategoryCacheAction;
use AIArmada\Persons\Models\TitleCategory;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Framework observer adapter; the feature Action owns the behavior. */
final readonly class TitleCategoryObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private InvalidateTitleCategoryCacheAction $effects) {}

    public function saved(TitleCategory $category): void
    {
        $this->effects->saved($category);
    }

    public function deleted(TitleCategory $category): void
    {
        $this->effects->deleted($category);
    }
}
