<?php

declare(strict_types=1);

namespace App\Platform\Api\Admin;

use App\Platform\Api\Admin\Contracts\AdminResourceHooks;

final class AdminResourceHookRegistry
{
    /** @var list<AdminResourceHooks> */
    private array $hooks = [];

    public function register(AdminResourceHooks $hooks): void
    {
        if (! in_array($hooks, $this->hooks, true)) {
            $this->hooks[] = $hooks;
        }
    }

    /** @return list<AdminResourceHooks> */
    public function all(): array
    {
        return $this->hooks;
    }

    /** @param class-string $resourceClass */
    public function for(string $resourceClass): ?AdminResourceHooks
    {
        foreach ($this->hooks as $hooks) {
            if ($hooks->supports($resourceClass)) {
                return $hooks;
            }
        }

        return null;
    }
}
