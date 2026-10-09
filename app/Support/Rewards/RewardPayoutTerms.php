<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Affiliates\Contracts\PayoutProcessorInterface;
use AIArmada\Affiliates\Services\Payouts\PayoutProcessorFactory;
use InvalidArgumentException;

/**
 * Frozen per-operation payout terms: exact net/gross math and the
 * validated provider destination, both derived from the INSTALLED
 * processor contracts — never reimplemented fee schedules or
 * invented credential keys.
 *
 * Program-pays-fees means the member receives the awarded amount to the
 * minor unit: the backing is grossed up so gross minus the installed
 * fee schedule equals the promised net exactly. When no exact gross
 * exists the request is refused instead of silently underpaying.
 *
 * The payee account_ref is the single destination handle (PayPal email,
 * Stripe account id, operator handle for manual rails). Its SHAPE is
 * always validated by the installed processor's own validateDetails;
 * this class only maps the ref onto the installed details keys.
 */
final class RewardPayoutTerms
{
    /**
     * Least gross backing whose installed fee leaves exactly the promised
     * net. Solved against the LIVE installed schedule by fixed-point
     * iteration from below, so the reserve is minimal and exact.
     *
     * @return array{gross_minor: int, fee_minor: int}
     */
    public static function grossForNet(PayoutProcessorInterface $processor, int $netMinor, string $currency): array
    {
        if ($netMinor <= 0) {
            throw new InvalidArgumentException('Payout net must be a positive minor-unit amount.');
        }

        $gross = $netMinor + $processor->getFees($netMinor, $currency);

        for ($i = 0; $i < 100; $i++) {
            $fee = $processor->getFees($gross, $currency);

            if ($fee < 0) {
                throw new InvalidArgumentException('Processor fee must be non-negative.');
            }

            if ($gross - $fee === $netMinor) {
                return ['gross_minor' => $gross, 'fee_minor' => $fee];
            }

            // Installed schedules are non-decreasing with slope below 1,
            // so net + fee(gross) walks monotonically onto the least
            // exact gross. Anything else throws below instead of
            // promising a net the provider will not deliver.
            $gross = $netMinor + $fee;
        }

        throw new InvalidArgumentException('No exact program-paid gross exists for this net under the installed fee schedule.');
    }

    /**
     * Installed provider-destination details for one frozen payee ref.
     * Manual/operator rails carry no provider destination: the frozen
     * snapshot stays the operator's reconciliation authority.
     *
     * @param  array<string, mixed>  $audit  audit-only extras (payee id, display, operation key).
     * @return array<string, mixed>
     */
    public static function detailsFor(string $processor, string $accountRef, array $audit = []): array
    {
        return match ($processor) {
            'paypal' => [...$audit, 'email' => $accountRef],
            'stripe_connect' => [...$audit, 'stripe_account_id' => $accountRef],
            default => $audit,
        };
    }

    /**
     * The installed destination handle inside stored method details, or
     * null for operator rails that carry none.
     *
     * @param  array<string, mixed>  $details
     */
    public static function destinationRefFor(string $processor, array $details): ?string
    {
        $key = match ($processor) {
            'paypal' => 'email',
            'stripe_connect' => 'stripe_account_id',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $ref = $details[$key] ?? null;

        return is_string($ref) ? $ref : null;
    }

    /**
     * Validity always comes from the installed processor's own
     * validateDetails contract — never a local reimplementation.
     *
     * @param  array<string, mixed>  $details
     */
    public static function assertValidDestination(string $processor, array $details): void
    {
        $errors = app(PayoutProcessorFactory::class)->make($processor)->validateDetails($details);

        if ($errors === []) {
            return;
        }

        $messages = [];

        foreach ($errors as $field => $message) {
            $messages[] = is_string($field) ? "{$field}: {$message}" : (string) $message;
        }

        throw new InvalidArgumentException(
            "Payout destination refused by [{$processor}]: ".implode('; ', $messages)
        );
    }
}
