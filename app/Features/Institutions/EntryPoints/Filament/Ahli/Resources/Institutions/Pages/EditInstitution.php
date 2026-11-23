<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Filament\Ahli\Resources\Institutions\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Institutions\EntryPoints\Filament\Ahli\Resources\Institutions\InstitutionResource;
use Filament\Schemas\Components\Component;
use Filament\Support\Components\Attributes\Unexposed;
use Livewire\Attributes\Locked;

class EditInstitution extends \App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\Pages\EditInstitution
{
    #[\Override]
    protected static string $resource = InstitutionResource::class;

    #[Locked]
    public bool $managingMemberInvitations = false;

    #[\Override]
    #[Unexposed]
    public function mount(int|string $record): void
    {
        $this->managingMemberInvitations = request()->query('relation') === 'member_invitations'
            && auth()->user()?->canPublishDirectoryUpdates() !== true;

        if (! $this->managingMemberInvitations) {
            parent::mount($record);

            return;
        }

        OwnerContext::withOwner(null, function () use ($record): void {
            $this->record = $this->resolveRecord($record);
            $this->authorizeAccess();
        });
    }

    #[\Override]
    protected function authorizeAccess(): void
    {
        if ($this->managingMemberInvitations) {
            static::getResource()::authorizeEdit($this->getRecord());

            return;
        }

        parent::authorizeAccess();
    }

    #[\Override]
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->requireDirectoryPublishingPermission();

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    #[\Override]
    #[Unexposed]
    public function saveFormComponentOnly(Component $component): void
    {
        $this->requireDirectoryPublishingPermission();

        parent::saveFormComponentOnly($component);
    }

    #[\Override]
    #[Unexposed]
    public function getFormContentComponent(): Component
    {
        return parent::getFormContentComponent()->hidden($this->managingMemberInvitations);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
