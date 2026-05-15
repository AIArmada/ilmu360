<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Data\GitHub\GitHubIssueSubmissionData;
use App\Models\User;
use App\Support\GitHub\GitHubIssueReportContract;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreGitHubIssueReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('sanctum');

        return $user instanceof User && $user->canSubmitIntegrationFeedback();
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
            $user instanceof User ? $user->integrationFeedbackBanMessage() : 'This action is unauthorized.',
        );
    }

    public function submissionData(): GitHubIssueSubmissionData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return GitHubIssueSubmissionData::fromValidated($validated);
    }

    public function actor(): User
    {
        $user = $this->user('sanctum');

        if (! $user instanceof User) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $user;
    }
}
