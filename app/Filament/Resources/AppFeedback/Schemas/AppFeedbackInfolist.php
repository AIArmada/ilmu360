<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppFeedback\Schemas;

use App\Models\AppFeedback;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class AppFeedbackInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('app-feedback.admin.section_submission'))
                    ->schema([
                        TextEntry::make('category')
                            ->label(__('app-feedback.form.category'))
                            ->badge(),
                        TextEntry::make('status')
                            ->label(__('app-feedback.history.status'))
                            ->badge(),
                        TextEntry::make('title')
                            ->label(__('app-feedback.form.title'))
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->label(__('app-feedback.form.description_field'))
                            ->columnSpanFull(),
                        TextEntry::make('page_url')
                            ->label(__('app-feedback.form.page_url'))
                            ->url(fn (AppFeedback $record): ?string => $record->page_url, shouldOpenInNewTab: true)
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label(__('app-feedback.history.submitted'))
                            ->dateTime(),
                        TextEntry::make('diagnostics')
                            ->label('Diagnostics')
                            ->state(fn (AppFeedback $record): string => $record->diagnostics === null
                                ? '—'
                                : (string) json_encode($record->diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                            ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap'])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('app-feedback.admin.section_reporter'))
                    ->schema([
                        TextEntry::make('reporter.name')
                            ->label('Name'),
                        TextEntry::make('reporter.email')
                            ->label('Email'),
                    ])
                    ->columns(2),
                Section::make(__('app-feedback.admin.section_evidence'))
                    ->schema([
                        TextEntry::make('evidence_files')
                            ->hiddenLabel()
                            ->state(fn (AppFeedback $record): HtmlString => self::evidenceLinks($record))
                            ->html()
                            ->columnSpanFull(),
                    ]),
                Section::make(__('app-feedback.admin.section_resolution'))
                    ->schema([
                        TextEntry::make('resolution_note')
                            ->label(__('app-feedback.admin.resolution_note'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('handler.name')
                            ->label('Handled by')
                            ->placeholder('—'),
                        TextEntry::make('triaged_at')
                            ->label('Triaged at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('resolved_at')
                            ->label('Resolved at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('declined_at')
                            ->label('Declined at')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make(__('app-feedback.admin.section_internal'))
                    ->schema([
                        TextEntry::make('internal_notes')
                            ->hiddenLabel()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make(__('app-feedback.admin.section_github'))
                    ->schema([
                        TextEntry::make('github_escalation_status')
                            ->label('Status')
                            ->badge(),
                        TextEntry::make('github_issue_number')
                            ->label('Issue number')
                            ->placeholder('—'),
                        TextEntry::make('github_issue_url')
                            ->label('Issue URL')
                            ->url(fn (AppFeedback $record): ?string => $record->github_issue_url, shouldOpenInNewTab: true)
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('github_requested_at')
                            ->label('Requested at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('github_created_at')
                            ->label('Created at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('github_unknown_at')
                            ->label('Unknown at')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(2),
            ]);
    }

    private static function evidenceLinks(AppFeedback $feedback): HtmlString
    {
        $media = $feedback->getMedia('evidence');

        if ($media->isEmpty()) {
            return new HtmlString(e(__('app-feedback.admin.no_evidence')));
        }

        $links = $media
            ->map(static fn ($item): string => sprintf(
                '<a href="%s" class="underline underline-offset-2" target="_blank" rel="noopener noreferrer">%s</a>',
                e(route('app-feedback.evidence.download', ['feedback' => $feedback, 'mediaId' => $item->getKey()])),
                e($item->name ?: $item->file_name),
            ))
            ->implode(' ');

        return new HtmlString($links);
    }
}
