<?php

declare(strict_types=1);

namespace App\Features\GitHubIssues\Api;

interface IntegrationFeedbackUser
{
    public function canSubmitIntegrationFeedback(): bool;

    public function integrationFeedbackBanMessage(): string;

    public function integrationFeedbackIdentity(): IntegrationFeedbackIdentity;
}
