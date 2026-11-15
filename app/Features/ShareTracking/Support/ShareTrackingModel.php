<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Support;

use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Runtime model access for ShareTracking models kept in the app model
 * namespace. Resolution uses registered morph aliases so the feature does not
 * import those model classes directly.
 */
final class ShareTrackingModel
{
    /**
     * @return class-string<Model>
     */
    public static function browserSelection(): string
    {
        return MorphModelResolver::resolve('share_browser_selection');
    }

    /**
     * @return class-string<Model>
     */
    public static function sourceReceipt(): string
    {
        return MorphModelResolver::resolve('share_source_attribution_receipt');
    }

    /**
     * @return class-string<Model>
     */
    public static function effectReceipt(): string
    {
        return MorphModelResolver::resolve('share_effect_receipt');
    }

    /**
     * @return class-string<Model>
     */
    public static function outcomeCorrection(): string
    {
        return MorphModelResolver::resolve('share_outcome_correction');
    }

    /**
     * @return class-string<Model>
     */
    public static function cohortProjection(): string
    {
        return MorphModelResolver::resolve('share_cohort_projection');
    }

    /**
     * @return class-string<Model>
     */
    public static function signalReaderCursor(): string
    {
        return MorphModelResolver::resolve('share_signal_reader_cursor');
    }

    /**
     * @return class-string<Model>
     */
    public static function signalReaderSkip(): string
    {
        return MorphModelResolver::resolve('share_signal_reader_skip');
    }

    /**
     * Resolves a model query from its canonical morph alias.
     *
     * Owning writers pass literal aliases so their table target stays explicit
     * while the model classes remain in the application root namespace.
     *
     * @return Builder<Model>
     */
    public static function query(string $alias): Builder
    {
        return MorphModelResolver::resolve($alias)::query();
    }
}
