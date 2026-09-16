<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

final class SignalsDocsExtension extends OperationExtension
{
    /**
     * Summaries for vendor signals collectors, keyed by URI suffix.
     *
     * @var array<string, array{summary: string, description: string}>
     */
    private const OPERATIONS = [
        'signals/tracker.js' => [
            'summary' => 'Serve the browser tracker script',
            'description' => 'Serves the first-party JavaScript collector that reports pageviews and browser events to the signals collectors.',
        ],
        'signals/collect/browser-event' => [
            'summary' => 'Collect a browser interaction event',
            'description' => 'Ingests a single browser interaction event (clicks, engagement) attributed to the current signal session.',
        ],
        'signals/collect/geo' => [
            'summary' => 'Collect a geolocation signal',
            'description' => 'Ingests a consented browser geolocation payload attributed to the current signal session.',
        ],
        'signals/collect/identify' => [
            'summary' => 'Identify the signal session subject',
            'description' => 'Attaches identity traits to the current signal session for later attribution.',
        ],
        'signals/collect/pageview' => [
            'summary' => 'Collect a pageview signal',
            'description' => 'Ingests a pageview attributed to the current signal session.',
        ],
        'signals/collect/server-outcome' => [
            'summary' => 'Record a server-confirmed outcome',
            'description' => 'Records a backend-confirmed conversion or workflow outcome signal.',
        ],
    ];

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $uri = $routeInfo->route->uri();

        foreach (self::OPERATIONS as $suffix => $docs) {
            if (! Str::endsWith($uri, $suffix)) {
                continue;
            }

            if (blank($operation->summary)) {
                $operation->summary($docs['summary']);
            }

            if (blank($operation->description)) {
                $operation->description($docs['description']);
            }

            return;
        }
    }
}
