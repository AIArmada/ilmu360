<?php

namespace App\Actions\Reports;

use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveReporterFingerprintAction
{
    use AsAction;

    public function handle(Request $request): string
    {
        $userId = $request->user()?->getAuthIdentifier();

        if (is_string($userId) && $userId !== '') {
            return 'user:'.$userId;
        }

        $ipAddress = (string) ($request->ip() ?? 'unknown-ip');
        $userAgent = trim((string) ($request->userAgent() ?? 'unknown-agent'));

        return 'guest:'.hash('sha256', "{$ipAddress}|{$userAgent}");
    }
}
