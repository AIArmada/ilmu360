<?php

use App\Features\DonationChannels\Actions\DeleteDonationBankAction;
use App\Features\DonationChannels\Actions\SaveDonationBankAction;
use App\Features\DonationChannels\Actions\SaveDonationChannelAction;
use App\Features\DonationChannels\Actions\TransitionDonationBankStatusAction;
use App\Features\DonationChannels\Api\Enums\DonationBankStatus;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\DonationBankResource;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\Pages\CreateDonationBank;
use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationBanks\Pages\EditDonationBank;
use App\Features\DonationChannels\Models\DonationBank;
use App\Features\Institutions\Models\Institution;
use App\Models\User;
use App\Platform\Admin\ExcludedFromAdminApi;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Admin\AdminResourceRegistry;
use Database\Seeders\DonationBankCatalogSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function donationBankAdmin(): User
{
    test()->seed(PermissionSeeder::class);
    test()->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    return $admin;
}

it('registers the bank catalog once in the admin panel and keeps it out of the admin api', function () {
    $panelResources = Filament::getPanel('admin')->getResources();

    expect(DonationBankResource::class)->toImplement(ExcludedFromAdminApi::class)
        ->and(app(FilamentResourceRegistry::class)->all())->toContain(DonationBankResource::class)
        ->and(array_count_values($panelResources)[DonationBankResource::class] ?? 0)->toBe(1)
        ->and(app(AdminResourceRegistry::class)->resources())->not->toContain(DonationBankResource::class);
});

it('lists catalog entries for global admins and denies moderators', function () {
    $admin = donationBankAdmin();

    $this->actingAs($admin)
        ->get(DonationBankResource::getUrl('index'))
        ->assertSuccessful();

    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $this->actingAs($moderator)
        ->get(DonationBankResource::getUrl('index'))
        ->assertForbidden();
});

it('creates a catalog entry through the Filament resource with a normalized code', function () {
    $admin = donationBankAdmin();
    $country = ensureTestAddressCountry('MY', 'Malaysia');

    Livewire::actingAs($admin)
        ->test(CreateDonationBank::class)
        ->fillForm([
            'country_id' => (string) $country->getKey(),
            'code' => '  msb-t1  ',
            'name' => '  My New Bank  ',
            'source_url' => 'https://example.com/source/my-new-bank',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $bank = DonationBank::query()->where('code', 'MSB-T1')->sole();

    expect($bank->name)->toBe('My New Bank')
        ->and($bank->status)->toBe(DonationBankStatus::Active)
        ->and((string) $bank->country_id)->toBe((string) $country->getKey())
        ->and((string) $bank->source_url)->toBe('https://example.com/source/my-new-bank');
});

it('renames a catalog entry through the Filament edit page', function () {
    $admin = donationBankAdmin();
    $bank = DonationBank::query()->where('code', 'MB2U0227')->sole();

    Livewire::actingAs($admin)
        ->test(EditDonationBank::class, ['record' => $bank->getRouteKey()])
        ->fillForm([
            'name' => 'Malayan Banking Berhad (retail)',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($bank->fresh()->name)->toBe('Malayan Banking Berhad (retail)');
});

it('rejects duplicate codes inside one country and allows them in another', function () {
    $this->actingAs(donationBankAdmin());
    $indonesia = ensureTestAddressCountry('ID', 'Indonesia');

    expect(fn () => app(SaveDonationBankAction::class)->handle([
        'country_id' => testMalaysiaCountryId(),
        'code' => 'bcbb0235',
        'name' => 'Duplicate CIMB',
        'source_url' => 'https://example.com/source/duplicate-cimb',
    ]))->toThrow(ValidationException::class);

    $bank = app(SaveDonationBankAction::class)->handle([
        'country_id' => (string) $indonesia->getKey(),
        'code' => 'BCBB0235',
        'name' => 'Indonesian BCBB',
        'source_url' => 'https://example.com/source/indonesian-bcbb',
    ]);

    expect($bank->code)->toBe('BCBB0235')
        ->and((string) $bank->country_id)->toBe((string) $indonesia->getKey());
});

it('locks the identity of a referenced bank while allowing renames', function () {
    $this->actingAs(donationBankAdmin());
    $institution = Institution::factory()->create();
    $indonesia = ensureTestAddressCountry('ID', 'Indonesia');

    $bank = DonationBank::query()->where('code', 'MB2U0227')->sole();

    app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '123456789',
    ]);

    $renamed = app(SaveDonationBankAction::class)->handle([
        'name' => 'Maybank (personal)',
    ], $bank);

    expect($renamed->name)->toBe('Maybank (personal)');

    expect(fn () => app(SaveDonationBankAction::class)->handle([
        'country_id' => (string) $indonesia->getKey(),
    ], $renamed))->toThrow(ValidationException::class);

    expect(fn () => app(SaveDonationBankAction::class)->handle([
        'code' => 'MBB0227',
    ], $renamed))->toThrow(ValidationException::class);

    expect(fn () => app(DeleteDonationBankAction::class)->handle($renamed))
        ->toThrow(ValidationException::class);

    expect(DonationBank::query()->whereKey($bank->getKey())->exists())->toBeTrue();
});

it('retires and reactivates a bank through the catalog workflow', function () {
    $this->actingAs(donationBankAdmin());

    $bank = DonationBank::factory()->create(['code' => 'FACT0001']);

    $retired = app(TransitionDonationBankStatusAction::class)->handle($bank, DonationBankStatus::Retired);

    expect($retired->status)->toBe(DonationBankStatus::Retired)
        ->and($retired->retired_at)->not->toBeNull()
        ->and($retired->last_state_change_at)->not->toBeNull();

    $reactivated = app(TransitionDonationBankStatusAction::class)->handle($retired, DonationBankStatus::Active);

    expect($reactivated->status)->toBe(DonationBankStatus::Active)
        ->and($reactivated->retired_at)->not->toBeNull()
        ->and($reactivated->reactivated_at)->not->toBeNull();
});

it('deletes an unused bank and blocks deleting a referenced one', function () {
    $this->actingAs(donationBankAdmin());
    $institution = Institution::factory()->create();

    $unused = app(SaveDonationBankAction::class)->handle([
        'country_id' => testMalaysiaCountryId(),
        'code' => 'UNUSED01',
        'name' => 'Unused Bank',
        'source_url' => 'https://example.com/source/unused-bank',
    ]);

    app(DeleteDonationBankAction::class)->handle($unused);

    expect(DonationBank::query()->where('code', 'UNUSED01')->exists())->toBeFalse();

    $referenced = app(SaveDonationBankAction::class)->handle([
        'country_id' => testMalaysiaCountryId(),
        'code' => 'USED0001',
        'name' => 'Used Bank',
        'source_url' => 'https://example.com/source/used-bank',
    ]);

    app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'bank_account',
        'bank_code' => 'USED0001',
        'account_number' => '123456789',
    ]);

    expect(fn () => app(DeleteDonationBankAction::class)->handle($referenced))
        ->toThrow(ValidationException::class);

    expect(DonationBank::query()->whereKey($referenced->getKey())->exists())->toBeTrue();
});

it('denies catalog writes to moderators', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $bank = DonationBank::query()->where('code', 'MB2U0227')->sole();

    $this->actingAs($moderator);

    expect(fn () => app(SaveDonationBankAction::class)->handle([
        'name' => 'Moderator Rename',
    ], $bank))->toThrow(AuthorizationException::class);

    expect(fn () => app(TransitionDonationBankStatusAction::class)->handle($bank, DonationBankStatus::Retired))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(DeleteDonationBankAction::class)->handle($bank))
        ->toThrow(AuthorizationException::class);

    expect($bank->fresh()->name)->toBe('Malayan Banking Berhad')
        ->and($bank->fresh()->status)->toBe(DonationBankStatus::Active);
});

it('never overwrites or resurrects entries when the bootstrap seeder runs again', function () {
    $renamed = DonationBank::query()->where('code', 'MB2U0227')->sole();
    $renamed->name = 'Renamed By Admin';
    $renamed->save();

    DonationBank::query()->where('code', 'BCBB0235')->sole()->delete();

    app(DonationBankCatalogSeeder::class)->run();

    expect(DonationBank::query()->where('code', 'MB2U0227')->value('name'))->toBe('Renamed By Admin')
        ->and(DonationBank::query()->where('code', 'BCBB0235')->exists())->toBeFalse();
});

it('initializes a country only once even after all its banks are deleted', function () {
    $singapore = ensureTestAddressCountry('SG', 'Singapore');

    expect(DonationBank::query()->where('country_id', $singapore->getKey())->count())->toBe(0);

    seedDonationBankCatalogForTests();

    expect(DonationBank::query()->where('country_id', $singapore->getKey())->count())->toBe(5);

    DonationBank::query()
        ->where('country_id', $singapore->getKey())
        ->where('code', '7171')
        ->delete();

    seedDonationBankCatalogForTests();

    expect(DonationBank::query()->where('country_id', $singapore->getKey())->count())->toBe(4);

    DonationBank::query()->where('country_id', $singapore->getKey())->delete();

    seedDonationBankCatalogForTests();

    expect(DonationBank::query()->where('country_id', $singapore->getKey())->exists())->toBeFalse();
});
