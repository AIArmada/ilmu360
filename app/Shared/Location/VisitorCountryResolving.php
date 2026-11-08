<?php

declare(strict_types=1);

namespace App\Shared\Location;

use Illuminate\Http\Request;

interface VisitorCountryResolving
{
    /**
     * The country UUID to scope address filters to, or null when no country
     * could be resolved.
     */
    public function resolve(?Request $request = null): ?string;

    /**
     * The ISO-3166-1 alpha-2 code, or null when unresolved.
     */
    public function resolveCode(?Request $request = null): ?string;
}
