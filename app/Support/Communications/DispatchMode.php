<?php

namespace App\Support\Communications;

class DispatchMode
{
    public static function usePackage(): bool
    {
        return (bool) config('communications.features.dispatch_through_package', false);
    }

    public static function useLegacy(): bool
    {
        return ! self::usePackage();
    }

    public static function legacyOnly(): bool
    {
        return ! self::usePackage() && ! (bool) config('communications.features.auto_capture', false);
    }
}
