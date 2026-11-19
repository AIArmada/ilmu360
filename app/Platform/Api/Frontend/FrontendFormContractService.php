<?php

declare(strict_types=1);

namespace App\Platform\Api\Frontend;

use App\Models\User;
use BackedEnum;

/**
 * Thin dispatcher over owner form-contract contributors.
 *
 * The public method surface is stable for HTTP controllers and clients; all
 * payload ownership lives with the registered contributors.
 */
class FrontendFormContractService
{
    public function __construct(
        private readonly FrontendFormContractRegistry $registry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function manifest(?User $user): array
    {
        return $this->registry->manifest($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function submitEvent(?User $user): array
    {
        return $this->registry->contract(FrontendFormContractKey::SUBMIT_EVENT, ['user' => $user]);
    }

    /**
     * @return array<string, mixed>
     */
    public function submitInstitution(): array
    {
        return $this->registry->contract(FrontendFormContractKey::SUBMIT_INSTITUTION);
    }

    /**
     * @return array<string, mixed>
     */
    public function submitPerson(): array
    {
        return $this->registry->contract(FrontendFormContractKey::SUBMIT_PERSON);
    }

    /**
     * @return array<string, mixed>
     */
    public function mobileTelemetry(): array
    {
        return $this->registry->contract(FrontendFormContractKey::MOBILE_TELEMETRY);
    }

    /**
     * @return array<string, mixed>
     */
    public function membershipApplication(BackedEnum $subjectType): array
    {
        return $this->registry->contract(FrontendFormContractKey::MEMBERSHIP_APPLICATION, [
            'subjectType' => $subjectType,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return $this->registry->contract(FrontendFormContractKey::REPORT);
    }

    public function supportsGitHubIssueReporting(): bool
    {
        return $this->registry->supports(FrontendFormContractKey::GITHUB_ISSUE_REPORTING);
    }

    /**
     * @return array<string, mixed>
     */
    public function githubIssueReport(): array
    {
        return $this->registry->contract(FrontendFormContractKey::GITHUB_ISSUE_REPORT);
    }

    /**
     * @return array<string, mixed>
     */
    public function accountSettings(User $user): array
    {
        return $this->registry->contract(FrontendFormContractKey::ACCOUNT_SETTINGS, ['user' => $user]);
    }

    /**
     * @return array<string, mixed>
     */
    public function advancedEvent(User $user): array
    {
        return $this->registry->contract(FrontendFormContractKey::ADVANCED_EVENT, ['user' => $user]);
    }

    /**
     * @return array<string, mixed>
     */
    public function institutionWorkspace(): array
    {
        return $this->registry->contract(FrontendFormContractKey::INSTITUTION_WORKSPACE);
    }
}
