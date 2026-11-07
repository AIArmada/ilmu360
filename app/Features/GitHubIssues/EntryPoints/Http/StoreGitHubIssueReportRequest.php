<?php

declare(strict_types=1);

namespace App\Features\GitHubIssues\EntryPoints\Http;

use App\Features\GitHubIssues\Api\GitHubIssueReportContract;
use App\Features\GitHubIssues\Api\GitHubIssueSubmissionData;
use App\Shared\Auth\IntegrationFeedbackIdentity;
use App\Shared\Auth\IntegrationFeedbackUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreGitHubIssueReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('sanctum');

        return $user instanceof IntegrationFeedbackUser && $user->canSubmitIntegrationFeedback();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return GitHubIssueReportContract::rules();
    }

    /**
     * @throws AuthorizationException
     */
    protected function failedAuthorization(): void
    {
        $user = $this->user('sanctum');

        throw new AuthorizationException(
            $user instanceof IntegrationFeedbackUser ? $user->integrationFeedbackBanMessage() : 'This action is unauthorized.',
        );
    }

    public function submissionData(): GitHubIssueSubmissionData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return GitHubIssueSubmissionData::fromValidated($validated);
    }

    public function actor(): IntegrationFeedbackIdentity
    {
        $user = $this->user('sanctum');

        if (! $user instanceof IntegrationFeedbackUser) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $user->integrationFeedbackIdentity();
    }
}
