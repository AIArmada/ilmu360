<?php

declare(strict_types=1);

namespace App\Features\Organizations\EntryPoints\Livewire\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Organizations\Models\Organization;
use App\Features\DonationChannels\Api\Data\DonationChannelSummary;
use App\Features\DonationChannels\Api\DonationChannelDirectory;
use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Read-only public organization profile for donors.
 *
 * Only active, public organizations resolve here, and only verified donation
 * channels render, matching the public organization API payload.
 */
#[Layout('layouts.app')]
#[Title('Organization profile')]
final class Show extends Component
{
    public Organization $organization;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Organization $organization): void
    {
        abort_unless(
            Organization::query()->public()->whereKey($organization->getKey())->exists(),
            404,
        );

        $this->organization = $organization;
    }

    /**
     * @return list<DonationChannelSummary>
     */
    public function channels(): array
    {
        return collect(app(DonationChannelDirectory::class)->forOwner('organization', (string) $this->organization->getKey()))
            ->filter(static fn (DonationChannelSummary $channel): bool => $channel->status === DonationChannelStatus::Verified)
            ->values()
            ->all();
    }

    public function render(): View
    {
        return view('livewire.pages.organizations.show', [
            'channels' => $this->channels(),
        ]);
    }
}
