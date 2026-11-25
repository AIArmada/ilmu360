<?php

use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Organizations\Actions\CreateOrganizationAction;
use AIArmada\Organizations\Actions\MakeOrganizationPublicAction;
use AIArmada\Organizations\Models\Organization;
use App\Features\DonationChannels\Actions\SaveDonationChannelAction;
use App\Features\DonationChannels\Api\DonationChannelCountryProfiles;
use App\Features\DonationChannels\Api\DonationChannelDirectory;
use App\Features\DonationChannels\Api\DonationChannelWorkflow;
use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Features\Organizations\Actions\SaveOrganizationAddressAction;
use App\Features\Organizations\EntryPoints\Livewire\Pages\Workspace;
use App\Features\Organizations\Services\OrganizationProfileService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function organizationDonationFixture(User $owner, ?string $countryIso2 = 'MY'): Organization
{
    $organization = CreateOrganizationAction::make()->handle($owner, ['name' => 'Rumah Amal Ujian']);

    if ($countryIso2 === null) {
        return $organization;
    }

    $country = ensureTestAddressCountry($countryIso2, match ($countryIso2) {
        'MY' => 'Malaysia',
        'ID' => 'Indonesia',
        'SG' => 'Singapore',
        'AQ' => 'Antarctica',
        default => $countryIso2,
    });

    // Install the bootstrap country catalog so bank validation resolves.
    seedDonationBankCatalogForTests();

    app(SaveOrganizationAddressAction::class)->handle(
        (string) $owner->getKey(),
        (string) $organization->getKey(),
        ['country_id' => (string) $country->getKey(), 'line1' => 'Jalan Amal 1'],
    );

    return $organization;
}

function organizationDonationChannels(Organization $organization): array
{
    return app(DonationChannelDirectory::class)->forOwner('organization', (string) $organization->getKey());
}

it('resolves the organization donation country from the canonical primary address', function () {
    $owner = User::factory()->create();
    $organization = organizationDonationFixture($owner, null);

    $profiles = app(DonationChannelCountryProfiles::class);

    expect($profiles->forOwner($organization)->country_code)->toBeNull()
        ->and($profiles->forOwner($organization)->methods)->toBe([]);

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $organization->getKey(),
        'recipient' => 'Rumah Amal Ujian',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '1234567890',
    ]))->toThrow(ValidationException::class);

    $countryId = testMalaysiaCountryId();

    app(SaveOrganizationAddressAction::class)->handle(
        (string) $owner->getKey(),
        (string) $organization->getKey(),
        ['country_id' => $countryId, 'line1' => 'Jalan Amal 1'],
    );

    $organization->refresh();

    expect($profiles->forOwner($organization)->country_code)->toBe('MY')
        ->and($profiles->forOwner($organization)->methods)->toBe(['bank_account', 'duitnow', 'ewallet'])
        ->and(app(OrganizationProfileService::class)->countryIdFor((string) $organization->getKey()))->toBe($countryId);

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $organization->getKey(),
        'recipient' => 'Rumah Amal Ujian',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '1234567890',
    ]);

    expect($channel->donatable_type)->toBe('organization')
        ->and($channel->bank_name)->toBe('Malayan Banking Berhad')
        ->and($channel->status)->toBe(DonationChannelStatus::Pending);
});

it('rejects organization channels outside catalogue coverage', function () {
    $owner = User::factory()->create();
    $organization = organizationDonationFixture($owner, 'AQ');

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $organization->getKey(),
        'recipient' => 'Yayasan Ujian',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '1234567890',
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $organization->getKey(),
        'recipient' => 'Yayasan Ujian',
        'method' => 'duitnow',
        'duitnow_type' => 'business',
        'duitnow_value' => '12345678',
    ]))->toThrow(ValidationException::class);

    expect(organizationDonationChannels($organization))->toBe([]);
});

it('rejects an organization country change that leaves a wrong-country bank behind', function () {
    $owner = User::factory()->create();
    $malaysiaId = testMalaysiaCountryId();
    $organization = organizationDonationFixture($owner, 'MY');

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $organization->getKey(),
        'recipient' => 'Rumah Amal Ujian',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '1234567890',
    ]);

    $indonesia = ensureTestAddressCountry('ID', 'Indonesia');
    $indonesiaId = (string) $indonesia->getKey();

    expect(fn () => app(SaveOrganizationAddressAction::class)->handle(
        (string) $owner->getKey(),
        (string) $organization->getKey(),
        ['country_id' => $indonesiaId, 'line1' => 'Jalan Baru 1'],
    ))->toThrow(ValidationException::class);

    expect(app(OrganizationProfileService::class)->countryIdFor((string) $organization->getKey()))->toBe($malaysiaId);

    app(DonationChannelWorkflow::class)->delete([(string) $channel->getKey()]);

    app(SaveOrganizationAddressAction::class)->handle(
        (string) $owner->getKey(),
        (string) $organization->getKey(),
        ['country_id' => $indonesiaId, 'line1' => 'Jalan Baru 1'],
    );

    expect(app(OrganizationProfileService::class)->countryIdFor((string) $organization->getKey()))->toBe($indonesiaId)
        ->and(organizationDonationChannels($organization))->toBe([]);
});

it('lets organization administrators manage the address and channels from the workspace', function () {
    $owner = User::factory()->create();
    $organization = organizationDonationFixture($owner, null);
    $countryId = testMalaysiaCountryId();

    Livewire::actingAs($owner)
        ->test(Workspace::class, ['organization' => $organization])
        ->assertSet('organizationId', (string) $organization->getKey())
        ->set('addressCountryId', $countryId)
        ->set('addressLine1', 'Jalan Amal 2')
        ->call('saveAddress')
        ->assertHasNoErrors();

    $state = app(OrganizationProfileService::class)->addressStateFor((string) $organization->getKey());

    expect($state['line1'])->toBe('Jalan Amal 2')
        ->and($state['country_id'])->toBe($countryId);

    Livewire::actingAs($owner)
        ->test(Workspace::class, ['organization' => $organization])
        ->call('startChannel')
        ->set('channelForm.label', 'Tabung Beras')
        ->set('channelForm.recipient', 'Rumah Amal Ujian')
        ->set('channelForm.method', 'bank_account')
        ->set('channelForm.bank_code', 'MB2U0227')
        ->set('channelForm.account_number', '1234567890')
        ->call('saveChannel')
        ->assertHasNoErrors();

    $channel = DonationChannel::query()
        ->where('donatable_type', 'organization')
        ->where('donatable_id', (string) $organization->getKey())
        ->firstOrFail();

    expect($channel->bank_name)->toBe('Malayan Banking Berhad')
        ->and($channel->status)->toBe(DonationChannelStatus::Pending);

    Livewire::actingAs($owner)
        ->test(Workspace::class, ['organization' => $organization])
        ->assertSee('Tabung Beras')
        ->call('removeChannel', (string) $channel->getKey())
        ->assertHasNoErrors();

    expect(organizationDonationChannels($organization))->toBe([]);
});

it('rejects saving a donation channel that belongs to a different organization', function () {
    $owner = User::factory()->create();
    $organization = organizationDonationFixture($owner);
    $other = organizationDonationFixture(User::factory()->create());
    $channel = DonationChannel::factory()->bankAccount()->create([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $other->getKey(),
    ]);

    Livewire::actingAs($owner)
        ->test(Workspace::class, ['organization' => $organization])
        ->set('editingChannelId', (string) $channel->getKey())
        ->call('saveChannel')
        ->assertNotFound();

    expect($channel->fresh()->donatable_id)->toBe($other->getKey());
});

it('keeps viewers read only and forbids non-members on the organization workspace', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $outsider = User::factory()->create();
    $organization = organizationDonationFixture($owner, 'MY');

    addTestMember($organization, $viewer, MemberRole::Viewer);

    $this->actingAs($outsider)
        ->get(route('dashboard.organizations.show', $organization))
        ->assertForbidden();

    $component = Livewire::actingAs($viewer)
        ->test(Workspace::class, ['organization' => $organization])
        ->assertSee(__('Donation channels'))
        ->assertDontSee(__('Add channel'));

    expect($component->instance()->canManageDonations())->toBeFalse();

    expect(fn () => app(SaveOrganizationAddressAction::class)->handle(
        (string) $viewer->getKey(),
        (string) $organization->getKey(),
        ['country_id' => testMalaysiaCountryId(), 'line1' => 'Jalan Penonton 1'],
    ))->toThrow(AuthorizationException::class);

    expect(app(OrganizationProfileService::class)->addressStateFor((string) $organization->getKey())['line1'] ?? null)
        ->toBe('Jalan Amal 1');
});

it('exposes only verified donation channels on the public organization profile', function () {
    $owner = User::factory()->create();
    $organization = organizationDonationFixture($owner, 'MY');

    $verified = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $organization->getKey(),
        'recipient' => 'Rumah Amal Awam',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '1234567890',
        'label' => 'Tabung Amal',
    ]);
    $verified->verify();

    app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $organization->getKey(),
        'recipient' => 'Rumah Amal Belum Sah',
        'method' => 'bank_account',
        'bank_code' => 'BCBB0235',
        'account_number' => '0987654321',
    ]);

    MakeOrganizationPublicAction::make()->handle($organization, $owner);

    $this->getJson(route('api.client.organizations.show', ['organizationKey' => $organization->slug]))
        ->assertOk()
        ->assertJsonCount(1, 'data.donation_channels')
        ->assertJsonPath('data.donation_channels.0.label', 'Tabung Amal')
        ->assertJsonPath('data.donation_channels.0.bank_name', 'Malayan Banking Berhad')
        ->assertJsonPath('data.donation_channels.0.recipient', 'Rumah Amal Awam')
        ->assertJsonPath('data.donation_channels.0.method_display', 'Bank Account');
});

it('creates a Singapore organization bank channel with a branch routing code', function () {
    $owner = User::factory()->create();
    $organization = organizationDonationFixture($owner, 'SG');

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $organization->getKey(),
        'recipient' => 'Rumah Amal Singapura',
        'method' => 'bank_account',
        'bank_code' => '7171',
        'routing_code' => '081',
        'account_number' => '123456789',
    ]);

    $summary = collect(organizationDonationChannels($organization))->first();

    expect($channel->bank_code)->toBe('7171')
        ->and($channel->bank_name)->toBe('DBS Bank / POSB')
        ->and($channel->routing_code)->toBe('081')
        ->and($channel->payment_details)->toBe('DBS Bank / POSB - 123456789 (Routing: 081)')
        ->and($channel->status)->toBe(DonationChannelStatus::Pending)
        ->and($summary)->not->toBeNull()
        ->and($summary->routing_code)->toBe('081')
        ->and($summary->bank_name)->toBe('DBS Bank / POSB');
});

it('rejects a wrong-country bank code for an international organization', function () {
    $owner = User::factory()->create();
    $organization = organizationDonationFixture($owner, 'SG');

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $organization->getKey(),
        'recipient' => 'Rumah Amal Singapura',
        'method' => 'bank_account',
        'bank_code' => 'CENAIDJA',
        'account_number' => '123456789',
    ]))->toThrow(ValidationException::class);

    expect(organizationDonationChannels($organization))->toBe([]);
});

it('rejects routing codes for countries whose banks do not use them', function () {
    $owner = User::factory()->create();
    $organization = organizationDonationFixture($owner, 'MY');

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $organization->getKey(),
        'recipient' => 'Rumah Amal Ujian',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'routing_code' => '014',
        'account_number' => '1234567890',
    ]))->toThrow(ValidationException::class);

    expect(organizationDonationChannels($organization))->toBe([]);
});

it('requires reverification when only the routing code changes', function () {
    $owner = User::factory()->create();
    $organization = organizationDonationFixture($owner, 'SG');

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $organization->getKey(),
        'recipient' => 'Rumah Amal Singapura',
        'method' => 'bank_account',
        'bank_code' => '7171',
        'routing_code' => '081',
        'account_number' => '123456789',
    ]);

    $channel->verify();

    $reformatted = app(SaveDonationChannelAction::class)->handle([
        'routing_code' => ' 0 81 ',
    ], $channel);

    expect($reformatted->routing_code)->toBe('081')
        ->and($reformatted->status)->toBe(DonationChannelStatus::Verified);

    $changed = app(SaveDonationChannelAction::class)->handle([
        'routing_code' => '005',
    ], $reformatted);

    expect($changed->routing_code)->toBe('005')
        ->and($changed->status)->toBe(DonationChannelStatus::Pending)
        ->and($changed->verified_at)->toBeNull();
});

it('renders verified donation channels on the public organization page', function () {
    $owner = User::factory()->create();
    $organization = organizationDonationFixture($owner, 'MY');

    $verified = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $organization->getKey(),
        'recipient' => 'Rumah Amal Awam',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '1234567890',
        'label' => 'Tabung Amal',
    ]);
    $verified->verify();

    app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'organization',
        'donatable_id' => (string) $organization->getKey(),
        'recipient' => 'Rumah Amal Belum Sah',
        'method' => 'bank_account',
        'bank_code' => 'BCBB0235',
        'account_number' => '0987654321',
    ]);

    $this->get(route('organizations.show', ['organization' => $organization->slug]))
        ->assertNotFound();

    MakeOrganizationPublicAction::make()->handle($organization, $owner);

    $this->get(route('organizations.show', ['organization' => $organization->slug]))
        ->assertSuccessful()
        ->assertSee('Tabung Amal')
        ->assertSee('Rumah Amal Awam')
        ->assertSee('Malayan Banking Berhad')
        ->assertDontSee('Rumah Amal Belum Sah');

    Livewire::actingAs($owner)
        ->test(Workspace::class, ['organization' => $organization])
        ->assertSee(__('View public profile'))
        ->assertSee(route('organizations.show', $organization->slug), false);
});
