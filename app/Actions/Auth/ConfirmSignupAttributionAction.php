<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use AIArmada\Referrals\Support\ReferralContactNormalizer;
use App\Features\Identity\Api\SignupAttributions;
use App\Features\Identity\Api\SignupAttributionSnapshot;
use App\Features\Identity\Api\SignupAttributionSourceType;
use App\Features\Identity\Api\SignupAttributionStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class ConfirmSignupAttributionAction
{
    use AsAction;

    public function __construct(
        private ReferralContactNormalizer $contactNormalizer,
        private SignupAttributions $signupAttributions,
    ) {}

    public function handle(User $user): ?SignupAttributionSnapshot
    {
        return DB::transaction(function () use ($user): ?SignupAttributionSnapshot {
            $currentUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $currentUser instanceof User) {
                return null;
            }

            $attribution = $this->signupAttributions->lockForAccount((string) $currentUser->getKey());

            if (! $attribution instanceof SignupAttributionSnapshot
                || $attribution->sourceType !== SignupAttributionSourceType::ReferralInvite
                || $attribution->status !== SignupAttributionStatus::Pending) {
                return $attribution;
            }

            foreach (['email', 'phone'] as $field) {
                $contact = $currentUser->getAttribute($field);

                if ($currentUser->getAttribute($field.'_verified_at') === null || ! is_string($contact) || trim($contact) === '') {
                    continue;
                }

                try {
                    $matches = is_string($attribution->inviteeContactHash)
                        && hash_equals($attribution->inviteeContactHash, $this->contactNormalizer->hash($contact));
                } catch (InvalidArgumentException) {
                    continue;
                }

                if ($matches) {
                    return $this->signupAttributions->confirm($attribution->id) ?? $attribution;
                }
            }

            return $attribution;
        }, 3);
    }
}
