<?php

declare(strict_types=1);

use App\Features\ShareTracking\Actions\BrowserSelection\LockBrowserSelectionAction;
use App\Features\ShareTracking\FeatureServiceProvider as ShareTrackingServiceProvider;
use App\Features\ShareTracking\Persistence\DatabaseEffectReceipts;
use App\Features\ShareTracking\Persistence\DatabaseOutcomeCorrections;
use App\Features\ShareTracking\Persistence\DatabaseSourceAttributionReceipts;
use App\Features\ShareTracking\Support\ShareTrackingModel;
use App\Models\ShareBrowserSelection;
use App\Models\ShareCohortProjection;
use App\Models\ShareEffectReceipt;
use App\Models\ShareOutcomeCorrection;
use App\Models\ShareSignalReaderCursor;
use App\Models\ShareSignalReaderSkip;
use App\Models\ShareSourceAttributionReceipt;
use App\Platform\FeatureCatalog;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use App\Shared\ShareTracking\ShareOutcomeType;
use Illuminate\Support\Str;

dataset('share tracking owned tables', [
    'browser selections' => [ShareBrowserSelection::class, 'share_browser_selections'],
    'source attribution receipts' => [ShareSourceAttributionReceipt::class, 'share_source_attribution_receipts'],
    'effect receipts' => [ShareEffectReceipt::class, 'share_effect_receipts'],
    'outcome corrections' => [ShareOutcomeCorrection::class, 'share_outcome_corrections'],
    'cohort projections' => [ShareCohortProjection::class, 'share_cohort_projections'],
    'signal reader cursors' => [ShareSignalReaderCursor::class, 'share_signal_reader_cursors'],
    'signal reader skips' => [ShareSignalReaderSkip::class, 'share_signal_reader_skips'],
]);

it('claims the browser selection, effect receipt, outcome correction and source receipt tables with their named writers', function (): void {
    $catalog = FeatureCatalog::load(base_path());
    $manifest = json_decode((string) file_get_contents(app_path('Features/ShareTracking/feature.json')), true, 512, JSON_THROW_ON_ERROR);
    $providers = require base_path('bootstrap/providers.php');

    expect($catalog['features'])->toHaveKey('ShareTracking')
        ->and($manifest['schema'] ?? null)->toBe(2)
        ->and($manifest['name'] ?? null)->toBe('ShareTracking')
        ->and($manifest['owns'] ?? null)->toBe([
            'table:share_browser_selections' => [LockBrowserSelectionAction::class],
            'table:share_effect_receipts' => [DatabaseEffectReceipts::class],
            'table:share_outcome_corrections' => [DatabaseOutcomeCorrections::class],
            'table:share_source_attribution_receipts' => [DatabaseSourceAttributionReceipts::class],
        ])
        ->and($catalog['owners'])->toHaveKey('table:share_browser_selections')
        ->and($catalog['owners']['table:share_browser_selections'])->toBe('ShareTracking')
        ->and($catalog['owners'])->toHaveKey('table:share_effect_receipts')
        ->and($catalog['owners']['table:share_effect_receipts'])->toBe('ShareTracking')
        ->and($catalog['owners'])->toHaveKey('table:share_outcome_corrections')
        ->and($catalog['owners']['table:share_outcome_corrections'])->toBe('ShareTracking')
        ->and($catalog['owners'])->toHaveKey('table:share_source_attribution_receipts')
        ->and($catalog['owners']['table:share_source_attribution_receipts'])->toBe('ShareTracking')
        ->and(array_filter($catalog['owners'], static fn (string $owner): bool => $owner === 'ShareTracking'))->toHaveCount(4)
        ->and(array_keys($providers, ShareTrackingServiceProvider::class, true))->toHaveCount(1);
});

it('resolves the browser selection model through its registered morph alias', function (): void {
    expect(ShareTrackingModel::browserSelection())->toBe(ShareBrowserSelection::class)
        ->and((new ShareBrowserSelection)->getMorphClass())->toBe('share_browser_selection');
});

it('declares the owned table literally on the root model', function (string $model, string $table): void {
    $defaults = (new ReflectionClass($model))->getDefaultProperties();

    expect($defaults['table'] ?? null)->toBe($table)
        ->and((new $model)->getTable())->toBe($table);
})->with('share tracking owned tables');

it('preserves the share outcome vocabulary values', function (): void {
    expect(array_map(static fn (ShareOutcomeType $type): string => $type->value, ShareOutcomeType::cases()))
        ->toBe([
            'signup',
            'event_registration',
            'event_checkin',
            'event_submission',
            'event_save',
            'event_going',
            'institution_follow',
            'person_follow',
            'series_follow',
            'reference_follow',
            'saved_search_created',
        ]);
});

it('builds canonical business keys from the authoritative source identity', function (): void {
    $id = (string) Str::uuid();

    expect(ShareOutcomeBusinessKey::NAMESPACE)->toBe('share-outcome')
        ->and(ShareOutcomeBusinessKey::signup($id))->toBe("share-outcome:signup:user:{$id}")
        ->and(ShareOutcomeBusinessKey::eventRegistration($id))->toBe("share-outcome:event_registration:registration:{$id}")
        ->and(ShareOutcomeBusinessKey::eventCheckin($id))->toBe("share-outcome:event_checkin:checkin:{$id}")
        ->and(ShareOutcomeBusinessKey::eventSubmission($id))->toBe("share-outcome:event_submission:submission:{$id}")
        ->and(ShareOutcomeBusinessKey::eventSave($id))->toBe("share-outcome:event_save:bookmark:{$id}")
        ->and(ShareOutcomeBusinessKey::eventGoing($id))->toBe("share-outcome:event_going:response:{$id}")
        ->and(ShareOutcomeBusinessKey::follow(ShareOutcomeType::InstitutionFollow, $id))->toBe("share-outcome:institution_follow:follow:{$id}")
        ->and(ShareOutcomeBusinessKey::follow(ShareOutcomeType::PersonFollow, $id))->toBe("share-outcome:person_follow:follow:{$id}")
        ->and(ShareOutcomeBusinessKey::follow(ShareOutcomeType::SeriesFollow, $id))->toBe("share-outcome:series_follow:follow:{$id}")
        ->and(ShareOutcomeBusinessKey::follow(ShareOutcomeType::ReferenceFollow, $id))->toBe("share-outcome:reference_follow:follow:{$id}")
        ->and(ShareOutcomeBusinessKey::savedSearch($id))->toBe("share-outcome:saved_search_created:saved_search:{$id}");
});

it('round-trips a canonical business key through parse', function (): void {
    $id = (string) Str::uuid();
    $key = ShareOutcomeBusinessKey::eventRegistration($id);

    expect(ShareOutcomeBusinessKey::parse($key, ShareOutcomeType::EventRegistration))->toBe([
        'namespace' => 'share-outcome',
        'outcome_type' => 'event_registration',
        'source_transition_id' => "registration:{$id}",
    ]);
});

it('rejects non-canonical business key identities', function (): void {
    $id = (string) Str::uuid();

    expect(fn (): string => ShareOutcomeBusinessKey::for(ShareOutcomeType::Signup, "registration:{$id}"))
        ->toThrow(InvalidArgumentException::class, 'exact source prefix')
        ->and(fn (): string => ShareOutcomeBusinessKey::for(ShareOutcomeType::Signup, 'user:not-a-uuid'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): array => ShareOutcomeBusinessKey::parse("share-outcome:event_registration:registration:{$id}", ShareOutcomeType::Signup))
        ->toThrow(InvalidArgumentException::class, 'type must match')
        ->and(fn (): array => ShareOutcomeBusinessKey::parse("wrong:signup:user:{$id}", ShareOutcomeType::Signup))
        ->toThrow(InvalidArgumentException::class, 'canonical share-outcome namespace');
});

it('removes the old shared vocabulary locations without aliases', function (): void {
    expect(app_path('Enums/ShareOutcomeType.php'))->not->toBeFile()
        ->and(app_path('Support/ShareTracking/ShareOutcomeBusinessKey.php'))->not->toBeFile();
});
