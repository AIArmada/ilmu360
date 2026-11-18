<?php

declare(strict_types=1);

namespace App\Platform\Api\Admin;

use App\Platform\Api\Admin\Contracts\AdminMutationHooks;

final class AdminMutationHookRegistry
{
    /** @var list<AdminMutationHooks> */
    private array $hooks = [];

    public function register(AdminMutationHooks $hooks): void
    {
        if (! in_array($hooks, $this->hooks, true)) {
            $this->hooks[] = $hooks;
        }
    }

    /** @return list<AdminMutationHooks> */
    public function all(): array
    {
        return $this->hooks;
    }

    /** @param class-string $resourceClass */
    public function for(string $resourceClass): ?AdminMutationHooks
    {
        foreach ($this->hooks as $hooks) {
            if ($hooks->supports($resourceClass)) {
                return $hooks;
            }
        }

        return null;
    }
}
