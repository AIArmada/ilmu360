<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Enums\Rewards\RewardProgramMode;
use InvalidArgumentException;

/**
 * Assemble publish rules/terms from structured operator input.
 *
 * Staff never hand-write rules JSON: the publish form collects typed
 * fields per enabled component plus eligibility/windows/caps/holds
 * controls and plain terms fields, and this assembler builds the exact
 * rules/terms maps the validator accepts. Unknown component toggles or
 * empty component sets are refused here, before the publish action.
 */
final class RewardPublishAssembler
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{rules: array<string, mixed>, terms: array<string, mixed>}
     */
    public static function assemble(RewardProgramMode $mode, array $data): array
    {
        $components = [];

        foreach (['recognition', 'points', 'badge', 'cash', 'benefit'] as $type) {
            if (! self::componentEnabled($mode, $type, $data)) {
                continue;
            }

            $components[] = match ($type) {
                'recognition' => array_filter([
                    'type' => 'recognition',
                    'template' => self::text($data['recognition_template'] ?? null, 64),
                ], static fn (mixed $value): bool => $value !== null),
                'points' => [
                    'type' => 'points',
                    'points' => self::int($data['points_amount'] ?? null, 'points amount'),
                    'unit' => self::requiredText($data['points_unit'] ?? null, 16, 'points unit'),
                ],
                'badge' => [
                    'type' => 'badge',
                    'badge_key' => self::requiredText($data['badge_key'] ?? null, 64, 'badge key'),
                ],
                'cash' => [
                    'type' => 'cash',
                    'minor_units' => self::int($data['cash_minor_units'] ?? null, 'cash amount'),
                    'currency' => self::requiredText($data['cash_currency'] ?? null, 3, 'cash currency'),
                ],
                'benefit' => [
                    'type' => 'benefit',
                    'benefit_key' => self::requiredText($data['benefit_key'] ?? null, 64, 'benefit key'),
                    'conditions' => self::lines($data['benefit_conditions'] ?? null),
                ],
            };
        }

        if ($components === []) {
            throw new InvalidArgumentException('At least one reward component must be enabled.');
        }

        $rules = [
            'schema' => RewardRuleValidator::SCHEMA,
            'mode' => $mode->value,
            'components' => $components,
            'eligibility' => [
                'outcomes' => self::stringList($data['eligibility_outcomes'] ?? null, 'eligibility outcomes'),
                'categories' => self::lines($data['eligibility_categories'] ?? null),
                'min_verification' => self::requiredText($data['min_verification'] ?? null, 32, 'minimum verification'),
                'first_only' => (bool) ($data['first_only'] ?? false),
                'exclude_self_credit' => (bool) ($data['exclude_self_credit'] ?? true),
                'require_enrollment' => (bool) ($data['require_enrollment'] ?? true),
                'require_claimed_identity' => (bool) ($data['require_claimed_identity'] ?? true),
            ],
            'windows' => [
                'preclaim_lookback_days' => self::int($data['preclaim_lookback_days'] ?? 30, 'preclaim lookback'),
                'hold_days' => self::int($data['hold_days'] ?? 0, 'hold days'),
            ],
            'caps' => self::caps($data),
            'holds' => [
                'ambiguous_device' => (bool) ($data['ambiguous_device'] ?? true),
                'manual_review_outcomes' => self::stringList($data['manual_review_outcomes'] ?? [], 'manual review outcomes', true),
            ],
        ];

        $terms = [
            'label' => self::requiredText($data['terms_label'] ?? null, 191, 'terms label'),
            'version_label' => self::requiredText($data['terms_version_label'] ?? null, 32, 'terms version label'),
            'body' => self::requiredText($data['terms_body'] ?? null, 20000, 'terms body'),
            'material' => [
                'mode' => $mode->value,
                'earn_basis' => self::requiredText($data['terms_earn_basis'] ?? null, 2000, 'earn basis'),
                'changes' => self::requiredText($data['terms_changes'] ?? null, 2000, 'changes clause'),
                'no_ranking' => self::requiredText($data['terms_no_ranking'] ?? null, 2000, 'no-ranking clause'),
            ],
        ];

        return ['rules' => $rules, 'terms' => $terms];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function componentEnabled(RewardProgramMode $mode, string $type, array $data): bool
    {
        if ($mode === RewardProgramMode::Combined) {
            return (bool) ($data['component_'.$type] ?? in_array($type, ['recognition', 'points', 'badge'], true));
        }

        foreach ($mode->allowedComponents() as $allowed) {
            if ($allowed->value === $type) {
                return true;
            }
        }

        return false;
    }

    private static function requiredText(mixed $value, int $max, string $label): string
    {
        if (! is_string($value) || mb_trim($value) === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException("Publish {$label} must be 1..{$max} characters.");
        }

        return $value;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || mb_strlen($value) > $max) {
            throw new InvalidArgumentException('Publish template must be up to '.$max.' characters.');
        }

        return $value;
    }

    private static function int(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }

        // Livewire dehydrates numeric inputs as floats; whole floats are
        // the same integer the operator typed.
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', mb_trim($value)) === 1) {
            return (int) mb_trim($value);
        }

        throw new InvalidArgumentException("Publish {$label} must be an integer, got [".get_debug_type($value).'].');
    }

    /**
     * @return list<string>
     */
    private static function lines(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter(
                array_map(static fn (mixed $line): string => is_string($line) ? mb_trim($line) : '', $value),
                static fn (string $line): bool => $line !== '',
            ));
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Publish list fields take one entry per line.');
        }

        return array_values(array_filter(
            array_map(static fn (string $line): string => mb_trim($line), preg_split('/\r\n|\r|\n/', $value) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, string $label, bool $allowEmpty = false): array
    {
        $lines = is_array($value) && array_is_list($value) ? self::lines($value) : self::lines($value);

        if ($lines === [] && ! $allowEmpty) {
            throw new InvalidArgumentException("Publish {$label} needs at least one entry.");
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{scope: string, limit: int}>
     */
    private static function caps(array $data): array
    {
        $caps = [];

        for ($row = 1; $row <= 3; $row++) {
            $scope = $data['cap_scope_'.$row] ?? null;

            if ($scope === null || $scope === '') {
                continue;
            }

            if (! is_string($scope)) {
                throw new InvalidArgumentException('Publish cap scope must be a string.');
            }

            $caps[] = ['scope' => $scope, 'limit' => self::int($data['cap_limit_'.$row] ?? null, 'cap limit')];
        }

        return $caps;
    }
}
