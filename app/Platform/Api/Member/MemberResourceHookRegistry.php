<?php

declare(strict_types=1);

namespace App\Platform\Api\Member;

use App\Platform\Api\Member\Contracts\MemberResourceHooks;

final class MemberResourceHookRegistry
{
    /** @var list<MemberResourceHooks> */
    private array $hooks = [];

    public function register(MemberResourceHooks $hooks): void
    {
        if (! in_array($hooks, $this->hooks, true)) {
            $this->hooks[] = $hooks;
        }
    }

    /** @return list<MemberResourceHooks> */
    public function all(): array
    {
        return $this->hooks;
    }

    /** @param class-string $resourceClass */
    public function for(string $resourceClass): ?MemberResourceHooks
    {
        foreach ($this->hooks as $hooks) {
            if ($hooks->supports($resourceClass)) {
                return $hooks;
            }
        }

        return null;
    }
}
