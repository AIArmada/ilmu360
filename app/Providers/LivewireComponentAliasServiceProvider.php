<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Stable public Livewire component aliases for feature-owned components.
 *
 * Component classes live with their owning feature; the externally used
 * component names stay registered here so views and tests keep resolving the
 * same public alias.
 */
final class LivewireComponentAliasServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string> */
    private const array ALIASES = [
        'components.directory-card-follow' => \App\Features\Engagement\EntryPoints\Livewire\Components\DirectoryCardFollow::class,
        'components.event-card-engagement' => \App\Features\Engagement\EntryPoints\Livewire\Components\EventCardEngagement::class,
        'pages.about.show' => \App\Platform\Ui\Pages\About\Show::class,
        'pages.contributions.index' => \App\Features\Contributions\EntryPoints\Livewire\Pages\Index::class,
        'pages.contributions.submit-institution' => \App\Features\Contributions\EntryPoints\Livewire\Pages\SubmitInstitution::class,
        'pages.contributions.submit-person' => \App\Features\Contributions\EntryPoints\Livewire\Pages\SubmitPerson::class,
        'pages.contributions.submit-reference' => \App\Features\Contributions\EntryPoints\Livewire\Pages\SubmitReference::class,
        'pages.contributions.suggest-update' => \App\Features\Contributions\EntryPoints\Livewire\Pages\SuggestUpdate::class,
        'pages.dashboard.dakwah-index' => \App\Features\ShareTracking\EntryPoints\Livewire\Pages\DakwahIndex::class,
        'pages.dashboard.dakwah-link-show' => \App\Features\ShareTracking\EntryPoints\Livewire\Pages\DakwahLinkShow::class,
        'pages.dashboard.events.create-advanced' => \App\Features\Events\EntryPoints\Livewire\Pages\Dashboard\Events\CreateAdvanced::class,
        'pages.dashboard.events.offline-admissions' => \App\Features\Registration\EntryPoints\Livewire\Pages\Dashboard\Events\OfflineAdmissions::class,
        'pages.dashboard.events.participants' => \App\Features\Registration\EntryPoints\Livewire\Pages\Dashboard\Events\Participants::class,
        'pages.dashboard.events.refunds' => \App\Features\Registration\EntryPoints\Livewire\Pages\Dashboard\Events\Refunds::class,
        'pages.dashboard.institution-dashboard' => \App\Features\Institutions\EntryPoints\Livewire\Pages\InstitutionDashboard::class,
        'pages.dashboard.organizations.create-event' => \App\Features\Organizations\EntryPoints\Livewire\Pages\CreateEvent::class,
        'pages.dashboard.organizations.create-organization' => \App\Features\Organizations\EntryPoints\Livewire\Pages\CreateOrganization::class,
        'pages.dashboard.organizations.index' => \App\Features\Organizations\EntryPoints\Livewire\Pages\Index::class,
        'pages.dashboard.organizations.workspace' => \App\Features\Organizations\EntryPoints\Livewire\Pages\Workspace::class,
        'pages.dashboard.person-dashboard' => \App\Features\Persons\EntryPoints\Livewire\Pages\PersonDashboard::class,
        'pages.dashboard.referral-member-index' => \App\Features\Referrals\EntryPoints\Livewire\Pages\ReferralMemberIndex::class,
        'pages.dashboard.user-dashboard' => \App\Features\Identity\EntryPoints\Livewire\Pages\Dashboard\UserDashboard::class,
        'pages.events.checkout' => \App\Features\Registration\EntryPoints\Livewire\Pages\Checkout::class,
        'pages.events.index' => \App\Features\Events\EntryPoints\Livewire\Pages\Index::class,
        'pages.events.refund' => \App\Features\Registration\EntryPoints\Livewire\Pages\Refund::class,
        'pages.events.show' => \App\Features\Events\EntryPoints\Livewire\Pages\Show::class,
        'pages.institutions.show' => \App\Features\Institutions\EntryPoints\Livewire\Pages\Show::class,
        'pages.membership.show-invitation' => \App\Features\Membership\EntryPoints\Livewire\Pages\ShowInvitation::class,
        'pages.persons.show' => \App\Features\Persons\EntryPoints\Livewire\Pages\Show::class,
        'pages.references.show' => \App\Features\References\EntryPoints\Livewire\Pages\Show::class,
        'pages.submit-event.create' => \App\Features\Events\EntryPoints\Livewire\Pages\SubmitEvent\Create::class,
        'components.event-filters' => \App\Features\Discovery\EntryPoints\Livewire\Components\EventFilters::class,
        'pages.events.occurrence' => \App\Features\Events\EntryPoints\Livewire\Pages\Occurrence::class,
        'pages.events.session' => \App\Features\Events\EntryPoints\Livewire\Pages\Session::class,
    ];

    public function boot(): void
    {
        foreach (self::ALIASES as $alias => $component) {
            Livewire::component($alias, $component);
        }
    }
}
