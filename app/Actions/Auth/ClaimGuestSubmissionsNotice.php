<?php

declare(strict_types=1);

namespace App\Actions\Auth;

/**
 * Presents the outcome of the Events guest-submission claim to the visitor.
 */
final class ClaimGuestSubmissionsNotice
{
    public static function flash(int $claimed): void
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
