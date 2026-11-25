<?php

declare(strict_types=1);

namespace App\Features\Reports\EntryPoints\Filament\Resources\Reports\Support;

use App\Features\Reports\Api\ReportCommands;
use App\Features\Reports\Api\TriageReportInput;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\ReportResource;
use App\Features\Reports\Models\Report;
use App\Features\Reports\Support\ReportPresenter;
use App\Shared\Auth\AccountActor;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Shared moderation actions for reports.
 *
 * Used by both the reports table record actions and the edit page header
 * actions so review behavior, guards and failure handling stay identical.
 */
final class ReportModerationActions
{
    /**
     * @param  (callable(Report): void)|null  $afterAction
     */
    public static function triageAction(?callable $afterAction = null): Action
    {
        return Action::make('triage')
            ->label('Mark Triaged')
            ->icon(Heroicon::OutlinedEye)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Mark Report Triaged')
            ->modalDescription('Move this report into triage for follow-up.')
            ->schema([
                Textarea::make('resolution_note')
                    ->label('Note (optional)')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->authorize(fn (Report $record): bool => self::canResolve($record))
            ->visible(fn (Report $record): bool => $record->status === Report::STATUS_OPEN && self::canResolve($record))
            ->action(function (Report $record, array $data, ReportCommands $commands) use ($afterAction): void {
                self::perform($record, 'triage', $data, $commands, 'Report marked as triaged', $afterAction);
            });
    }

    /**
     * @param  (callable(Report): void)|null  $afterAction
     */
    public static function resolveAction(?callable $afterAction = null): Action
    {
        return Action::make('resolve')
            ->label('Resolve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Resolve Report')
            ->modalDescription('Mark this report as resolved and notify the reporter.')
            ->schema([
                Textarea::make('resolution_note')
                    ->label('Resolution Note')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->authorize(fn (Report $record): bool => self::canResolve($record))
            ->visible(fn (Report $record): bool => in_array($record->status, [Report::STATUS_OPEN, Report::STATUS_TRIAGED], true)
                && self::canResolve($record))
            ->action(function (Report $record, array $data, ReportCommands $commands) use ($afterAction): void {
                self::perform($record, 'resolve', $data, $commands, 'Report resolved', $afterAction);
            });
    }

    /**
     * @param  (callable(Report): void)|null  $afterAction
     */
    public static function dismissAction(?callable $afterAction = null): Action
    {
        return Action::make('dismiss')
            ->label('Dismiss')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Dismiss Report')
            ->modalDescription('Dismiss this report and notify the reporter.')
            ->schema([
                Textarea::make('resolution_note')
                    ->label('Dismissal Note')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->authorize(fn (Report $record): bool => self::canResolve($record))
            ->visible(fn (Report $record): bool => in_array($record->status, [Report::STATUS_OPEN, Report::STATUS_TRIAGED], true)
                && self::canResolve($record))
            ->action(function (Report $record, array $data, ReportCommands $commands) use ($afterAction): void {
                self::perform($record, 'dismiss', $data, $commands, 'Report dismissed', $afterAction);
            });
    }

    /**
     * @param  (callable(Report): void)|null  $afterAction
     */
    public static function reopenAction(?callable $afterAction = null): Action
    {
        return Action::make('reopen')
            ->label('Reopen')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Reopen Report')
            ->modalDescription('Return this closed report to the open queue for another review pass.')
            ->authorize(fn (Report $record): bool => self::canResolve($record))
            ->visible(fn (Report $record): bool => in_array($record->status, [
                Report::STATUS_TRIAGED,
                Report::STATUS_RESOLVED,
                Report::STATUS_DISMISSED,
            ], true) && self::canResolve($record))
            ->action(function (Report $record, ReportCommands $commands) use ($afterAction): void {
                self::perform($record, 'reopen', [], $commands, 'Report reopened', $afterAction);
            });
    }

    public static function openEntityAction(): Action
    {
        return Action::make('open_entity')
            ->label('Open Subject')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->url(fn (Report $record): ?string => ReportPresenter::entityAdminUrl($record))
            ->openUrlInNewTab()
            ->visible(fn (Report $record): bool => filled(ReportPresenter::entityAdminUrl($record)));
    }

    public static function canResolve(Report $record): bool
    {
        return auth()->user()?->can('resolve', $record) ?? false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  (callable(Report): void)|null  $afterAction
     */
    private static function perform(
        Report $record,
        string $action,
        array $data,
        ReportCommands $commands,
        string $successTitle,
        ?callable $afterAction,
    ): void {
        $actor = auth()->user();

        abort_unless($actor instanceof AccountActor, 403);

        $note = filled($data['resolution_note'] ?? null) ? (string) $data['resolution_note'] : null;

        try {
            $commands->triage(new TriageReportInput((string) $record->getKey(), $action, $note, $actor));

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            self::notifyDanger(self::validationMessage($exception));
        } catch (AuthorizationException $exception) {
            self::notifyDanger($exception->getMessage() !== ''
                ? $exception->getMessage()
                : __('You are not authorized to moderate this report.'));
        } catch (InvalidArgumentException $exception) {
            self::notifyDanger($exception->getMessage() !== ''
                ? $exception->getMessage()
                : __('Could not moderate this report.'));
        } catch (RuntimeException $exception) {
            self::notifyDanger($exception->getMessage() !== ''
                ? $exception->getMessage()
                : __('Could not moderate this report.'));
        } finally {
            ReportResource::flushNavigationBadgeCache();

            if ($afterAction !== null) {
                $afterAction($record);
            }
        }
    }

    private static function notifyDanger(string $title): void
    {
        Notification::make()
            ->title($title !== '' ? $title : __('Could not moderate this report.'))
            ->danger()
            ->send();
    }

    private static function validationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())->flatten()->first();

        return is_string($message) && $message !== '' ? $message : __('Could not moderate this report.');
    }
}
