<?php

declare(strict_types=1);

namespace App\Features\Organizations\EntryPoints\Livewire\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Channels\EmailInvitationChannel;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Organizations\Models\Organization;
use App\Features\DonationChannels\Api\Data\DonationChannelCountryProfile;
use App\Features\DonationChannels\Api\Data\DonationChannelSummary;
use App\Features\DonationChannels\Api\DonationChannelCountryProfiles;
use App\Features\DonationChannels\Api\DonationChannelDirectory;
use App\Features\DonationChannels\Api\DonationChannelWorkflow;
use App\Features\Events\Api\Data\OrganizationWorkspaceEvent;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Membership\Api\MembershipCommands;
use App\Features\Membership\Api\MembershipInvitationDetail;
use App\Features\Membership\Api\MembershipInvitations;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Organizations\Actions\SaveOrganizationAddressAction;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Api\OrganizationCommands;
use App\Features\Organizations\Services\OrganizationProfileService;
use App\Models\User;
use App\Platform\Admin\AdminResourceLink;
use App\Platform\Location\Api\AddressCatalog;
use App\Platform\Ui\InteractsWithToasts;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

#[Layout('layouts.app')]
#[Title('Organization workspace')]
final class Workspace extends Component
{
    use InteractsWithToasts;
    use WithFileUploads;

    public string $organizationId = '';

    public string $inviteRecipient = '';

    public string $inviteRole = 'viewer';

    public ?string $editingMemberId = null;

    public string $editingRole = 'viewer';

    public ?string $addressCountryId = null;

    public ?string $addressStateId = null;

    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $addressPostcode = '';

    public bool $channelFormOpen = false;

    /** @var array<string, mixed> */
    public array $channelForm = [];

    public ?string $editingChannelId = null;

    public mixed $channelQr = null;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Organization $organization): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        abort_unless(
            app(OrganizationAccess::class)->can((string) $user->getKey(), (string) $organization->getKey(), 'organization.view'),
            403,
        );

        $this->organizationId = (string) $organization->getKey();
        $this->syncAddressState();
    }

    public function invite(
        MembershipInvitations $membershipInvitations,
    ): void {
        $organization = $this->selectedOrganization();
        $user = $this->currentUser();
        $this->authorizeManageMembers($organization);

        $validated = $this->validate([
            'inviteRecipient' => ['required', 'email:rfc', 'max:255'],
            'inviteRole' => ['required', Rule::in($this->manageableRoles())],
        ]);

        $email = mb_strtolower(trim((string) $validated['inviteRecipient']));

        if ($organization->members()->where('users.email', $email)->exists()) {
            throw ValidationException::withMessages([
                'inviteRecipient' => __('This person is already a member of the organization.'),
            ]);
        }

        $membershipInvitations->invite(
            MemberSubjectType::Organization,
            (string) $organization->getKey(),
            EmailInvitationChannel::CHANNEL,
            $email,
            (string) $validated['inviteRole'],
            (string) $user->getKey(),
        );

        $this->reset('inviteRecipient');
        $this->successToast(__('Invitation sent successfully.'));
    }

    public function startEditingMember(string $memberId): void
    {
        $organization = $this->selectedOrganization();
        $this->authorizeManageMembers($organization);

        $member = $this->memberOrFail($organization, $memberId);
        $role = MemberRole::fromSpatieRoleName((string) data_get($member->getRelationValue('pivot'), 'role'));

        if ($role === MemberRole::Owner) {
            $this->errorToast(__('Transfer ownership before changing the owner role.'));

            return;
        }

        $this->editingMemberId = $memberId;
        $this->editingRole = $role instanceof MemberRole ? $role->value : MemberRole::Viewer->value;
    }

    public function cancelEditingMember(): void
    {
        $this->reset(['editingMemberId', 'editingRole']);
        $this->editingRole = MemberRole::Viewer->value;
    }

    public function saveMemberRole(MembershipCommands $membershipCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->authorizeManageMembers($organization);

        if ($this->editingMemberId === null) {
            abort(404);
        }

        $validated = $this->validate([
            'editingRole' => ['required', Rule::in($this->manageableRoles())],
        ]);
        $member = $this->memberOrFail($organization, $this->editingMemberId);

        OwnerContext::withOwner($organization, function () use ($membershipCommands, $member, $organization, $validated): void {
            $membershipCommands->changeMemberRole(
                MemberSubjectType::Organization,
                (string) $organization->getKey(),
                (string) $member->getKey(),
                (string) $validated['editingRole'],
            );
        });

        $this->cancelEditingMember();
        $this->successToast(__('Member role updated.'));
    }

    public function removeMember(string $memberId, MembershipCommands $membershipCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->authorizeManageMembers($organization);
        $member = $this->memberOrFail($organization, $memberId);

        try {
            OwnerContext::withOwner($organization, function () use ($membershipCommands, $member, $organization): void {
                $membershipCommands->removeMember(
                    MemberSubjectType::Organization,
                    (string) $organization->getKey(),
                    (string) $member->getKey(),
                );
            });
        } catch (Throwable $throwable) {
            report($throwable);
            $this->errorToast(__('This member cannot be removed. Transfer ownership first if needed.'));

            return;
        }

        $this->successToast(__('Member removed successfully.'));
    }

    public function transferOwnership(string $memberId, OrganizationCommands $organizationCommands): void
    {
        $organization = $this->selectedOrganization();
        $user = $this->currentUser();
        $member = $this->memberOrFail($organization, $memberId);

        OwnerContext::withOwner($organization, function () use ($member, $organization, $organizationCommands, $user): void {
            $organizationCommands->transferOwnership(
                (string) $organization->getKey(),
                (string) $user->getKey(),
                (string) $member->getKey(),
            );
        });

        $this->successToast(__('Organization ownership transferred.'));
    }

    public function revokeInvitation(
        string $invitationId,
        MembershipInvitations $membershipInvitations,
    ): void {
        $organization = $this->selectedOrganization();
        $user = $this->currentUser();
        $this->authorizeManageMembers($organization);

        $invitation = $membershipInvitations->find($invitationId);

        abort_unless(
            $invitation instanceof MembershipInvitationDetail
            && $invitation->subjectType === MemberSubjectType::Organization
            && $invitation->subjectId === (string) $organization->getKey(),
            404,
        );

        $membershipInvitations->revoke($invitationId, (string) $user->getKey());

        $this->successToast(__('Invitation revoked.'));
    }

    public function makePublic(OrganizationCommands $organizationCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-visibility');
        $organizationCommands->makePublic((string) $organization->getKey(), (string) $this->currentUser()->getKey());
        $this->successToast(__('Organization is now public.'));
    }

    public function makePrivate(OrganizationCommands $organizationCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-visibility');
        $organizationCommands->makePrivate((string) $organization->getKey(), (string) $this->currentUser()->getKey());
        $this->successToast(__('Organization is now private.'));
    }

    public function suspend(OrganizationCommands $organizationCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-status');
        $organizationCommands->suspend((string) $organization->getKey(), (string) $this->currentUser()->getKey());
        $this->successToast(__('Organization suspended.'));
    }

    public function restore(OrganizationCommands $organizationCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-status');
        $organizationCommands->restore((string) $organization->getKey(), (string) $this->currentUser()->getKey());
        $this->successToast(__('Organization restored.'));
    }

    public function archive(OrganizationCommands $organizationCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-status');
        $organizationCommands->archive((string) $organization->getKey(), (string) $this->currentUser()->getKey());
        $this->successToast(__('Organization archived.'));
    }

    public function updatedAddressCountryId(): void
    {
        $this->addressStateId = null;
    }

    public function saveAddress(SaveOrganizationAddressAction $saveOrganizationAddress): void
    {
        $organization = $this->selectedOrganization();
        $this->authorizeDonations();

        $this->addressStateId = $this->normalizeFormString($this->addressStateId);

        $validated = $this->validate([
            'addressCountryId' => ['required', 'uuid'],
            'addressStateId' => ['nullable', 'uuid'],
            'addressLine1' => ['nullable', 'string', 'max:255'],
            'addressLine2' => ['nullable', 'string', 'max:255'],
            'addressPostcode' => ['nullable', 'string', 'max:16'],
        ]);

        $saveOrganizationAddress->handle(
            (string) $this->currentUser()->getKey(),
            (string) $organization->getKey(),
            [
                'country_id' => $validated['addressCountryId'],
                'state_id' => $validated['addressStateId'],
                'line1' => $validated['addressLine1'],
                'line2' => $validated['addressLine2'],
                'postcode' => $validated['addressPostcode'],
            ],
        );

        $this->syncAddressState();
        $this->successToast(__('Organization location saved.'));
    }

    public function clearAddress(SaveOrganizationAddressAction $saveOrganizationAddress): void
    {
        $organization = $this->selectedOrganization();
        $this->authorizeDonations();

        $saveOrganizationAddress->handle(
            (string) $this->currentUser()->getKey(),
            (string) $organization->getKey(),
            null,
        );

        $this->syncAddressState();
        $this->successToast(__('Organization location cleared.'));
    }

    /** @return array<string, string> */
    public function countryOptions(): array
    {
        return collect(app(AddressCatalog::class)->countries())
            ->mapWithKeys(static fn (array $country): array => [$country['id'] => $country['label']])
            ->all();
    }

    /** @return array<string, string> */
    public function addressStateOptions(): array
    {
        if (! is_string($this->addressCountryId) || $this->addressCountryId === '') {
            return [];
        }

        return collect(app(AddressCatalog::class)->states($this->addressCountryId))
            ->mapWithKeys(static fn (array $state): array => [$state['id'] => $state['label']])
            ->all();
    }

    public function canManageDonations(): bool
    {
        try {
            $this->authorizeDonations();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<DonationChannelSummary> */
    public function channels(): array
    {
        return app(DonationChannelDirectory::class)->forOwner('organization', $this->organizationId);
    }

    /** @return array<string, string> */
    public function donationMethodOptions(): array
    {
        $labels = [
            'bank_account' => __('Bank Account'),
            'duitnow' => 'DuitNow',
            'ewallet' => __('E-Wallet'),
        ];

        return array_intersect_key($labels, array_flip($this->donationProfile()->methods));
    }

    /** @return array<array-key, string> */
    public function donationBankOptions(): array
    {
        return $this->donationProfile()->banks->options();
    }

    public function startChannel(): void
    {
        $this->authorizeDonations();

        $this->editingChannelId = null;
        $this->channelQr = null;
        $this->channelForm = [
            'label' => '',
            'recipient' => '',
            'method' => (string) (array_key_first($this->donationMethodOptions()) ?? ''),
            'currency' => $this->donationProfile()->default_currency ?? '',
            'bank_code' => null,
            'account_number' => '',
            'iban' => '',
            'swift_bic' => '',
            'routing_code' => '',
            'duitnow_type' => '',
            'duitnow_value' => '',
            'ewallet_provider' => '',
            'ewallet_handle' => '',
            'is_default' => false,
        ];
        $this->channelFormOpen = true;
        $this->resetValidation();
    }

    public function editChannel(string $channelId): void
    {
        $this->authorizeDonations();

        $channel = app(DonationChannelDirectory::class)->findForOwner('organization', $this->organizationId, $channelId);
        abort_unless($channel instanceof DonationChannelSummary, 404);

        $this->editingChannelId = $channel->id;
        $this->channelQr = null;
        $this->channelForm = [
            'label' => (string) ($channel->label ?? ''),
            'recipient' => $channel->recipient,
            'method' => $channel->method,
            'currency' => (string) ($channel->currency ?? ''),
            'bank_code' => $channel->bank_code,
            'account_number' => (string) ($channel->account_number ?? ''),
            'iban' => (string) ($channel->iban ?? ''),
            'swift_bic' => (string) ($channel->swift_bic ?? ''),
            'routing_code' => (string) ($channel->routing_code ?? ''),
            'duitnow_type' => (string) ($channel->duitnow_type ?? ''),
            'duitnow_value' => (string) ($channel->duitnow_value ?? ''),
            'ewallet_provider' => (string) ($channel->ewallet_provider ?? ''),
            'ewallet_handle' => (string) ($channel->ewallet_handle ?? ''),
            'is_default' => $channel->is_default,
        ];
        $this->channelFormOpen = true;
        $this->resetValidation();
    }

    public function cancelChannel(): void
    {
        $this->channelFormOpen = false;
        $this->editingChannelId = null;
        $this->channelQr = null;
        $this->channelForm = [];
        $this->resetValidation();
    }

    public function saveChannel(DonationChannelWorkflow $donationChannels): void
    {
        $organization = $this->selectedOrganization();
        $this->authorizeDonations();

        if ($this->editingChannelId !== null) {
            abort_unless(
                app(DonationChannelDirectory::class)->findForOwner('organization', (string) $organization->getKey(), $this->editingChannelId) instanceof DonationChannelSummary,
                404,
            );
        }

        $this->channelForm['currency'] = $this->normalizeFormString($this->channelForm['currency'] ?? null);
        $this->channelForm['bank_code'] = $this->normalizeFormString($this->channelForm['bank_code'] ?? null);

        $validated = $this->validate($this->channelRules());
        $form = is_array($validated['channelForm'] ?? null) ? $validated['channelForm'] : [];
        $wasEditing = $this->editingChannelId !== null;

        $payload = [
            'donatable_type' => 'organization',
            'donatable_id' => (string) $organization->getKey(),
            'label' => $this->normalizeFormString($form['label'] ?? null),
            'recipient' => (string) ($form['recipient'] ?? ''),
            'method' => (string) ($form['method'] ?? ''),
            'currency' => $this->normalizeFormString($form['currency'] ?? null),
            'bank_code' => $this->normalizeFormString($form['bank_code'] ?? null),
            'account_number' => $this->normalizeFormString($form['account_number'] ?? null),
            'iban' => $this->normalizeFormString($form['iban'] ?? null),
            'swift_bic' => $this->normalizeFormString($form['swift_bic'] ?? null),
            'routing_code' => $this->normalizeFormString($form['routing_code'] ?? null),
            'duitnow_type' => $this->normalizeFormString($form['duitnow_type'] ?? null),
            'duitnow_value' => $this->normalizeFormString($form['duitnow_value'] ?? null),
            'ewallet_provider' => $this->normalizeFormString($form['ewallet_provider'] ?? null),
            'ewallet_handle' => $this->normalizeFormString($form['ewallet_handle'] ?? null),
            'is_default' => (bool) ($form['is_default'] ?? false),
        ];

        if (is_object($this->channelQr)) {
            $payload['qr'] = $this->channelQr;
        }

        $donationChannels->save($payload, $this->editingChannelId);

        $this->cancelChannel();
        $this->successToast($wasEditing ? __('Donation channel updated.') : __('Donation channel added.'));
    }

    public function removeChannel(string $channelId, DonationChannelWorkflow $donationChannels): void
    {
        $this->authorizeDonations();

        abort_unless(
            app(DonationChannelDirectory::class)->findForOwner('organization', $this->organizationId, $channelId) instanceof DonationChannelSummary,
            404,
        );

        $donationChannels->delete([$channelId]);

        if ($this->editingChannelId === $channelId) {
            $this->cancelChannel();
        }

        $this->successToast(__('Donation channel removed.'));
    }

    /** @return Collection<int, User> */
    public function members(): Collection
    {
        $organization = $this->selectedOrganization();

        return OwnerContext::withOwner($organization, fn (): Collection => $organization->members()->orderBy('name')->get());
    }

    /** @return list<MembershipInvitationDetail> */
    public function invitations(): array
    {
        return app(MembershipInvitations::class)->recentForSubject(
            MemberSubjectType::Organization,
            (string) $this->selectedOrganization()->getKey(),
            20,
        );
    }

    /** @return list<OrganizationWorkspaceEvent> */
    public function events(): array
    {
        $organization = $this->selectedOrganization();

        return OwnerContext::withOwner($organization, fn (): array => app(EventScheduleReads::class)->organizationWorkspaceEvents(
            $organization->getMorphClass(),
            (string) $organization->getKey(),
            20,
        ));
    }

    /** @return array<string, string> */
    public function roleOptions(): array
    {
        return collect($this->manageableRoles())
            ->mapWithKeys(fn (string $role): array => [$role => MemberRole::from($role)->label()])
            ->all();
    }

    public function canManageMembers(): bool
    {
        try {
            $this->authorizeManageMembers($this->selectedOrganization());

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function canManageOrganization(): bool
    {
        try {
            $this->selectedOrganizationAuthorization('organization.change-status');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function render(): View
    {
        $organization = $this->selectedOrganization();
        $events = $this->events();
        $publicEventIds = [];
        $eventEditUrls = [];
        $eventScheduleUrls = [];
        $eventOfflineAdmissionsUrls = [];

        foreach ($events as $event) {
            if ($event->publiclyReachable) {
                $publicEventIds[$event->id] = true;
            }

            if ($event->canEdit) {
                $eventEditUrl = AdminResourceLink::to(
                    'event',
                    'edit',
                    $event->id,
                    panel: 'ahli',
                );

                if ($eventEditUrl !== null) {
                    $eventEditUrls[$event->id] = $eventEditUrl;
                }

                $eventScheduleUrls[$event->id] = route(
                    'dashboard.events.schedule',
                    ['event' => $event->id],
                );
            }

            if ($event->canManageAdmissions) {
                $eventOfflineAdmissionsUrls[$event->id] = route(
                    'dashboard.events.offline-admissions',
                    ['event' => $event->id],
                );
            }
        }

        return view('livewire.pages.dashboard.organizations.workspace', [
            'organization' => $organization,
            'members' => $this->members(),
            'invitations' => $this->invitations(),
            'events' => $events,
            'publicEventIds' => $publicEventIds,
            'eventEditUrls' => $eventEditUrls,
            'eventScheduleUrls' => $eventScheduleUrls,
            'eventOfflineAdmissionsUrls' => $eventOfflineAdmissionsUrls,
            'roleOptions' => $this->roleOptions(),
            'canManageMembers' => $this->canManageMembers(),
            'canManageOrganization' => $this->canManageOrganization(),
            'canManageDonations' => $this->canManageDonations(),
            'donationChannels' => $this->channels(),
            'donationMethodOptions' => $this->donationMethodOptions(),
            'donationBankOptions' => $this->donationBankOptions(),
            'donationRoutingSupported' => $this->donationProfile()->supports_routing_codes,
            'donationPublicUrl' => $organization->isPublic() ? route('organizations.show', $organization->slug) : null,
            'addressState' => app(OrganizationProfileService::class)->addressStateFor($this->organizationId),
            'countryOptions' => $this->countryOptions(),
            'addressStateOptions' => $this->addressStateOptions(),
        ]);
    }

    private function syncAddressState(): void
    {
        $state = app(OrganizationProfileService::class)->addressStateFor($this->organizationId) ?? [];

        $this->addressCountryId = is_string($state['country_id'] ?? null) ? $state['country_id'] : null;
        $this->addressStateId = is_string($state['state_id'] ?? null) ? $state['state_id'] : null;
        $this->addressLine1 = (string) ($state['line1'] ?? '');
        $this->addressLine2 = (string) ($state['line2'] ?? '');
        $this->addressPostcode = (string) ($state['postcode'] ?? '');
    }

    private function authorizeDonations(): void
    {
        $organization = $this->selectedOrganization();

        abort_unless(
            app(OrganizationAccess::class)->can(
                (string) $this->currentUser()->getKey(),
                (string) $organization->getKey(),
                'organization.update',
            ),
            403,
        );
    }

    private function donationProfile(): DonationChannelCountryProfile
    {
        return app(DonationChannelCountryProfiles::class)->forOwner($this->selectedOrganization());
    }

    /**
     * @return array<string, mixed>
     */
    private function channelRules(): array
    {
        $method = $this->channelForm['method'] ?? null;

        $rules = [
            'channelForm.label' => ['nullable', 'string', 'max:255'],
            'channelForm.recipient' => ['required', 'string', 'max:255'],
            'channelForm.method' => ['required', Rule::in(['bank_account', 'duitnow', 'ewallet'])],
            'channelForm.currency' => ['nullable', 'string', 'size:3'],
            'channelForm.bank_code' => ['nullable', 'string', 'max:32'],
            'channelForm.account_number' => ['nullable', 'string', 'max:64'],
            'channelForm.iban' => ['nullable', 'string', 'max:34'],
            'channelForm.swift_bic' => ['nullable', 'string', 'max:11'],
            'channelForm.routing_code' => ['nullable', 'string', 'max:20'],
            'channelForm.duitnow_type' => ['nullable', 'string', 'max:64'],
            'channelForm.duitnow_value' => ['nullable', 'string', 'max:255'],
            'channelForm.ewallet_provider' => ['nullable', 'string', 'max:64'],
            'channelForm.ewallet_handle' => ['nullable', 'string', 'max:255'],
            'channelForm.is_default' => ['boolean'],
            'channelQr' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.$this->maxQrUploadKb()],
        ];

        return match ($method) {
            'bank_account' => [
                ...$rules,
                'channelForm.bank_code' => ['required', 'string', 'max:32'],
                'channelForm.account_number' => ['nullable', 'string', 'max:64', 'required_without:channelForm.iban'],
                'channelForm.iban' => ['nullable', 'string', 'max:34', 'required_without:channelForm.account_number'],
            ],
            'duitnow' => [
                ...$rules,
                'channelForm.duitnow_type' => ['required', 'string', 'max:64'],
                'channelForm.duitnow_value' => ['required', 'string', 'max:255'],
            ],
            'ewallet' => [
                ...$rules,
                'channelForm.ewallet_provider' => ['required', 'string', 'max:64'],
                'channelForm.ewallet_handle' => ['required', 'string', 'max:255'],
            ],
            default => $rules,
        };
    }

    private function maxQrUploadKb(): int
    {
        return (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);
    }

    private function normalizeFormString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /** @return list<string> */
    private function manageableRoles(): array
    {
        return [MemberRole::Admin->value, MemberRole::Editor->value, MemberRole::Viewer->value];
    }

    private function selectedOrganization(): Organization
    {
        $user = $this->currentUser();

        /** @var Organization $organization */
        $organization = Organization::query()
            ->whereKey($this->organizationId)
            ->whereHas('members', fn (Builder $query): Builder => $query->whereKey($user->getKey()))
            ->firstOrFail();

        abort_unless(
            app(OrganizationAccess::class)->can((string) $user->getKey(), (string) $organization->getKey(), 'organization.view'),
            403,
        );

        return $organization;
    }

    private function selectedOrganizationAuthorization(string $ability): void
    {
        $organization = $this->selectedOrganization();

        abort_unless(
            app(OrganizationAccess::class)->can((string) $this->currentUser()->getKey(), (string) $organization->getKey(), $ability),
            403,
        );
    }

    private function authorizeManageMembers(Organization $organization): void
    {
        abort_unless(
            app(OrganizationAccess::class)->can(
                (string) $this->currentUser()->getKey(),
                (string) $organization->getKey(),
                'organization.manage-members',
            ),
            403,
        );
    }

    private function currentUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function memberOrFail(Organization $organization, string $memberId): User
    {
        $member = $organization->members()->whereKey($memberId)->firstOrFail();

        abort_unless($member instanceof User, 404);

        return $member;
    }
}
