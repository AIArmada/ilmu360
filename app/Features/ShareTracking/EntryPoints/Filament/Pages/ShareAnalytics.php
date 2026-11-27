<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\EntryPoints\Filament\Pages;

use AIArmada\FilamentSignals\Features\Reporting\EntryPoints\Filament\Pages\PageViewsReport;
use App\Features\ShareTracking\Persistence\ShareAnalyticsDashboardCacheStore;
use App\Features\ShareTracking\Services\Analytics\AdminShareAnalyticsService;
use App\Features\ShareTracking\Support\ShareTrackingMeasurement;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * @phpstan-import-type ShareAnalyticsDashboard from \App\Features\ShareTracking\Services\Analytics\AdminShareAnalyticsService
 */
final class ShareAnalytics extends Page
{
    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    #[\Override]
    protected static ?int $navigationSort = 11;

    #[\Override]
    protected static ?string $title = 'Share Analytics';

    #[\Override]
    protected string $view = 'filament.pages.share-analytics';

    /** @var ShareAnalyticsDashboard */
    public array $report = [
        'summary' => [
            'affiliates' => 0,
            'shared_links' => 0,
            'outbound_shares' => 0,
            'visits' => 0,
            'distinct_browsers' => 0,
            'signups' => 0,
            'event_registrations' => 0,
            'event_checkins' => 0,
            'event_submissions' => 0,
            'total_outcomes' => 0,
        ],
        'provider_breakdown' => [],
        'top_sharers' => [],
        'top_links' => [],
        'recent_visits' => [],
        'recent_outcomes' => [],
    ];

    public ?string $signalsReportUrl = null;

    public string $measurementStatus = 'not_configured';

    public ?string $reportingStart = null;

    public function mount(AdminShareAnalyticsService $analytics, ShareAnalyticsDashboardCacheStore $cache): void
    {
        $this->report = $cache->get($analytics);

        $this->measurementStatus = ShareTrackingMeasurement::status();
        $this->reportingStart = ShareTrackingMeasurement::reportingStart();
        $this->signalsReportUrl = class_exists(PageViewsReport::class)
            ? PageViewsReport::getUrl(panel: 'admin')
            : null;
    }
}
