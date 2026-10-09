<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Affiliates\Services\Payouts\PayoutProcessorFactory;
use App\Enums\Rewards\RewardPayeeStatus;
use App\Models\RewardPayee;
use App\Support\Rewards\RewardLedgerEntries;
use App\Support\Rewards\RewardPayoutTerms;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Member payee registration: the recipient submits a payout destination
 * for staff verification. The row lands Pending and never activates
 * itself; any prior Pending registration for the same recipient + method
 * is superseded, while a live Verified destination stays in force until
 * staff verify the replacement.
 */
final readonly class RegisterRewardPayeeAction
{
    use AsAction;

    public function handle(
        string $recipientType,
        string $recipientId,
        string $method,
        string $accountRef,
        string $accountDisplay,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?CarbonInterface $decidedAt = null,
    ): RewardPayee {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        $recipientType = mb_trim($recipientType);
        $recipientId = mb_trim($recipientId);
        $method = mb_trim($method);
        $accountRef = mb_trim($accountRef);
        $accountDisplay = mb_trim($accountDisplay);

        if ($recipientType === '' || $recipientId === '') {
            throw new InvalidArgumentException('Recipient type and id must be non-empty.');
        }

        if ($actorType !== 'user' || $actorId !== $recipientId) {
            throw new InvalidArgumentException('Payee registration is only for the recipient themselves.');
        }

        if ($method === '' || mb_strlen($method) > 32) {
            throw new InvalidArgumentException('Payee method must be 1..32 characters.');
        }

        if ($accountRef === '' || mb_strlen($accountRef) > 191) {
            throw new InvalidArgumentException('Payee account ref must be 1..191 characters.');
        }

        if ($accountDisplay === '' || mb_strlen($accountDisplay) > 191) {
            throw new InvalidArgumentException('Payee account display must be 1..191 characters.');
        }

        if (app(PayoutProcessorFactory::class)->hasProcessor($method)) {
            RewardPayoutTerms::assertValidDestination(
                $method,
                RewardPayoutTerms::detailsFor($method, $accountRef)
            );
        }

        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use (
            $recipientType, $recipientId, $method, $accountRef, $accountDisplay, $decided,
        ): RewardPayee {
            $pending = RewardPayee::query()
                ->where('recipient_type', $recipientType)
                ->where('recipient_id', $recipientId)
                ->where('method', $method)
                ->where('status', RewardPayeeStatus::Pending->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($pending as $row) {
                $row->forceFill([
                    'status' => RewardPayeeStatus::Superseded,
                    'superseded_at' => $decided,
                ])->save();
            }

            return RewardPayee::query()->create([
                'recipient_type' => $recipientType,
                'recipient_id' => $recipientId,
                'method' => $method,
                'account_ref' => $accountRef,
                'account_display' => $accountDisplay,
                'status' => RewardPayeeStatus::Pending,
            ]);
        });
    }
}
