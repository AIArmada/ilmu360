<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use LogicException;

/** Guard canonical immutable signup sources from ad hoc updates. */
final readonly class AssertSignupAttributionImmutabilityAction
{
    public function updating(): never
    {
        throw new LogicException('Signup sources are immutable. Use the signup confirmation or guest linking action.');
    }
}
