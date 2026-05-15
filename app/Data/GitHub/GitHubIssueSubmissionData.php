<?php

declare(strict_types=1);

namespace App\Data\GitHub;

use Spatie\LaravelData\Data;

class GitHubIssueSubmissionData extends Data
{
    public function __construct(
        public string $category,
        public string $title,
        public string $summary,
        public string $platform,
        public ?string $description = null,
        public ?string $client_name = null,
        public ?string $client_version = null,
        public ?string $current_endpoint = null,
        public ?string $tool_name = null,
        public ?string $steps_to_reproduce = null,
        public ?string $expected_behavior = null,
        public ?string $actual_behavior = null,
        public ?string $proposal = null,
        public ?string $additional_context = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            category: trim((string) $validated['category']),
            title: trim((string) $validated['title']),
            summary: trim((string) $validated['summary']),
            platform: trim((string) $validated['platform']),
            description: self::normalizeOptionalString($validated['description'] ?? null),
            client_name: self::normalizeOptionalString($validated['client_name'] ?? null),
            client_version: self::normalizeOptionalString($validated['client_version'] ?? null),
            current_endpoint: self::normalizeOptionalString($validated['current_endpoint'] ?? null),
            tool_name: self::normalizeOptionalString($validated['tool_name'] ?? null),
            steps_to_reproduce: self::normalizeOptionalString($validated['steps_to_reproduce'] ?? null),
            expected_behavior: self::normalizeOptionalString($validated['expected_behavior'] ?? null),
            actual_behavior: self::normalizeOptionalString($validated['actual_behavior'] ?? null),
            proposal: self::normalizeOptionalString($validated['proposal'] ?? null),
            additional_context: self::normalizeOptionalString($validated['additional_context'] ?? null),
        );
    }

    private static function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
