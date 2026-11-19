<?php

declare(strict_types=1);

namespace App\Platform\Telemetry;

use App\Platform\Telemetry\Contracts\SignalSchemaContributor;

final class SignalSchemaRegistry
{
    /** @var list<SignalSchemaContributor> */
    private array $contributors = [];

    public function register(SignalSchemaContributor $contributor): void
    {
        if (! in_array($contributor, $this->contributors, true)) {
            $this->contributors[] = $contributor;
        }
    }

    /**
     * @return list<string>
     */
    public function allowedProperties(string $eventName): array
    {
        $schemas = $this->schemasFor($eventName);

        if (array_key_exists($eventName, $schemas)) {
            return $schemas[$eventName];
        }

        foreach ($schemas as $pattern => $properties) {
            if (! str_ends_with($pattern, '*')) {
                continue;
            }

            if (str_starts_with($eventName, substr($pattern, 0, -1))) {
                return $properties;
            }
        }

        return [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function schemasFor(string $eventName): array
    {
        $schemas = [];

        foreach ($this->contributors as $contributor) {
            if (! $contributor->supports($eventName)) {
                continue;
            }

            $schemas += $contributor->schemas();
        }

        return $schemas;
    }
}
