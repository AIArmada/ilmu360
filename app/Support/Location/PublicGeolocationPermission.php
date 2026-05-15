<?php

declare(strict_types=1);

namespace App\Support\Location;

use Illuminate\Http\Request;

class PublicGeolocationPermission
{
    public const COOKIE_NAME = 'public_geolocation_permission';

    public function isGranted(?Request $request = null): bool
    {
        $request ??= request();

        return filter_var(
            $request->cookie(self::COOKIE_NAME, '0'),
            FILTER_VALIDATE_BOOL
        );
    }
}
