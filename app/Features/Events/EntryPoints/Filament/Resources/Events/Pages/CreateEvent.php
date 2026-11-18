<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Resources\Events\Pages;

use AIArmada\Events\Contracts\EventTemplateService;
use AIArmada\Events\Models\EventTemplate;
use App\Features\Events\Api\AdminEventWriter;
use App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource;
use App\Features\Events\Models\Event;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

final class CreateEvent extends CreateRecord
{
    #[\Override]
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createFromTemplate')
                ->label('From Template')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->form([
                    Select::make('event_template_id')
                        ->label('Template')
                        ->options(fn (): array => EventTemplate::query()
                            ->where('template_type', 'event')
                            ->where('status', 'published')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (EventTemplate $template): array => [
                                $template->getKey() => "{$template->name} ({$template->code})",
                            ])
                            ->all())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $template = EventTemplate::query()->findOrFail($data['event_template_id']);

                    try {
                        $event = app(EventTemplateService::class)->createFromTemplate($template);
                        redirect(EventResource::getUrl('edit', ['record' => $event]));
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Failed to create from template')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->modalWidth('lg'),
        ];
    }

    protected function handleRecordCreation(array $data): Event
    {
        return Event::query()->with(['accessPolicy', 'references', 'series', 'classifications', 'keyPeople', 'languages', 'media', 'institution', 'venue', 'primaryLocation.venueSpace'])->findOrFail(
            app(AdminEventWriter::class)->save($data, (string) $this->authenticatedUser()->getKey()),
        );
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('An authenticated admin is required to save an event.');
        }

        return $user;
    }
}
