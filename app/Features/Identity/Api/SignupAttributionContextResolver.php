<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

/** Resolves untrusted signup attribution context from the current request. */
interface SignupAttributionContextResolver
{
    public function resolve(): SignupAttributionContext;
}
