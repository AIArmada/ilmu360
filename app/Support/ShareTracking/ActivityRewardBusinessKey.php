<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use App\Enums\DawahShareOutcomeType;
use InvalidArgumentException;

/**
 * Canonical identity for actor-only reward facts.
 *
 * Source prefixes and UUID validation are shared with share outcome keys;
 * the namespace remains distinct so each reader fails closed on the other.
 */
final class ActivityRewardBusinessKey
{
    public const string NAMESPACE = 'activity';

    /**
     * @return array{namespace: string, outcome_type: string, source_transition_id: string}
     */
    public static function parse(string $businessKey): array
    {
        $businessKey = trim($businessKey);

        if ($businessKey === '' || mb_strlen($businessKey) > 191 || str_contains($businessKey, "\0")) {
            throw new InvalidArgumentException('Activity reward business key must be a non-empty bounded identity of at most 191 characters.');
        }

        $parts = explode(':', $businessKey, 3);

        if (count($parts) !== 3 || $parts[0] !== self::NAMESPACE) {
            throw new InvalidArgumentException('Activity reward business key must use the canonical activity namespace.');
        }

        $type = DawahShareOutcomeType::tryFrom($parts[1]);

        if (! $type instanceof DawahShareOutcomeType) {
            throw new InvalidArgumentException('Activity reward business key must name a known outcome type.');
        }

        $sourceTransitionId = trim($parts[2]);

        if ($sourceTransitionId === '' || mb_strlen($sourceTransitionId) > 120 || str_contains($sourceTransitionId, "\0")) {
            throw new InvalidArgumentException('Activity reward source transition ID must be a non-empty bounded identity of at most 120 characters.');
        }

        ShareOutcomeBusinessKey::assertCanonicalSourceTransition($type, $sourceTransitionId);

        return [
            'namespace' => self::NAMESPACE,
            'outcome_type' => $type->value,
            'source_transition_id' => $sourceTransitionId,
        ];
    }

    public static function for(DawahShareOutcomeType $type, string $sourceTransitionId): string
    {
        $sourceTransitionId = trim($sourceTransitionId);

        if ($sourceTransitionId === '' || mb_strlen($sourceTransitionId) > 120 || str_contains($sourceTransitionId, "\0")) {
            throw new InvalidArgumentException('Activity reward source transition ID must be a non-empty bounded identity of at most 120 characters.');
        }

        ShareOutcomeBusinessKey::assertCanonicalSourceTransition($type, $sourceTransitionId);

        $key = self::NAMESPACE.':'.$type->value.':'.$sourceTransitionId;

        if (mb_strlen($key) > 191) {
            throw new InvalidArgumentException('Activity reward business key exceeds 191 characters.');
        }

        return $key;
    }
}
