<?php

declare(strict_types=1);

namespace App\Shared\Feedback;

use App\Shared\Auth\AccountActor;

/**
 * Account actor that may submit application or directory feedback.
 *
 * Feedback eligibility is a Feedback capability concern; it is deliberately
 * segregated from the general identity contract.
 */
interface FeedbackActor extends AccountActor
{
    public function canSubmitAppFeedback(): bool;

    public function canSubmitDirectoryFeedback(): bool;

    public function directoryFeedbackBanMessage(): string;
}
