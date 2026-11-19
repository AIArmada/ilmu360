<?php

declare(strict_types=1);

namespace App\Platform\Telemetry;

final readonly class ProductSignalSchemaRegistry
{
    public function __construct(
        private SignalSchemaRegistry $schemas,
    ) {}

    /**
     * @return list<string>
     */
    public function allowedProperties(string $eventName): array
    {
        return $this->schemas->allowedProperties($eventName);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function normalize(string $eventName, array $properties): array
    {
        $allowed = array_flip($this->allowedProperties($eventName));

        $normalized = [];

        foreach ($properties as $key => $value) {
            if (! isset($allowed[$key]) || $this->isEmpty($value)) {
                continue;
            }

            if ($this->isSensitiveSearchValue($key, $value)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }

    private function isSensitiveSearchValue(string $key, mixed $value): bool
    {
        if ($key !== 'query' || ! is_string($value)) {
            return false;
        }

        return preg_match('/(?:@|\+?\d[\d\s().-]{6,}|password|token|secret|api[_ -]?key|credit\s*card)/i', $value) === 1;
    }
}
