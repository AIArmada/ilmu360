<?php

declare(strict_types=1);

namespace App\Providers\Filament\Concerns;

trait ResolvesPanelDomain
{
    protected function resolvePanelDomain(string $panelId): ?string
    {
        $configuredDomain = config("filament-panels.domains.{$panelId}");

        if (is_string($configuredDomain) && trim($configuredDomain) !== '') {
            return trim($configuredDomain);
        }

        if (app()->runningUnitTests()) {
            return null;
        }

        $appUrlHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($appUrlHost) || trim($appUrlHost) === '') {
            return null;
        }

        $host = trim($appUrlHost);

        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        if (str_starts_with($host, "{$panelId}.")) {
            return $host;
        }

        return "{$panelId}.{$host}";
    }
}
