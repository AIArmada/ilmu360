<?php

namespace App\Features\Contributions\Notifications;

use App\Features\Communications\Api\InAppNotificationPayload;
use App\Features\Communications\Api\NotificationCatalog;
use App\Features\Communications\Api\NotificationDispatch;
use App\Features\Communications\Api\NotificationDispatchData;
use App\Features\Communications\Api\NotificationMessageRendering;
use App\Features\Communications\Api\NotificationPriority;
use App\Features\Communications\Api\NotificationTrigger;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\EntryPoints\Ui\ContributionsHubPresenter;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Contributions\Support\ContributionRequestNotifier;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Models\User;
use Illuminate\Support\Str;

class ContributionRequestNotificationService implements ContributionRequestNotifier
{
    public function __construct(
        protected NotificationDispatch $notificationDispatch,
        protected NotificationMessageRendering $messageRenderer,
        protected ContributionsHubPresenter $hubPresenter,
    ) {}

    public function notifyRequestApproved(ContributionRequest $request): void
    {
        if (! $this->supportsSubmissionNotifications($request)) {
            return;
        }

        $proposer = $request->proposer;

        if (! $proposer instanceof User) {
            return;
        }

        $isUpdate = $request->type === ContributionRequestType::Update;

        $this->dispatchToProposer(
            user: $proposer,
            request: $request,
            trigger: NotificationTrigger::SubmissionApproved,
            titleKey: $isUpdate
                ? 'notifications.messages.directory_update_approved.title'
                : 'notifications.messages.directory_submission_approved.title',
            titleParams: [
                'subject' => $this->subjectLabel($request),
                'title' => $this->subjectTitle($request),
            ],
            bodyKey: $isUpdate
                ? 'notifications.messages.directory_update_approved.body'
                : 'notifications.messages.directory_submission_approved.body',
            actionUrl: $isUpdate ? $this->updateApprovedActionUrl($request) : $this->approvedActionUrl($request),
            priority: NotificationPriority::High,
            fingerprint: 'contribution-request-approved:'.$request->getKey(),
        );
    }

    public function notifyRequestRejected(ContributionRequest $request): void
    {
        if (! $this->supportsSubmissionNotifications($request)) {
            return;
        }

        $proposer = $request->proposer;

        if (! $proposer instanceof User) {
            return;
        }

        $isUpdate = $request->type === ContributionRequestType::Update;
        $bodyKeyPrefix = $isUpdate
            ? 'notifications.messages.directory_update_rejected'
            : 'notifications.messages.directory_submission_rejected';

        $note = is_string($request->reviewer_note) ? trim($request->reviewer_note) : '';
        $bodyKey = $note !== ''
            ? $bodyKeyPrefix.'.body_with_note'
            : $bodyKeyPrefix.'.body';

        $bodyParams = $note !== '' ? ['note' => $note] : [];

        $this->dispatchToProposer(
            user: $proposer,
            request: $request,
            trigger: NotificationTrigger::SubmissionRejected,
            titleKey: $bodyKeyPrefix.'.title',
            titleParams: [
                'subject' => $this->subjectLabel($request),
                'title' => $this->subjectTitle($request),
            ],
            bodyKey: $bodyKey,
            bodyParams: $bodyParams,
            actionUrl: route('contributions.index', ['lihat' => 'request:'.$request->getKey()]),
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
            $timezone = array_key_exists('timing', $titleParams) || array_key_exists('timing', $bodyParams)
                ? $user->preferredTimezone()
                : null;

            return new NotificationDispatchData(
                trigger: $trigger,
                title: $this->messageRenderer->renderDefinition($render['title'], $timezone),
                body: $this->messageRenderer->renderDefinition($render['body'], $timezone),
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

        $family = NotificationCatalog::triggerDefinition($trigger)['family'];

        $payload = new InAppNotificationPayload(
            pendingNotificationId: (string) Str::uuid(),
            family: $family,
            trigger: $trigger,
            priority: $priority,
            title: $data->title,
            body: $data->body,
            actionUrl: $actionUrl,
            entityType: $data->entityType,
            entityId: $data->entityId,
            occurredAt: $data->occurredAt?->toIso8601String(),
            meta: $data->meta,
        );

        $this->notificationDispatch->sendInAppToAccount($user, $payload);
    }

    private function supportsSubmissionNotifications(ContributionRequest $request): bool
    {
        $subjectType = $this->normalizedSubjectType($request);

        if (! $subjectType instanceof ContributionSubjectType) {
            return false;
        }

        $type = $request->type instanceof ContributionRequestType
            ? $request->type
            : (is_string($request->type) ? ContributionRequestType::tryFrom($request->type) : null);

        return match ($type) {
            ContributionRequestType::Create => in_array($subjectType, [
                ContributionSubjectType::Institution,
                ContributionSubjectType::Person,
                ContributionSubjectType::Reference,
            ], true),
            ContributionRequestType::Update => in_array($subjectType, [
                ContributionSubjectType::Event,
                ContributionSubjectType::Institution,
                ContributionSubjectType::Person,
                ContributionSubjectType::Reference,
            ], true),
            default => false,
        };
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
            ContributionSubjectType::Event => __('Event'),
            ContributionSubjectType::Institution => __('Institution'),
            ContributionSubjectType::Person => __('Speaker'),
            ContributionSubjectType::Reference => __('Reference'),
            default => __('Submission'),
        };
    }

    private function subjectTitle(ContributionRequest $request): string
    {
        $entity = $request->entity;

        $proposedName = data_get($request->proposed_data, 'name');
        $proposedTitle = data_get($request->proposed_data, 'title');

        return match (true) {
            $entity instanceof Event => $entity->title,
            $entity instanceof Institution => $entity->name,
            $entity instanceof Person => $entity->formatted_name,
            $entity instanceof Reference => $entity->displayTitle(),
            is_string($proposedName) && trim($proposedName) !== '' => trim($proposedName),
            is_string($proposedTitle) && trim($proposedTitle) !== '' => trim($proposedTitle),
            default => $this->subjectLabel($request),
        };
    }

    private function approvedActionUrl(ContributionRequest $request): string
    {
        $entity = $request->entity;

        return match (true) {
            $entity instanceof Institution => route('institutions.show', $entity),
            $entity instanceof Person => route('persons.show', $entity),
            $entity instanceof Reference => route('references.show', $entity),
            default => route('contributions.index'),
        };
    }

    private function updateApprovedActionUrl(ContributionRequest $request): string
    {
        return $this->hubPresenter->requestPublicUrl($request)
            ?? route('contributions.index', ['lihat' => 'request:'.$request->getKey()]);
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
