<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use AIArmada\FilamentSignals\Pages\LiveActivityReport;
use App\Services\Signals\ProductSignalsInsightsService;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * @phpstan-import-type ProductSignalsDashboard from \App\Services\Signals\ProductSignalsInsightsService
 */
final class ProductSignals extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Product Signals';

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
    ];

    public ?string $liveActivityUrl = null;

    public function mount(ProductSignalsInsightsService $insights): void
    {
        $this->report = $insights->dashboard();
        $this->liveActivityUrl = class_exists(LiveActivityReport::class)
            ? LiveActivityReport::getUrl(panel: 'admin')
            : null;
    }
}
