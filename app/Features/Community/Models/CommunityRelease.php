<?php

declare(strict_types=1);

namespace App\Features\Community\Models;

use App\Features\Community\Actions\AssertCommunityReleaseImmutabilityAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable delayed coarse community release for one fixed month.
 *
 * Published only when BOTH the distinct-sharer threshold and the distinct
 * proven-participant threshold hold over a single canonical evidence set;
 * every published cell independently clears the same dual thresholds,
 * and cuts publish all-or-nothing so no differencing attack recovers a
 * hidden side. No subject-level rows, no precise recent drilldown, no
 * repeats-as-people. Rows are immutable once published; republishing a
 * month returns the published row.
 *
 * @property array<string, mixed> $cells
 * @property CarbonImmutable|null $published_at
 */
final class CommunityRelease extends Model
{
    use HasUuids;

    #[\Override]
    protected $table = 'community_releases';

    #[\Override]
    protected $fillable = [
        'release_key',
        'period_start',
        'period_end',
        'cells',
        'sharer_count',
        'participant_count',
        'published_by_type',
        'published_by_id',
        'published_by_role',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'cells' => 'array',
            'sharer_count' => 'integer',
            'participant_count' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): void {
            app(AssertCommunityReleaseImmutabilityAction::class)->updating();
        });

        self::deleting(static function (): void {
            app(AssertCommunityReleaseImmutabilityAction::class)->deleting();
        });
    }
}
