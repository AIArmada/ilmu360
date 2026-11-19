<?php

namespace App\Features\Contributions\EntryPoints\Livewire\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Contributions\Api\StagedContributionCreates;
use App\Features\Contributions\EntryPoints\Filament\Forms\ReferenceContributionFormSchema;
use App\Features\Contributions\Support\ContributionSubjectType;
use App\Features\References\Models\Reference;
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

    public function submit(StagedContributionCreates $stagedCreates): void
    {
        OwnerContext::withOwner(null, function () use ($stagedCreates): void {
            $user = auth()->user();

            abort_unless($user instanceof User, 403);

            $stagedCreates->submit(
                ContributionSubjectType::Reference,
                $this->contributionForm()->getState(),
                (string) $user->getKey(),
                function (string $referenceId): void {
                    $reference = Reference::query()->withoutOwnerScope()->findOrFail($referenceId);
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
