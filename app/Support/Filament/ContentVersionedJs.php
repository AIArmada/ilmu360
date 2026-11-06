<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Filament\Support\Assets\Js;

class ContentVersionedJs extends Js
{
    public function getVersion(): string
    {
        $version = parent::getVersion();
        $path = $this->getPath();

        if ($this->isRemote() || $path === null || ! is_file($path)) {
            return $version;
        }

        return $version.'-'.hash_file('xxh128', $path);
    }
}
