<?php

namespace App\Livewire\Pages\References;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Enums\DawahShareOutcomeType;
use App\Models\Reference;
use App\Services\ShareTrackingService;
use App\Support\Auth\IntendedRedirect;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Reference $reference;

    public bool $isFollowing = false;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Reference $reference): void
    {
        $canBypassVisibility = auth()->user()?->hasAnyRole(['super_admin', 'moderator']) ?? false;

        abort_unless(
            $reference->isPubliclyVisible() || $canBypassVisibility,
            404,
        );

        $this->reference = $reference;
        $this->loadReferenceRelations();
        $this->isFollowing = auth()->user()?->isFollowing($reference) ?? false;
    }

    public function toggleFollow(): void
    {
        $user = auth()->user();

        if (! $user) {
            $this->redirect(
                IntendedRedirect::loginUrl(route('references.show', $this->reference, absolute: false)),
                navigate: true,
            );

            return;
        }

        if ($this->isFollowing) {
            $user->unfollow($this->reference);
            $this->isFollowing = false;

            return;
        }

        $user->follow($this->reference);
        $this->isFollowing = true;

        app(ShareTrackingService::class)->recordOutcome(
            type: DawahShareOutcomeType::ReferenceFollow,
            outcomeKey: 'reference_follow:user:'.$user->id.':reference:'.$this->reference->id,
            subject: $this->reference,
            actor: $user,
            request: request(),
            metadata: [
                'reference_id' => $this->reference->id,
            ],
        );
    }

    public function render(): View
    {
        $this->loadReferenceRelations();

        return view('livewire.pages.references.show');
    }

    private function loadReferenceRelations(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->reference->load([
                'media',
                'socialProfiles',
                'parentReference' => fn ($query) => $query->active(),
                'childReferences' => fn ($query) => $query->active(),
            ]);
        });
    }
}
