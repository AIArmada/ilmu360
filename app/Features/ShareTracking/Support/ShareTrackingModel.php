<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Support;

use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Runtime model access for ShareTracking while the models remain in the legacy
 * root namespace. Resolution goes through the registered morph aliases so the
 * feature never imports the private model class directly.
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
     * @return Builder<Model>
     */
    public static function browserSelectionQuery(): Builder
    {
        return self::browserSelection()::query();
    }

    /**
     * @return Builder<Model>
     */
    public static function sourceReceiptQuery(): Builder
    {
        return self::sourceReceipt()::query();
    }

    /**
     * @return Builder<Model>
     */
    public static function effectReceiptQuery(): Builder
    {
        return self::effectReceipt()::query();
    }

    /**
     * @return Builder<Model>
     */
    public static function outcomeCorrectionQuery(): Builder
    {
        return self::outcomeCorrection()::query();
    }

    /**
     * @return Builder<Model>
     */
    public static function cohortProjectionQuery(): Builder
    {
        return self::cohortProjection()::query();
    }

    /**
     * @return Builder<Model>
     */
    public static function signalReaderCursorQuery(): Builder
    {
        return self::signalReaderCursor()::query();
    }

    /**
     * @return Builder<Model>
     */
    public static function signalReaderSkipQuery(): Builder
    {
        return self::signalReaderSkip()::query();
    }
}
