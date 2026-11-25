<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Support;

use AIArmada\Membership\Enums\ApplicationStatus;
use App\Features\Membership\Api\MembershipApplicationFormRules;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Features\Membership\Models\MembershipApplication;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Shared moderation actions for membership applications.
 *
 * Used by both the admin table record actions and the view page header
 * actions so review behavior, guards and failure handling stay identical.
 */
final class MembershipApplicationModerationActions
{
    /**
     * @param  (callable(MembershipApplication): void)|null  $afterAction
     */
    public static function approveAction(?callable $afterAction = null): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve Membership Application')
            ->modalDescription('Approve this application and choose the role to grant.')
            ->schema(function (MembershipApplication $record): array {
                $options = MembershipApplicationFormRules::roleOptions($record->subjectType());
                $requestedRole = $record->meta['applied_role'] ?? null;

                return [
                    Select::make('granted_role')
                        ->label('Granted Role')
                        ->options($options)
                        ->default(is_string($requestedRole) && isset($options[$requestedRole]) ? $requestedRole : null)
                        ->required(),
                    Textarea::make('reviewer_note')
                        ->label('Reviewer Note')
                        ->rows(3)
                        ->maxLength(2000),
                ];
            })
            ->authorize(fn (): bool => MembershipApplicationResource::canAccess())
            ->visible(fn (MembershipApplication $record): bool => $record->status === ApplicationStatus::Pending
                && MembershipApplicationResource::canAccess())
            ->action(function (MembershipApplication $record, array $data, MembershipApplications $membershipApplications) use ($afterAction): void {
                $user = auth()->user();

                abort_unless($user instanceof User, 403);

                try {
                    $membershipApplications->approve(
                        (string) $record->getKey(),
                        (string) $user->getKey(),
                        (string) $data['granted_role'],
                        filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                    );

                    Notification::make()
                        ->title('Membership application approved')
                        ->success()
                        ->send();
                } catch (RuntimeException|InvalidArgumentException $exception) {
                    Notification::make()
                        ->title($exception->getMessage() !== ''
                            ? $exception->getMessage()
                            : __('Could not approve this membership application.'))
                        ->danger()
                        ->send();
                } finally {
                    MembershipApplicationResource::flushNavigationBadgeCache();

                    if ($afterAction !== null) {
                        $afterAction($record);
                    }
                }
            });
    }

    /**
     * @param  (callable(MembershipApplication): void)|null  $afterAction
     */
    public static function rejectAction(?callable $afterAction = null): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Reject Membership Application')
            ->modalDescription('Reject this application and optionally leave guidance for the applicant.')
            ->schema([
                Textarea::make('reviewer_note')
                    ->label('Reviewer Note')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->authorize(fn (): bool => MembershipApplicationResource::canAccess())
            ->visible(fn (MembershipApplication $record): bool => $record->status === ApplicationStatus::Pending
                && MembershipApplicationResource::canAccess())
            ->action(function (MembershipApplication $record, array $data, MembershipApplications $membershipApplications) use ($afterAction): void {
                $user = auth()->user();

                abort_unless($user instanceof User, 403);

                try {
                    $membershipApplications->reject(
                        (string) $record->getKey(),
                        (string) $user->getKey(),
                        filled($data['reviewer_note'] ?? null) ? (string) $data['reviewer_note'] : null,
                    );

                    Notification::make()
                        ->title('Membership application rejected')
                        ->danger()
                        ->send();
                } catch (RuntimeException|InvalidArgumentException $exception) {
                    Notification::make()
                        ->title($exception->getMessage() !== ''
                            ? $exception->getMessage()
                            : __('Could not reject this membership application.'))
                        ->danger()
                        ->send();
                } finally {
                    MembershipApplicationResource::flushNavigationBadgeCache();

                    if ($afterAction !== null) {
                        $afterAction($record);
                    }
                }
            });
    }

    public static function openSubjectAction(): Action
    {
        return Action::make('open_subject')
            ->label('Open Record')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->url(fn (MembershipApplication $record): ?string => MembershipApplicationAdminPresenter::subjectAdminUrl($record))
            ->openUrlInNewTab()
            ->visible(fn (MembershipApplication $record): bool => filled(MembershipApplicationAdminPresenter::subjectAdminUrl($record)));
    }
}
