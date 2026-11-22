<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Pages;

use AIArmada\CommerceSupport\Support\UserTimezoneResolver;
use AIArmada\FilamentSignals\Pages\LiveActivityReport;
use App\Features\Events\Api\ProductSignalsDashboardCache;
use App\Features\Events\Api\ProductSignalsInsights;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * @phpstan-import-type ProductSignalsDashboard from \App\Features\Events\Api\ProductSignalsInsights
 */
final class ProductSignals extends Page
{
    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    #[\Override]
    protected static ?int $navigationSort = 10;

    #[\Override]
    protected static ?string $title = 'Product Signals';

    #[\Override]
    protected string $view = 'filament.pages.product-signals';

    /** @var ProductSignalsDashboard */
    public array $report = [
        'summary' => [
            'events' => 0,
            'web_events' => 0,
            'api_events' => 0,
            'mobile_events' => 0,
            'unattributed_events' => 0,
        ],
        'origin_breakdown' => [],
        'platform_breakdown' => [],
        'transport_breakdown' => [],
        'recent_events' => [],
        'scorecard' => [
            'zero_result_filters' => [],
            'supply_gaps' => [],
            'search_to_outcome' => [
                'searches' => 0,
                'with_results' => 0,
                'conversion_rate' => 0.0,
            ],
            'moderation_minutes' => [
                'median' => 0.0,
                'p90' => 0.0,
            ],
            'imminent_pending' => 0,
        ],
    ];

    public ?string $liveActivityUrl = null;

    public function mount(
        ProductSignalsInsights $insights,
        ProductSignalsDashboardCache $dashboardCache,
    ): void {
        $this->report = $dashboardCache->rememberDashboard(
            UserTimezoneResolver::resolve(),
            app()->getLocale(),
            fn (): array => $insights->dashboard(),
        );

        $this->liveActivityUrl = class_exists(LiveActivityReport::class)
            ? LiveActivityReport::getUrl(panel: 'admin')
            : null;
    }
}
