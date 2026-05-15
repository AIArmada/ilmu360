<?php

namespace App\Services\Notifications;

use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use App\Models\ContributionRequest;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Notifications\NotificationDispatchData;

class ContributionRequestNotificationService
{
    public function __construct(
        protected NotificationEngine $engine,
        protected NotificationMessageRenderer $messageRenderer,
    ) {}

    public function notifyCreateRequestApproved(ContributionRequest $request): void
    {
        if (! $this->supportsCreateSubmissionNotifications($request)) {
            return;
        }

        $proposer = $request->proposer;

        if (! $proposer instanceof User) {
            return;
        }

        $this->dispatchToProposer(
            user: $proposer,
            request: $request,
            trigger: NotificationTrigger::SubmissionApproved,
            titleKey: 'notifications.messages.directory_submission_approved.title',
            titleParams: [
                'subject' => $this->subjectLabel($request),
                'title' => $this->subjectTitle($request),
            ],
            bodyKey: 'notifications.messages.directory_submission_approved.body',
            actionUrl: $this->approvedActionUrl($request),
            priority: NotificationPriority::High,
            fingerprint: 'contribution-request-approved:'.$request->getKey(),
        );
    }

    public function notifyCreateRequestRejected(ContributionRequest $request): void
    {
        if (! $this->supportsCreateSubmissionNotifications($request)) {
            return;
        }

        $proposer = $request->proposer;

        if (! $proposer instanceof User) {
            return;
        }

        $note = is_string($request->reviewer_note) ? trim($request->reviewer_note) : '';
        $bodyKey = $note !== ''
            ? 'notifications.messages.directory_submission_rejected.body_with_note'
            : 'notifications.messages.directory_submission_rejected.body';

        $bodyParams = $note !== '' ? ['note' => $note] : [];

        $this->dispatchToProposer(
            user: $proposer,
            request: $request,
            trigger: NotificationTrigger::SubmissionRejected,
            titleKey: 'notifications.messages.directory_submission_rejected.title',
            titleParams: [
                'subject' => $this->subjectLabel($request),
                'title' => $this->subjectTitle($request),
            ],
            bodyKey: $bodyKey,
            bodyParams: $bodyParams,
            actionUrl: route('contributions.index'),
            priority: NotificationPriority::High,
            fingerprint: 'contribution-request-rejected:'.$request->getKey().':'.sha1($note),
        );
    }

    /**
     * @param  array<string, mixed>  $titleParams
     * @param  array<string, mixed>  $bodyParams
     */
    private function dispatchToProposer(
        User $user,
        ContributionRequest $request,
        NotificationTrigger $trigger,
        string $titleKey,
        array $titleParams,
        string $bodyKey,
        array $bodyParams = [],
        ?string $actionUrl = null,
        NotificationPriority $priority = NotificationPriority::Medium,
        ?string $fingerprint = null,
    ): void {
        $data = $this->withUserLocale($user, function () use (
            $user,
            $request,
            $trigger,
            $titleKey,
            $titleParams,
            $bodyKey,
            $bodyParams,
            $actionUrl,
            $priority,
            $fingerprint,
        ): NotificationDispatchData {
            $render = [
                'title' => [
                    'key' => $titleKey,
                    'params' => $titleParams,
                ],
                'body' => [
                    'key' => $bodyKey,
                    'params' => $bodyParams,
                ],
            ];

            $entity = $request->entity;

            return new NotificationDispatchData(
                trigger: $trigger,
                title: $this->messageRenderer->renderDefinition($render['title'], $user),
                body: $this->messageRenderer->renderDefinition($render['body'], $user),
                actionUrl: $actionUrl,
                entityType: $entity?->getMorphClass(),
                entityId: $entity?->getKey(),
                priority: $priority,
                fingerprint: $fingerprint,
                meta: [
                    'contribution_request_id' => $request->getKey(),
                    'subject_type' => $this->normalizedSubjectType($request)?->value,
                    'subject_title' => $this->subjectTitle($request),
                ],
                render: $render,
            );
        });

        $this->engine->dispatchToUser($user, $data);
    }

    private function supportsCreateSubmissionNotifications(ContributionRequest $request): bool
    {
        $subjectType = $this->normalizedSubjectType($request);

        return $request->type === ContributionRequestType::Create
            && in_array($subjectType, [ContributionSubjectType::Institution, ContributionSubjectType::Speaker], true);
    }

    private function normalizedSubjectType(ContributionRequest $request): ?ContributionSubjectType
    {
        $subjectType = $request->subject_type;

        if ($subjectType instanceof ContributionSubjectType) {
            return $subjectType;
        }

        if (! is_string($subjectType)) {
            return null;
        }

        return ContributionSubjectType::tryFrom($subjectType);
    }

    private function subjectLabel(ContributionRequest $request): string
    {
        return match ($this->normalizedSubjectType($request)) {
            ContributionSubjectType::Institution => __('Institution'),
            ContributionSubjectType::Speaker => __('Speaker'),
            default => __('Submission'),
        };
    }

    private function subjectTitle(ContributionRequest $request): string
    {
        $entity = $request->entity;

        return match (true) {
            $entity instanceof Institution => $entity->name,
            $entity instanceof Speaker => $entity->name,
            is_string(data_get($request->proposed_data, 'name')) && trim(data_get($request->proposed_data, 'name')) !== '' => trim(data_get($request->proposed_data, 'name')),
            default => $this->subjectLabel($request),
        };
    }

    private function approvedActionUrl(ContributionRequest $request): string
    {
        $entity = $request->entity;

        return match (true) {
            $entity instanceof Institution => route('institutions.show', $entity),
            $entity instanceof Speaker => route('speakers.show', $entity),
            default => route('contributions.index'),
        };
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withUserLocale(User $user, callable $callback): mixed
    {
        $originalLocale = app()->getLocale();
        app()->setLocale($user->preferredLocale());

        try {
            return $callback();
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
