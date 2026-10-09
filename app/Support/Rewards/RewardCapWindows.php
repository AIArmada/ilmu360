<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Deterministic cap-counter keys for the validated cap scopes.
 *
 * Counter rows are unique (cap_key, window_key); all counter locks are taken
 * in sorted cap_key order. Cross-program counters use the literal '*'
 * family marker so one source/component cannot overspend across families.
 *
 * recipient_source_component is deliberately program-independent: the default
 * preset (limit 1) allows one award per recipient/source/component across
 * ALL families. Families that stack compatibly must relax or drop that cap
 * in their own frozen rules — the stacking allowlist never overrides caps.
 */
final class RewardCapWindows
{
    /**
     * @return array{cap_key: string, window_key: string}
     */
    public static function keysFor(
        string $scope,
        string $familyKey,
        string $programId,
        string $recipientKey,
        string $sourceBusinessKey,
        string $component,
        CarbonImmutable $decision,
    ): array {
        $decision = $decision->setTimezone('UTC');

        return match ($scope) {
            'recipient_source_component' => [
                'cap_key' => "rsc:{$recipientKey}:".self::digest($sourceBusinessKey).":{$component}",
                'window_key' => 'per-source',
            ],
            'recipient_program_day' => [
                'cap_key' => "rpd:{$recipientKey}:{$programId}",
                'window_key' => $decision->format('Y-m-d'),
            ],
            'recipient_program_month' => [
                'cap_key' => "rpm:{$recipientKey}:{$programId}",
                'window_key' => $decision->format('Y-m'),
            ],
            'program_day' => [
                'cap_key' => "pd:{$programId}",
                'window_key' => $decision->format('Y-m-d'),
            ],
            'program_total' => [
                'cap_key' => "pt:{$programId}",
                'window_key' => 'total',
            ],
            'family_source_component' => [
                'cap_key' => 'fsc:*:'.self::digest($sourceBusinessKey).":{$component}",
                'window_key' => 'per-source',
            ],
            default => throw new InvalidArgumentException("Unknown cap scope [{$scope}]."),
        };
    }

    public static function recipientKey(string $recipientType, string $recipientId): string
    {
        return "{$recipientType}:{$recipientId}";
    }

    private static function digest(string $value): string
    {
        return substr(hash('sha256', $value), 0, 32);
    }
}
