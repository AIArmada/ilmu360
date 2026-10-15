<?php

declare(strict_types=1);

namespace App\Jobs\Referrals;

use App\Actions\Referrals\QualifyReferralFromShareOutcomeAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class QualifyReferralFromShareOutcomeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $businessKey) {}

    public function handle(QualifyReferralFromShareOutcomeAction $action): void
    {
        try {
            $action->handle($this->businessKey);
        } catch (Throwable $exception) {
            Log::warning('Referral qualification job failed without affecting the committed source outcome.', [
                'business_key' => $this->businessKey,
                'exception' => $exception,
            ]);
        }
    }
}
