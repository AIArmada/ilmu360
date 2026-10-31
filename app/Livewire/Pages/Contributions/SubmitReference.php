<?php

namespace App\Livewire\Pages\Contributions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Contributions\Submission\SubmitStagedContributionCreateAction;
use App\Enums\ContributionSubjectType;
use App\Forms\ReferenceContributionFormSchema;
use App\Models\Reference;
use App\Models\User;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

#[Layout('layouts.app')]
#[Title('Submit Reference')]
class SubmitReference extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use WithFileUploads;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->contributionForm()->fill([
                'type' => 'book',
                'record_kind' => 'work',
            ]);
        });
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model(new Reference)
            ->statePath('data')
            ->components(ReferenceContributionFormSchema::components(includeMedia: true));
    }

    public function submit(SubmitStagedContributionCreateAction $submitStagedContributionCreateAction): void
    {
        OwnerContext::withOwner(null, function () use ($submitStagedContributionCreateAction): void {
            $user = auth()->user();

            abort_unless($user instanceof User, 403);

            $submitStagedContributionCreateAction->handle(
                ContributionSubjectType::Reference,
                $this->contributionForm()->getState(),
                $user,
                function (Reference $reference): void {
                    $this->contributionForm()->model($reference)->saveRelationships();
                    session()->flash('contribution_submission_name', $reference->displayTitle());
                },
                'data',
            );

            $this->redirect(route('contributions.submission-success', [
                'subjectType' => ContributionSubjectType::Reference->publicRouteSegment(),
            ]), navigate: true);
        });
    }

    protected function contributionForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Reference contribution form is not available.');
    }
}
