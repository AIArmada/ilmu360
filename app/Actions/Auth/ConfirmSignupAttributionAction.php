<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use AIArmada\Referrals\Support\ReferralContactNormalizer;
use App\Enums\SignupAttributionSourceType;
use App\Enums\SignupAttributionStatus;
use App\Models\SignupAttribution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class ConfirmSignupAttributionAction
{
    use AsAction;

    public function __construct(private ReferralContactNormalizer $contactNormalizer) {}

    public function handle(User $user): ?SignupAttribution
    {
        return DB::transaction(function () use ($user): ?SignupAttribution {
            $currentUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $currentUser instanceof User) {
                return null;
            }

            $attribution = $currentUser->signupAttribution()->lockForUpdate()->first();

            if (! $attribution instanceof SignupAttribution
                || $attribution->source_type !== SignupAttributionSourceType::ReferralInvite
                || $attribution->status !== SignupAttributionStatus::Pending) {
                return $attribution;
            }

            foreach (['email', 'phone'] as $field) {
                $contact = $currentUser->getAttribute($field);

                if ($currentUser->getAttribute($field.'_verified_at') === null || ! is_string($contact) || trim($contact) === '') {
                    continue;
                }

                try {
                    $matches = is_string($attribution->invitee_contact_hash)
                        && hash_equals($attribution->invitee_contact_hash, $this->contactNormalizer->hash($contact));
                } catch (InvalidArgumentException) {
                    continue;
                }

                if ($matches) {
                    SignupAttribution::query()->whereKey($attribution->getKey())
                        ->where('status', SignupAttributionStatus::Pending->value)
                        ->update([
                            'status' => SignupAttributionStatus::Confirmed->value,
                            'confirmed_at' => now(),
                            'updated_at' => now(),
                        ]);

                    return $attribution->refresh();
                }
            }

            return $attribution;
        }, 3);
    }
}
