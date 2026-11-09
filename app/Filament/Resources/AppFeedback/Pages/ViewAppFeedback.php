<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppFeedback\Pages;

use App\Actions\AppFeedback\EscalateAppFeedbackToGitHubAction;
use App\Actions\AppFeedback\LinkAppFeedbackGitHubIssueAction;
use App\Actions\AppFeedback\PostAppFeedbackMessageAction;
use App\Actions\AppFeedback\TransitionAppFeedbackAction;
use App\Enums\AppFeedbackGitHubStatus;
use App\Enums\AppFeedbackStatus;
use App\Features\GitHubIssues\Api\GitHubIssueReporterContract;
use App\Filament\Resources\AppFeedback\AppFeedbackResource;
use App\Models\AppFeedback;
use App\Shared\Auth\AccountActor;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ViewAppFeedback extends ViewRecord
{
    protected static string $resource = AppFeedbackResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->replyAction(),
            $this->triageAction(),
            $this->resolveFeedbackAction(),
            $this->declineAction(),
            $this->internalNotesAction(),
            $this->githubEscalateAction(),
            $this->githubLinkAction(),
        ];
    }

    private function replyAction(): Action
    {
        return Action::make('reply')
            ->label(__('app-feedback.admin.reply'))
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->color('primary')
            ->visible(fn (): bool => in_array($this->feedback()->status, [AppFeedbackStatus::New, AppFeedbackStatus::Triaged], true)
                && auth()->user()?->can('reply', $this->feedback()) === true)
            ->schema([
                Textarea::make('body')
                    ->label(__('app-feedback.admin.reply_body'))
                    ->helperText(__('app-feedback.admin.reply_help'))
                    ->required()
                    ->minLength(3)
                    ->maxLength(2000)
                    ->rows(4),
            ])
            ->action(function (array $data, PostAppFeedbackMessageAction $postAppFeedbackMessageAction): void {
                $user = auth()->user();
                abort_unless($user instanceof AccountActor, 403);

                $postAppFeedbackMessageAction->handle($user, $this->feedback(), (string) $data['body']);

                $this->refreshFeedback();

                Notification::make()
                    ->title(__('app-feedback.admin.reply'))
                    ->success()
                    ->send();
            });
    }

    private function triageAction(): Action
    {
        return Action::make('triage')
            ->label(__('app-feedback.admin.triage'))
            ->icon(Heroicon::OutlinedEye)
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->feedback()->status === AppFeedbackStatus::New
                && auth()->user()?->can('transition', $this->feedback()) === true)
            ->action(function (TransitionAppFeedbackAction $transitionAppFeedbackAction): void {
                $user = auth()->user();
                abort_unless($user instanceof AccountActor, 403);

                $transitionAppFeedbackAction->handle($user, $this->feedback(), AppFeedbackStatus::Triaged);

                $this->refreshFeedback();

                Notification::make()
                    ->title(__('app-feedback.admin.triage'))
                    ->success()
                    ->send();
            });
    }

    private function resolveFeedbackAction(): Action
    {
        return Action::make('resolve')
            ->label(__('app-feedback.admin.resolve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (): bool => $this->feedback()->status === AppFeedbackStatus::Triaged
                && auth()->user()?->can('transition', $this->feedback()) === true)
            ->schema([
                Textarea::make('resolution_note')
                    ->label(__('app-feedback.admin.resolution_note'))
                    ->required()
                    ->maxLength(2000)
                    ->rows(4),
            ])
            ->action(function (array $data, TransitionAppFeedbackAction $transitionAppFeedbackAction): void {
                $user = auth()->user();
                abort_unless($user instanceof AccountActor, 403);

                $transitionAppFeedbackAction->handle(
                    $user,
                    $this->feedback(),
                    AppFeedbackStatus::Resolved,
                    filled($data['resolution_note'] ?? null) ? (string) $data['resolution_note'] : null,
                );

                $this->refreshFeedback();

                Notification::make()
                    ->title(__('app-feedback.admin.resolve'))
                    ->success()
                    ->send();
            });
    }

    private function declineAction(): Action
    {
        return Action::make('decline')
            ->label(__('app-feedback.admin.decline'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (): bool => $this->feedback()->status === AppFeedbackStatus::Triaged
                && auth()->user()?->can('transition', $this->feedback()) === true)
            ->schema([
                Textarea::make('resolution_note')
                    ->label(__('app-feedback.admin.resolution_note'))
                    ->required()
                    ->maxLength(2000)
                    ->rows(4),
            ])
            ->action(function (array $data, TransitionAppFeedbackAction $transitionAppFeedbackAction): void {
                $user = auth()->user();
                abort_unless($user instanceof AccountActor, 403);

                $transitionAppFeedbackAction->handle(
                    $user,
                    $this->feedback(),
                    AppFeedbackStatus::Declined,
                    filled($data['resolution_note'] ?? null) ? (string) $data['resolution_note'] : null,
                );

                $this->refreshFeedback();

                Notification::make()
                    ->title(__('app-feedback.admin.decline'))
                    ->success()
                    ->send();
            });
    }

    private function internalNotesAction(): Action
    {
        return Action::make('internal_notes')
            ->label(__('app-feedback.admin.edit_internal_notes'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('updateInternalNotes', $this->feedback()) === true)
            ->schema([
                Textarea::make('internal_notes')
                    ->label(__('app-feedback.admin.internal_notes'))
                    ->maxLength(5000)
                    ->rows(5),
            ])
            ->fillForm(fn (): array => [
                'internal_notes' => $this->feedback()->internal_notes,
            ])
            ->action(function (array $data): void {
                $user = auth()->user();
                abort_unless($user instanceof AccountActor, 403);

                $feedback = $this->feedback();

                Gate::forUser($user)->authorize('updateInternalNotes', $feedback);

                $feedback->forceFill([
                    'internal_notes' => filled($data['internal_notes'] ?? null)
                        ? trim((string) $data['internal_notes'])
                        : null,
                ]);
                $feedback->save();

                $this->refreshFeedback();

                Notification::make()
                    ->title(__('app-feedback.admin.edit_internal_notes'))
                    ->success()
                    ->send();
            });
    }

    private function githubEscalateAction(): Action
    {
        return Action::make('escalate_github')
            ->label(__('app-feedback.admin.escalate_github'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->visible(fn (): bool => $this->feedback()->github_escalation_status === AppFeedbackGitHubStatus::NotRequested
                && auth()->user()?->can('escalateToGitHub', $this->feedback()) === true
                && app(GitHubIssueReporterContract::class)->isConfigured())
            ->schema([
                TextInput::make('github_title')
                    ->label(__('app-feedback.admin.github_title'))
                    ->required()
                    ->minLength(3)
                    ->maxLength(180),
                Textarea::make('github_summary')
                    ->label(__('app-feedback.admin.github_summary'))
                    ->required()
                    ->minLength(10)
                    ->maxLength(4000)
                    ->rows(6)
                    ->helperText(__('app-feedback.admin.github_summary_help')),
            ])
            ->action(function (array $data, EscalateAppFeedbackToGitHubAction $escalateAppFeedbackToGitHubAction): void {
                $user = auth()->user();
                abort_unless($user instanceof AccountActor, 403);

                try {
                    $escalateAppFeedbackToGitHubAction->handle(
                        $user,
                        $this->feedback(),
                        (string) $data['github_title'],
                        (string) $data['github_summary'],
                    );
                } catch (ValidationException $exception) {
                    $this->refreshFeedback();

                    Notification::make()
                        ->title(collect($exception->errors())->flatten()->first() ?? __('app-feedback.validation.github_escalation_failed'))
                        ->danger()
                        ->send();

                    return;
                }

                $this->refreshFeedback();

                Notification::make()
                    ->title(__('app-feedback.admin.escalate_github'))
                    ->success()
                    ->send();
            });
    }

    private function githubLinkAction(): Action
    {
        return Action::make('link_github_issue')
            ->label(__('app-feedback.admin.link_github_issue'))
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->visible(fn (): bool => in_array($this->feedback()->github_escalation_status, [
                AppFeedbackGitHubStatus::Pending,
                AppFeedbackGitHubStatus::Unknown,
            ], true) && auth()->user()?->can('linkGitHubIssue', $this->feedback()) === true)
            ->schema([
                TextInput::make('github_issue_url')
                    ->label(__('app-feedback.admin.github_issue_url'))
                    ->required()
                    ->url()
                    ->maxLength(2048),
            ])
            ->action(function (array $data, LinkAppFeedbackGitHubIssueAction $linkAppFeedbackGitHubIssueAction): void {
                $user = auth()->user();
                abort_unless($user instanceof AccountActor, 403);

                try {
                    $linkAppFeedbackGitHubIssueAction->handle(
                        $user,
                        $this->feedback(),
                        (string) $data['github_issue_url'],
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(collect($exception->errors())->flatten()->first() ?? __('app-feedback.validation.github_issue_url_invalid'))
                        ->danger()
                        ->send();

                    return;
                }

                $this->refreshFeedback();

                Notification::make()
                    ->title(__('app-feedback.admin.link_github_issue'))
                    ->success()
                    ->send();
            });
    }

    private function feedback(): AppFeedback
    {
        /** @var AppFeedback $feedback */
        $feedback = $this->getRecord();

        return $feedback;
    }

    private function refreshFeedback(): void
    {
        $this->record = $this->feedback()->fresh(['reporter', 'handler']);
    }
}
