<?php

declare(strict_types=1);

namespace App\Shared\ShareTracking;

use Illuminate\Http\Request;

/** Records the affiliate/share signup fact for a newly created account. */
interface SignupRecorder
{
    public function recordSignup(string $accountId, ?Request $request = null): void;
}
