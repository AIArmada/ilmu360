<?php

declare(strict_types=1);

namespace App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Support;

use App\Features\Contributions\Api\ContributionRequestLifecycle;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Features\Contributions\Enums\ContributionRequestStatus;
use App\Features\Contributions\Exceptions\ContributionRequestAlreadyReviewedException;
use App\Features\Contributions\Exceptions\ContributionRequestNotPendingException;
use App\Features\Contributions\Models\ContributionRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use RuntimeException;

/**
 * Shared moderation actions for contribution requests.
 *
 * Used by both the admin table record actions and the view page header
 * actions so review behavior, guards and failure handling stay identical.
 */
final class ContributionRequestModerationActions
{
    public static function approveAction(bool $redirectToView = false): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve Contribution Request')
            ->modalDescription('Approve this request and apply the proposed change.')
            ->schema([
                Textarea::make('reviewer_note')
                    ->label('Reviewer Note')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->authorize(fn (ContributionRequest $record): bool => auth()->user()?->can('review', $record) ?? false)
            ->visible(fn (ContributionRequest $record): bool => $record->status === ContributionRequestStatus::Pending
                && (auth()->user()?->can('review', $record) ?? false))
            ->action(function (ContributionRequest $record, array $data, ContributionRequestLifecycle $contributionRequestLifecycle, Component $livewire) use ($redirectToView): void {
                $user = auth()->user();

                abort_unless($user instanceof User, 403);

                try {
                    $contributionRequestLifecycle->approve(
                        (string) $record->getKey(),
                        (string) $user->getKey(),
                        filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                    );
                } catch (ContributionRequestAlreadyReviewedException $exception) {
                    self::notifyDanger($exception->getMessage());

                    if ($redirectToView) {
                        self::redirectToView($livewire, $record);
                    }

                    return;
                } catch (ContributionRequestNotPendingException) {
                    self::notifyDanger(__('This request is no longer pending review. Please refresh to see its current status.'));

                    if ($redirectToView) {
                        self::redirectToView($livewire, $record);
                    }

                    return;
                } catch (ValidationException $exception) {
                    self::notifyDanger(self::firstValidationMessage($exception, __('Could not approve this request.')));

                    return;
                } catch (LockTimeoutException) {
                    self::notifyDanger(__('The review service is busy. Please try again in a moment.'));

                    if ($redirectToView) {
                        self::redirectToView($livewire, $record);
                    }

                    return;
                } catch (AuthorizationException $exception) {
                    self::notifyDanger(self::authorizationMessage($exception));

                    return;
                } catch (RuntimeException $exception) {
                    self::notifyDanger($exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : __('Could not approve this request.'));

                    return;
                }

                ContributionRequestResource::flushNavigationBadgeCache();

                Notification::make()
                    ->title('Contribution request approved')
                    ->success()
                    ->send();

                if ($redirectToView) {
                    self::redirectToView($livewire, $record);
                }
            });
    }

    public static function rejectAction(bool $redirectToView = false): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Reject Contribution Request')
            ->modalDescription('Reject this request and record the moderation reason.')
            ->schema([
                Select::make('reason_code')
                    ->label('Reason')
                    ->options(ContributionRequestPresenter::rejectionReasonOptions())
                    ->required(),
                Textarea::make('reviewer_note')
                    ->label('Reviewer Note')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->authorize(fn (ContributionRequest $record): bool => auth()->user()?->can('review', $record) ?? false)
            ->visible(fn (ContributionRequest $record): bool => $record->status === ContributionRequestStatus::Pending
                && (auth()->user()?->can('review', $record) ?? false))
            ->action(function (ContributionRequest $record, array $data, ContributionRequestLifecycle $contributionRequestLifecycle, Component $livewire) use ($redirectToView): void {
                $user = auth()->user();

                abort_unless($user instanceof User, 403);

                try {
                    $contributionRequestLifecycle->reject(
                        (string) $record->getKey(),
                        (string) $user->getKey(),
                        (string) $data['reason_code'],
                        filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                    );
                } catch (ContributionRequestAlreadyReviewedException $exception) {
                    self::notifyDanger($exception->getMessage());

                    if ($redirectToView) {
                        self::redirectToView($livewire, $record);
                    }

                    return;
                } catch (ContributionRequestNotPendingException) {
                    self::notifyDanger(__('This request is no longer pending review. Please refresh to see its current status.'));

                    if ($redirectToView) {
                        self::redirectToView($livewire, $record);
                    }

                    return;
                } catch (ValidationException $exception) {
                    self::notifyDanger(self::firstValidationMessage($exception, __('Could not review this request.')));

                    return;
                } catch (LockTimeoutException) {
                    self::notifyDanger(__('The review service is busy. Please try again in a moment.'));

                    if ($redirectToView) {
                        self::redirectToView($livewire, $record);
                    }

                    return;
                } catch (AuthorizationException $exception) {
                    self::notifyDanger(self::authorizationMessage($exception));

                    return;
                } catch (RuntimeException $exception) {
                    self::notifyDanger($exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : __('Could not review this request.'));

                    return;
                }

                ContributionRequestResource::flushNavigationBadgeCache();

                Notification::make()
                    ->title('Contribution request rejected')
                    ->danger()
                    ->send();

                if ($redirectToView) {
                    self::redirectToView($livewire, $record);
                }
            });
    }

    private static function notifyDanger(string $title): void
    {
        Notification::make()
            ->title($title !== '' ? $title : __('Could not review this request.'))
            ->danger()
            ->send();
    }

    private static function firstValidationMessage(ValidationException $exception, string $fallback): string
    {
        $message = collect($exception->errors())->flatten()->first();

        return is_string($message) && $message !== '' ? $message : $fallback;
    }

    private static function authorizationMessage(AuthorizationException $exception): string
    {
        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : __('You are not authorized to review this contribution request.');
    }

    private static function redirectToView(Component $livewire, ContributionRequest $record): void
    {
        $livewire->redirect(
            ContributionRequestResource::getUrl('view', ['record' => $record]),
            navigate: true,
        );
    }
}
