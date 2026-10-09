<?php

declare(strict_types=1);

namespace App\Support\Rewards;

/**
 * Canonical material-terms hashing. The version fingerprint covers the full
 * authoritative policy rules plus the declared material clauses; cosmetic
 * body/label/version_label edits do not change the hash, while any policy or
 * material clause change does — driving prospective reacceptance. Callers
 * cannot mask a rule change by leaving the material map unchanged.
 */
final class RewardMaterialTerms
{
    /**
     * @param  array<string, mixed>  $material
     */
    public static function hash(array $material): string
    {
        return hash('sha256', (string) json_encode(
            self::canonicalize($material),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $terms
     */
    public static function hashForVersion(array $rules, array $terms): string
    {
        $material = $terms['material'] ?? [];

        return self::hash([
            'rules' => $rules,
            'material' => is_array($material) ? $material : [],
        ]);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            foreach ($value as $key => $entry) {
                $value[$key] = self::canonicalize($entry);
            }
        }

        return $value;
    }
}
