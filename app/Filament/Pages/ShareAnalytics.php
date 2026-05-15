<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use AIArmada\FilamentSignals\Pages\PageViewsReport;
use App\Services\ShareTracking\AdminShareAnalyticsService;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * @phpstan-import-type ShareAnalyticsDashboard from \App\Services\ShareTracking\AdminShareAnalyticsService
 */
final class ShareAnalytics extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-share';

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'Share Analytics';

    protected string $view = 'filament.pages.share-analytics';

    /** @var ShareAnalyticsDashboard */
    public array $report = [
        'summary' => [
            'affiliates' => 0,
            'shared_links' => 0,
            'outbound_shares' => 0,
            'visits' => 0,
            'unique_visitors' => 0,
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

    public function mount(AdminShareAnalyticsService $analytics): void
    {
        $this->report = $analytics->dashboard();
        $this->signalsReportUrl = class_exists(PageViewsReport::class)
            ? PageViewsReport::getUrl(panel: 'admin')
            : null;
    }
}
