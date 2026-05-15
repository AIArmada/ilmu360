<?php

namespace App\Observers\Concerns;

use App\Actions\Slugs\Concerns\NormalizesComparableStrings;

trait SyncsCurrentAndPreviousValues
{
    use NormalizesComparableStrings;

    /**
     * Sync the current comparable string first, then sync the previous value if
     * it was non-empty and differs after normalization.
     *
     * This lets observers fan out slug recalculation for both the new canonical
     * value and any peers that may need to reclaim an older sequence slot.
     *
     * @param  callable(string): mixed  ...$syncers
     */
    protected function syncCurrentAndPreviousString(mixed $currentValue, mixed $previousValue, callable ...$syncers): void
    {
        $normalizedCurrentValue = $this->normalizeComparableString($currentValue);

        if ($normalizedCurrentValue === null) {
            return;
        }

        foreach ($syncers as $syncer) {
            $syncer($normalizedCurrentValue);
        }

        $normalizedPreviousValue = $this->normalizeComparableString($previousValue);

        if ($normalizedPreviousValue === null || $normalizedPreviousValue === $normalizedCurrentValue) {
            return;
        }

        foreach ($syncers as $syncer) {
            $syncer($normalizedPreviousValue);
        }
    }
}
