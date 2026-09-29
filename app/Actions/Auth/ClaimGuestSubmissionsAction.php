<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use AIArmada\Contacting\Enums\ContactMethodType;
use App\Models\EventSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class ClaimGuestSubmissionsAction
{
    use AsAction;

    /**
     * Link guest event submissions to the user once their email is verified.
     *
     * Only submissions nobody owns yet (null submitter) whose submitter email
     * contact matches the user's verified address are claimed. Returns the
     * number of submissions linked.
     */
    public function handle(User $user): int
    {
        $email = $user->email;

        if (! is_string($email) || trim($email) === '' || ! $user->hasVerifiedEmail()) {
            return 0;
        }

        $submissions = EventSubmission::query()
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

                if ($event !== null && $event->created_by_id === null) {
                    $event->forceFill([
                        'created_by_type' => $user->getMorphClass(),
                        'created_by_id' => $user->getKey(),
                    ])->save();
                }
            }
        });

        return $submissions->count();
    }

    /**
     * Flash the claimed-submissions notice when running in a web request.
     */
    public static function flashNotice(int $claimed): void
    {
        if ($claimed <= 0 || ! request()->hasSession()) {
            return;
        }

        session()->flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                'We linked :count past submission to your account.|We linked :count past submissions to your account.',
                $claimed
            ),
        ]);
    }
}
