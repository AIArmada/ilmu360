<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Submission;

use AIArmada\Contacting\Enums\ContactMethodType;
use App\Features\Events\Api\GuestSubmissionClaims;
use App\Features\Events\Support\EventModel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Events-owned claim of unowned guest submissions by verified email.
 *
 * Authentication code calls the GuestSubmissionClaims boundary instead of
 * writing event-submission or event attribution state itself.
 */
final class ClaimGuestSubmissionsAction implements GuestSubmissionClaims
{
    use AsAction;

    public function claimForAccount(string $accountId): int
    {
        $user = EventModel::query('user')->find($accountId);

        return $user instanceof Model ? $this->handle($user) : 0;
    }

    /**
     * Link guest event submissions to the user once their email is verified.
     *
     * Only submissions nobody owns yet (null submitter) whose submitter email
     * contact matches the user's verified address are claimed. Returns the
     * number of submissions linked.
     */
    public function handle(Model $user): int
    {
        if (! $user instanceof MustVerifyEmail) {
            return 0;
        }

        $email = $user->getAttribute('email');

        if (! is_string($email) || trim($email) === '' || ! $user->hasVerifiedEmail()) {
            return 0;
        }

        $submissions = EventModel::query('event_submission')
            ->whereNull('submitter_id')
            ->whereHas('contactMethods', function (Builder $query) use ($email): void {
                $value = $query->getQuery()->getGrammar()->wrap('value');

                $query->where('type', ContactMethodType::Email->value)
                    ->whereRaw('LOWER('.$value.') = ?', [mb_strtolower($email)]);
            })
            ->with('event')
            ->get();

        if ($submissions->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($submissions, $user): void {
            foreach ($submissions as $submission) {
                $submission->forceFill([
                    'submitter_type' => $user->getMorphClass(),
                    'submitter_id' => $user->getKey(),
                ])->save();

                $event = $submission->event;

                if ($event instanceof Model && $event->getAttribute('created_by_id') === null) {
                    $event->forceFill([
                        'created_by_type' => $user->getMorphClass(),
                        'created_by_id' => $user->getKey(),
                    ])->save();
                }
            }
        });

        return $submissions->count();
    }
}
