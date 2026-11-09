<?php

declare(strict_types=1);

namespace App\Features\Identity\Services;

use App\Features\Identity\Actions\ConfirmSignupAttributionAction;
use App\Features\Identity\Actions\LinkGuestSignupAttributionsAction;
use App\Features\Identity\Actions\RecordSignupAttributionAction;
use App\Features\Identity\Api\RecordSignupAttributionInput;
use App\Features\Identity\Api\SignupAttributions;
use App\Features\Identity\Api\SignupAttributionSnapshot;
use App\Features\Identity\Api\SignupAttributionStatus;
use App\Features\Identity\Models\SignupAttribution;
use Illuminate\Database\Eloquent\Builder;

final readonly class SignupAttributionsService implements SignupAttributions
{
    public function __construct(
        private RecordSignupAttributionAction $recordSignupAttribution,
        private ConfirmSignupAttributionAction $confirmSignupAttribution,
        private LinkGuestSignupAttributionsAction $linkGuestSignupAttributions,
    ) {}

    public function findForAccount(string $accountId): ?SignupAttributionSnapshot
    {
        return $this->snapshot($this->queryForAccount($accountId)->first());
    }

    public function lockForAccount(string $accountId): ?SignupAttributionSnapshot
    {
        return $this->snapshot($this->queryForAccount($accountId)->lockForUpdate()->first());
    }

    public function record(RecordSignupAttributionInput $input): SignupAttributionSnapshot
    {
        return $this->recordSignupAttribution->handle($input);
    }

    public function confirm(string $attributionId): ?SignupAttributionSnapshot
    {
        return $this->confirmSignupAttribution->handle($attributionId);
    }

    public function linkGuestReferrals(string $affiliateId, string $referrerAccountId): int
    {
        return $this->linkGuestSignupAttributions->handle($affiliateId, $referrerAccountId);
    }

    public function confirmedReferralAccountIds(string $referrerAccountId): array
    {
        return SignupAttribution::query()
            ->where('referrer_user_id', $referrerAccountId)
            ->where('status', SignupAttributionStatus::Confirmed->value)
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return Builder<SignupAttribution>
     */
    private function queryForAccount(string $accountId): Builder
    {
        return SignupAttribution::query()->where('user_id', $accountId);
    }

    private function snapshot(?SignupAttribution $attribution): ?SignupAttributionSnapshot
    {
        return $attribution instanceof SignupAttribution
            ? SignupAttributionSnapshots::fromModel($attribution)
            : null;
    }
}
