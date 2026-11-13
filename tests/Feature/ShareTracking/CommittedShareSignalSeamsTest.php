<?php

declare(strict_types=1);

use App\Features\ShareTracking\Actions\CommittedSignals\DrainCommittedShareSignalsAction;
use App\Features\ShareTracking\Api\CommittedShareSignals;
use App\Features\ShareTracking\Support\ShareTrackingModel;
use App\Models\ShareCohortProjection;
use App\Models\ShareSignalReaderCursor;
use App\Models\ShareSignalReaderSkip;
use App\Platform\FeatureCatalog;
use App\Shared\ShareTracking\CommittedShareSignal;

it('binds the committed-share signals api to its feature action', function (): void {
    expect(app(CommittedShareSignals::class))->toBeInstanceOf(DrainCommittedShareSignalsAction::class);
});

it('claims the three cohort and signal reader tables for the single drain action', function (): void {
    $catalog = FeatureCatalog::load(base_path());
    $manifest = json_decode((string) file_get_contents(app_path('Features/ShareTracking/feature.json')), true, 512, JSON_THROW_ON_ERROR);

    $claims = [
        'table:share_cohort_projections',
        'table:share_signal_reader_cursors',
        'table:share_signal_reader_skips',
    ];

    foreach ($claims as $claim) {
        $writers = array_keys(array_filter(
            $catalog['writes'],
            static fn (array $resources): bool => in_array($claim, $resources, true),
        ));

        expect($manifest['owns'][$claim] ?? null)->toBe([DrainCommittedShareSignalsAction::class])
            ->and($catalog['owners'][$claim] ?? null)->toBe('ShareTracking')
            ->and($writers)->toBe([DrainCommittedShareSignalsAction::class]);
    }
});

it('resolves the cohort and signal reader models through their morph aliases and literal tables', function (): void {
    expect(ShareTrackingModel::cohortProjection())->toBe(ShareCohortProjection::class)
        ->and(ShareTrackingModel::signalReaderCursor())->toBe(ShareSignalReaderCursor::class)
        ->and(ShareTrackingModel::signalReaderSkip())->toBe(ShareSignalReaderSkip::class)
        ->and((new ShareCohortProjection)->getMorphClass())->toBe('share_cohort_projection')
        ->and((new ShareSignalReaderCursor)->getMorphClass())->toBe('share_signal_reader_cursor')
        ->and((new ShareSignalReaderSkip)->getMorphClass())->toBe('share_signal_reader_skip')
        ->and((new ShareCohortProjection)->getTable())->toBe('share_cohort_projections')
        ->and((new ShareSignalReaderCursor)->getTable())->toBe('share_signal_reader_cursors')
        ->and((new ShareSignalReaderSkip)->getTable())->toBe('share_signal_reader_skips');
});

it('keeps the shared committed-share vocabulary values', function (): void {
    expect(CommittedShareSignal::READER_NAME)->toBe('committed-share-cohort')
        ->and(CommittedShareSignal::EVENT_NAME)->toBe('share.committed_share')
        ->and(CommittedShareSignal::TOUCHPOINT_TYPE)->toBe('outbound_share')
        ->and(CommittedShareSignal::VALID_CONVERSION_STATUSES)->toBe(['qualified', 'approved', 'paid'])
        ->and(CommittedShareSignal::STAGE_OBSERVED)->toBe('observed')
        ->and(CommittedShareSignal::STAGE_PARTICIPATED)->toBe('participated');
});

it('resolves the committed-share signals api for the root command', function (): void {
    $fake = new class implements CommittedShareSignals
    {
        /** @var list<int> */
        public array $batches = [];

        /**
         * @return array{processed: int, projected: int, failed: int}
         */
        public function drain(int $batch = 25): array
        {
            $this->batches[] = $batch;

            return ['processed' => 2, 'projected' => 2, 'failed' => 0];
        }

        public function resetCursor(): void {}
    };

    app()->instance(CommittedShareSignals::class, $fake);

    $this->artisan('signals:drain-committed-shares', ['--batch' => 7])
        ->expectsOutput('Signals processed: 2; projected 2; failed 0.')
        ->assertSuccessful();

    expect($fake->batches)->toBe([7]);
});
