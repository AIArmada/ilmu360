<?php

declare(strict_types=1);

namespace App\Shared\Auth;

interface AccountActor
{
    /**
     * Stable account identifier used for ownership and audit attribution.
     */
    public function actorId(): string;

    public function hasGlobalAdminAccess(): bool;

    /**
     * @param  array<int, string>|string  ...$roles
     */
    public function hasAnyRole(array|string ...$roles): bool;

    public function canSubmitAppFeedback(): bool;

    public function canSubmitDirectoryFeedback(): bool;

    public function directoryFeedbackBanMessage(): string;
}
