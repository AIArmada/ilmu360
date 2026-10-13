<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\States\Active;
use AIArmada\Rewards\Actions\CreateRewardProgramAction;
use App\Support\ShareTracking\ShareTrackingProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Purpose-marked recipient reward affiliate resolver.
 *
 * Decision: a dedicated NEW affiliate row per recipient (never the guest
 * source row, never a commercial row). The row carries purpose
 * `share_reward_recipient`, which the package's neutral purpose seam
 * excludes from commercial bonuses/ranks/uplines/downlines/sweeps and
 * implicit payouts. Identity is the stable code
 * `reward-recipient:{recipient_type}:{recipient_id}`; a racing creator
 * replays the committed row.
 *
 * The recipient row is Active so fixed-amount award postings (entitlements,
 * not commissions) can settle; commercial earning behavior is preserved
 * for genuine unmarked rows only.
 */
final class RewardRecipientAffiliateResolver
{
    public static function codeFor(string $recipientType, string $recipientId): string
    {
        $recipientType = mb_trim($recipientType);
        $recipientId = mb_trim($recipientId);

        if ($recipientType === '' || $recipientId === '') {
            throw new InvalidArgumentException('Recipient type and id must be non-empty.');
        }

        $code = "reward-recipient:{$recipientType}:{$recipientId}";

        if (mb_strlen($code) > 191) {
            throw new InvalidArgumentException('Recipient identity exceeds the affiliate code bound.');
        }

        return $code;
    }

    public static function find(string $recipientType, string $recipientId): ?Affiliate
    {
        return Affiliate::query()
            ->where('code', self::codeFor($recipientType, $recipientId))
            ->where('purpose', ShareTrackingProfile::PURPOSE_SHARE_REWARD_RECIPIENT)
            ->first();
    }

    public static function resolve(string $recipientType, string $recipientId): Affiliate
    {
        $code = self::codeFor($recipientType, $recipientId);

        $existing = Affiliate::query()
            ->where('code', $code)
            ->first();

        if ($existing instanceof Affiliate) {
            if ((string) $existing->purpose !== ShareTrackingProfile::PURPOSE_SHARE_REWARD_RECIPIENT) {
                throw new InvalidArgumentException('Recipient affiliate code is bound to a non-recipient row; refusing to reuse it.');
            }

            return $existing;
        }

        try {
            return DB::transaction(static fn (): Affiliate => Affiliate::query()->create([
                'code' => $code,
                'name' => "Reward recipient {$recipientType} {$recipientId}",
                'status' => Active::class,
                'purpose' => ShareTrackingProfile::PURPOSE_SHARE_REWARD_RECIPIENT,
                'metadata' => [
                    'recipient_type' => $recipientType,
                    'recipient_id' => $recipientId,
                ],
            ]), 1);
        } catch (QueryException $exception) {
            if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                throw $exception;
            }

            $raced = Affiliate::query()->where('code', $code)->first();

            if ($raced instanceof Affiliate
                && (string) $raced->purpose === ShareTrackingProfile::PURPOSE_SHARE_REWARD_RECIPIENT
            ) {
                return $raced;
            }

            throw new InvalidArgumentException('Recipient affiliate code raced with a non-recipient row; refusing to reuse it.', 0, $exception);
        }
    }
}
