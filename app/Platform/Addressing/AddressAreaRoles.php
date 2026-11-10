<?php

declare(strict_types=1);

namespace App\Platform\Addressing;

use AIArmada\Addressing\Contracts\CountryAddressProfile;
use AIArmada\Addressing\Support\CountryAddressProfileResolver;

/**
 * Country-profile area roles exposed to public location pickers.
 */
final class AddressAreaRoles
{
    /** @var list<string>|null */
    private static ?array $entryRoles = null;

    /**
     * @return list<string>
     */
    public static function entryRoles(): array
    {
        if (self::$entryRoles !== null) {
            return self::$entryRoles;
        }

        $roles = [];

        foreach (config('addressing.geography.providers', []) as $providerClass) {
            if (! is_string($providerClass)) {
                continue;
            }

            $provider = app($providerClass);

            if (! $provider instanceof CountryAddressProfile) {
                continue;
            }

            foreach ($provider->addressHierarchies() as $hierarchy) {
                foreach ($hierarchy->levels as $level) {
                    if ($level->kind === 'state') {
                        continue;
                    }

                    $roles[] = CountryAddressProfileResolver::roleForLevel($hierarchy, $level);
                }
            }
        }

        return self::$entryRoles = array_values(array_unique($roles));
    }

    public static function cascadeResetGuard(): int
    {
        return 2;
    }
}
