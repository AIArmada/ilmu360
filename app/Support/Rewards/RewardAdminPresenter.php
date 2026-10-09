<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Enums\Rewards\RewardAppealStatus;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Enums\Rewards\RewardVerificationLevel;
use App\Models\User;
use BackedEnum;
use Illuminate\Support\Facades\Gate;

/**
 * Staff admin labels, colors, options, and decision gating for rewards.
 */
final class RewardAdminPresenter
{
    /**
     * @return array<string, string>
     */
    public static function programStatusOptions(): array
    {
        return [
            RewardProgramStatus::Draft->value => 'Draft',
            RewardProgramStatus::Ready->value => 'Ready',
            RewardProgramStatus::Active->value => 'Active',
            RewardProgramStatus::Paused->value => 'Paused',
            RewardProgramStatus::Closed->value => 'Closed',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function programModeOptions(): array
    {
        return [
            RewardProgramMode::Recognition->value => 'Recognition',
            RewardProgramMode::Points->value => 'Points',
            RewardProgramMode::Badges->value => 'Badges',
            RewardProgramMode::Cash->value => 'Cash',
            RewardProgramMode::Benefit->value => 'Benefit',
            RewardProgramMode::Combined->value => 'Combined',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function awardStatusOptions(): array
    {
        return [
            RewardAwardStatus::Held->value => 'Held',
            RewardAwardStatus::PendingReview->value => 'Pending review',
            RewardAwardStatus::Approved->value => 'Approved',
            RewardAwardStatus::Rejected->value => 'Rejected',
            RewardAwardStatus::Fulfilled->value => 'Fulfilled',
            RewardAwardStatus::Reversed->value => 'Reversed',
            RewardAwardStatus::Corrected->value => 'Corrected',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function componentOptions(): array
    {
        return [
            RewardComponent::Recognition->value => 'Recognition',
            RewardComponent::Points->value => 'Points',
            RewardComponent::Badge->value => 'Badge',
            RewardComponent::Cash->value => 'Cash',
            RewardComponent::Benefit->value => 'Benefit',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function appealStatusOptions(): array
    {
        return [
            RewardAppealStatus::Open->value => 'Open',
            RewardAppealStatus::InReview->value => 'In review',
            RewardAppealStatus::Upheld->value => 'Upheld',
            RewardAppealStatus::Overturned->value => 'Overturned',
            RewardAppealStatus::Closed->value => 'Closed',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function outcomeOptions(): array
    {
        $options = [];

        foreach (RewardEligibleOutcome::cases() as $outcome) {
            $options[$outcome->value] = $outcome->value;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function verificationOptions(): array
    {
        return [
            RewardVerificationLevel::Unverified->value => 'Unverified',
            RewardVerificationLevel::Accepted->value => 'Accepted',
            RewardVerificationLevel::StaffVerified->value => 'Staff verified',
            RewardVerificationLevel::ModeratedApproved->value => 'Moderation approved',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function currencyOptions(): array
    {
        $options = [];

        foreach (RewardRuleValidator::CURRENCIES as $currency) {
            $options[$currency] = $currency;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function capScopeOptions(): array
    {
        $options = [];

        foreach (RewardRuleValidator::CAP_SCOPES as $scope) {
            $options[$scope] = $scope;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function payeeMethodOptions(): array
    {
        return [
            'manual' => 'Manual / bank handoff',
            'bank_transfer' => 'Bank transfer',
            'check' => 'Check',
            'wire' => 'Wire',
            'stripe_connect' => 'Stripe Connect',
            'paypal' => 'PayPal',
        ];
    }

    public static function statusColor(mixed $state): string
    {
        $value = $state instanceof BackedEnum ? (string) $state->value : (string) $state;

        return match ($value) {
            'active', 'approved', 'fulfilled', 'granted' => 'success',
            'held', 'pending_review', 'open', 'in_review', 'ready' => 'warning',
            'rejected', 'reversed', 'cancelled' => 'danger',
            default => 'gray',
        };
    }

    public static function statusLabel(mixed $state): string
    {
        $value = $state instanceof BackedEnum ? (string) $state->value : (string) $state;

        return self::programStatusOptions()[$value]
            ?? self::awardStatusOptions()[$value]
            ?? self::appealStatusOptions()[$value]
            ?? ucfirst(str_replace('_', ' ', $value));
    }

    public static function staffActor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole(['super_admin', 'admin']) ? $user : null;
    }

    public static function staffRole(): string
    {
        $user = auth()->user();

        if ($user instanceof User && $user->hasRole('super_admin')) {
            return 'admin';
        }

        return 'staff';
    }

    public static function canDecideAward(mixed $award, string $ability): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return Gate::forUser($user)->allows($ability, $award);
    }

    public static function recipientLabel(string $recipientType, string $recipientId): string
    {
        // Deliberately uncached: admin tables are paginated (bounded rows)
        // and static caches leak across Octane requests.
        if ($recipientType === 'user') {
            $email = User::query()->whereKey($recipientId)->value('email');

            if (is_string($email) && $email !== '') {
                return $email;
            }
        }

        return $recipientId;
    }
}
