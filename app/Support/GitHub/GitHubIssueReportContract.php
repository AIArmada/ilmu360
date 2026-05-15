<?php

declare(strict_types=1);

namespace App\Support\GitHub;

use Illuminate\Validation\Rule;

final class GitHubIssueReportContract
{
    public const string DEFAULT_CATEGORY = 'bug';

    public static function categoryDescription(): string
    {
        $choices = [];

        foreach (self::categoryLabels() as $value => $label) {
            $choices[] = sprintf('%s (%s)', $value, $label);
        }

        return 'Valid values: '.implode(', ', $choices).'.';
    }

    /**
     * @return list<string>
     */
    public static function categories(): array
    {
        return array_keys(self::categoryLabels());
    }

    /**
     * @return array<string, string>
     */
    public static function categoryLabels(): array
    {
        return [
            'bug' => 'Bug',
            'docs_mismatch' => 'Documentation Mismatch',
            'proposal' => 'Proposal',
            'feature_request' => 'Feature Request',
            'parameter_change' => 'Function / Parameter Change',
            'other' => 'Other',
        ];
    }

    public static function categoryLabel(string $category): string
    {
        return self::categoryLabels()[$category] ?? self::categoryLabels()[self::DEFAULT_CATEGORY];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(self::categories())],
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'summary' => ['required', 'string', 'min:10', 'max:1000'],
            'description' => ['nullable', 'string', 'max:4000'],
            'platform' => ['required', 'string', 'max:100'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'client_version' => ['nullable', 'string', 'max:100'],
            'current_endpoint' => ['nullable', 'string', 'max:255'],
            'tool_name' => ['nullable', 'string', 'max:255'],
            'steps_to_reproduce' => ['nullable', 'string', 'max:4000'],
            'expected_behavior' => ['nullable', 'string', 'max:4000'],
            'actual_behavior' => ['nullable', 'string', 'max:4000'],
            'proposal' => ['nullable', 'string', 'max:4000'],
            'additional_context' => ['nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'category' => self::DEFAULT_CATEGORY,
            'title' => '',
            'summary' => '',
            'description' => '',
            'platform' => '',
            'client_name' => '',
            'client_version' => '',
            'current_endpoint' => '',
            'tool_name' => '',
            'steps_to_reproduce' => '',
            'expected_behavior' => '',
            'actual_behavior' => '',
            'proposal' => '',
            'additional_context' => '',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fields(): array
    {
        return [
            self::field('category', 'string', true, default: self::DEFAULT_CATEGORY, allowedValues: self::categories()),
            self::field('title', 'string', true, maxLength: 255),
            self::field('summary', 'string', true, maxLength: 1000),
            self::field('description', 'string', false, maxLength: 4000),
            self::field('platform', 'string', true, maxLength: 100),
            self::field('client_name', 'string', false, maxLength: 255),
            self::field('client_version', 'string', false, maxLength: 100),
            self::field('current_endpoint', 'string', false, maxLength: 255),
            self::field('tool_name', 'string', false, maxLength: 255),
            self::field('steps_to_reproduce', 'string', false, maxLength: 4000),
            self::field('expected_behavior', 'string', false, maxLength: 4000),
            self::field('actual_behavior', 'string', false, maxLength: 4000),
            self::field('proposal', 'string', false, maxLength: 4000),
            self::field('additional_context', 'string', false, maxLength: 4000),
        ];
    }

    /**
     * @param  list<string>|null  $allowedValues
     * @return array<string, mixed>
     */
    private static function field(
        string $name,
        string $type,
        bool $required,
        mixed $default = null,
        ?int $maxLength = null,
        ?array $allowedValues = null,
    ): array {
        return array_filter([
            'name' => $name,
            'type' => $type,
            'required' => $required,
            'default' => $default,
            'max_length' => $maxLength,
            'allowed_values' => $allowedValues,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
