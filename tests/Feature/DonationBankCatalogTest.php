<?php

use App\Features\DonationChannels\Actions\SaveDonationChannelAction;
use App\Features\DonationChannels\Api\DonationBankCatalogs;
use App\Features\DonationChannels\Api\DonationChannelCountryProfiles;
use App\Features\DonationChannels\Api\Enums\DonationBankStatus;
use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;
use App\Features\DonationChannels\Models\DonationBank;
use App\Features\Institutions\Models\Institution;
use Illuminate\Validation\ValidationException;

function bankCatalogForeignInstitution(string $iso2 = 'AQ', string $name = 'Antarctica'): Institution
{
    $country = ensureTestAddressCountry($iso2, $name);
    seedDonationBankCatalogForTests();
    $institution = Institution::factory()->create();

    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) $country->getKey(),
        'country_code' => $iso2,
        'line1' => 'Jalan Internasional 1',
        'postcode' => '10110',
    ]);

    return $institution->fresh();
}

it('exposes the malaysia bank catalogue without routing codes and identifies uncovered countries', function () {
    $catalogs = app(DonationBankCatalogs::class);

    $malaysia = $catalogs->forCountryCode('my');

    expect($malaysia->country_code)->toBe('MY')
        ->and($malaysia->covers())->toBeTrue()
        ->and($malaysia->options())->toHaveKey('MB2U0227')
        ->and($malaysia->name('mb2u0227'))->toBe('Malayan Banking Berhad')
        ->and($malaysia->name('MBSB001'))->toBe('MBSB Bank Berhad')
        ->and($malaysia->name('AGRO01'))->toBe('Bank Pertanian Malaysia Berhad (Agrobank)')
        ->and($malaysia->has('NOT-A-BANK'))->toBeFalse()
        ->and($malaysia->supports_routing_codes)->toBeFalse();

    expect($catalogs->forCountryCode('AQ')->covers())->toBeFalse()
        ->and($catalogs->forCountryCode(null)->covers())->toBeFalse();
});

it('ships a sourced bank catalogue for countries beyond the curated payment rails', function () {
    $path = database_path('seeders/data/donation-bank-catalogs.json');

    expect(is_file($path))->toBeTrue();

    $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $countries = $payload['countries'] ?? [];

    expect($payload['meta']['license'] ?? null)->toBe('MIT')
        ->and(count($countries))->toBeGreaterThan(200)
        ->and($countries)->toHaveKeys(['US', 'GB', 'DE', 'NG', 'RU', 'AF'])
        ->and($countries)->not->toHaveKeys(['MY', 'ID', 'SG']);

    $duplicates = [];
    $invalid = [];

    foreach ($countries as $iso2 => $catalog) {
        if (! is_string($catalog['source_url'] ?? null) || $catalog['source_url'] === '') {
            $invalid[] = "{$iso2}:missing-source";
        }

        $seen = [];

        foreach (($catalog['banks'] ?? []) as $bank) {
            $code = is_string($bank['code'] ?? null) ? $bank['code'] : '';
            $name = is_string($bank['name'] ?? null) ? $bank['name'] : '';

            if ($code === '' || $name === '' || mb_strlen($name) > 255 || preg_match('/^[A-Z0-9]{8,11}$/', $code) !== 1) {
                $invalid[] = "{$iso2}:{$code}";

                continue;
            }

            if (isset($seen[$code])) {
                $duplicates[] = "{$iso2}:{$code}";
            }

            $seen[$code] = true;
        }
    }

    expect($duplicates)->toBe([])
        ->and($invalid)->toBe([]);
});

it('seeds global catalogues outside the curated payment rails and leaves uncovered countries empty', function () {
    ensureTestAddressCountry('US', 'United States');
    ensureTestAddressCountry('GB', 'United Kingdom');
    ensureTestAddressCountry('AQ', 'Antarctica');
    seedDonationBankCatalogForTests();

    $catalogs = app(DonationBankCatalogs::class);

    expect($catalogs->forCountryCode('US')->covers())->toBeTrue()
        ->and(count($catalogs->forCountryCode('US')->options()))->toBeGreaterThan(100)
        ->and($catalogs->forCountryCode('GB')->covers())->toBeTrue()
        ->and($catalogs->forCountryCode('AQ')->covers())->toBeFalse();
});

it('falls back to the parent banking system for territories without their own catalog', function () {
    ensureTestAddressCountry('FI', 'Finland');
    ensureTestAddressCountry('AX', 'Aland');
    ensureTestAddressCountry('IT', 'Italy');
    ensureTestAddressCountry('VA', 'Vatican City');
    seedDonationBankCatalogForTests();

    $catalogs = app(DonationBankCatalogs::class);

    expect($catalogs->forCountryCode('AX')->covers())->toBeTrue()
        ->and($catalogs->forCountryCode('AX')->country_code)->toBe('AX')
        ->and($catalogs->forCountryCode('AX')->name('AABAFI22'))->toBe('Bank of Aland')
        ->and($catalogs->forCountryCode('VA')->covers())->toBeTrue()
        ->and($catalogs->forCountryCode('VA')->country_code)->toBe('VA')
        ->and($catalogs->forCountryCode('VA')->name('ALLFITMM'))->toBe('Allfunds Bank');
});

it('exposes the indonesian and singapore catalogues with their routing codes', function () {
    ensureTestAddressCountry('ID', 'Indonesia');
    ensureTestAddressCountry('SG', 'Singapore');
    seedDonationBankCatalogForTests();

    $catalogs = app(DonationBankCatalogs::class);

    $indonesia = $catalogs->forCountryCode('id');

    expect($indonesia->country_code)->toBe('ID')
        ->and($indonesia->covers())->toBeTrue()
        ->and($indonesia->name('cenaidja'))->toBe('Bank Central Asia')
        ->and($indonesia->name('BMRIIDJA'))->toBe('Bank Mandiri')
        ->and($indonesia->has('7171'))->toBeFalse()
        ->and($indonesia->supports_routing_codes)->toBeTrue();

    $singapore = $catalogs->forCountryCode('SG');

    expect($singapore->country_code)->toBe('SG')
        ->and($singapore->covers())->toBeTrue()
        ->and($singapore->name('7171'))->toBe('DBS Bank / POSB')
        ->and($singapore->name('7339'))->toBe('OCBC Bank')
        ->and($singapore->name('7375'))->toBe('UOB')
        ->and($singapore->name('9779'))->toBe('MariBank Singapore')
        ->and($singapore->has('CENAIDJA'))->toBeFalse()
        ->and($singapore->supports_routing_codes)->toBeTrue();
});

it('returns Singapore bank codes as strings in the public catalog', function () {
    $country = ensureTestAddressCountry('SG', 'Singapore');
    seedDonationBankCatalogForTests();

    $this->getJson(route('api.client.catalogs.donation-banks', ['country_id' => $country->getKey()]))
        ->assertOk()
        ->assertJsonPath('data.0.code', '7171')
        ->assertJsonPath('data.0.name', 'DBS Bank / POSB')
        ->assertJsonPath('meta.supports_routing_codes', true);
});

it('derives the stored bank name from the selected catalogue bank', function () {
    $institution = Institution::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'bank_account',
        'bank_code' => 'mb2u0227',
        'account_number' => '123456789',
    ]);

    expect($channel->bank_code)->toBe('MB2U0227')
        ->and($channel->bank_name)->toBe('Malayan Banking Berhad')
        ->and($channel->currency)->toBe('MYR');
});

it('accepts a supplied bank name that matches the catalogue name', function () {
    $institution = Institution::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'bank_name' => 'malayan banking berhad',
        'account_number' => '123456789',
    ]);

    expect($channel->bank_name)->toBe('Malayan Banking Berhad');
});

it('rejects a supplied bank name that does not match the selected bank', function () {
    $institution = Institution::factory()->create();

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'bank_name' => 'Westminster Bank',
        'account_number' => '123456789',
    ]))->toThrow(ValidationException::class);

    expect($institution->fresh()->donationChannels()->count())->toBe(0);
});

it('rejects an unknown bank code for a covered country', function () {
    $institution = Institution::factory()->create();

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'bank_account',
        'bank_code' => 'NOT-A-BANK',
        'account_number' => '123456789',
    ]))->toThrow(ValidationException::class);

    expect($institution->fresh()->donationChannels()->count())->toBe(0);
});

it('rejects a bank account for an owner in a country without catalogue coverage', function () {
    $institution = bankCatalogForeignInstitution();

    $profile = app(DonationChannelCountryProfiles::class)->forOwner($institution);

    expect($profile->country_code)->toBe('AQ')
        ->and($profile->methods)->toBe([])
        ->and($profile->banks->covers())->toBeFalse()
        ->and($profile->supports_routing_codes)->toBeFalse();

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Internasional',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '123456789',
    ]))->toThrow(ValidationException::class);

    expect($institution->fresh()->donationChannels()->count())->toBe(0);
});

it('rejects a bank account for an owner without a country instead of defaulting', function () {
    $institution = Institution::factory()->create();
    $institution->addresses()->detach();
    $institution->unsetRelation('addresses');

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '123456789',
    ]))->toThrow(ValidationException::class);

    expect($institution->fresh()->donationChannels()->count())->toBe(0);
});

it('keeps the stored bank while the bank name is derived on updates', function () {
    $institution = Institution::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '123456789',
    ]);

    $updated = app(SaveDonationChannelAction::class)->handle([
        'recipient' => 'Masjid Al-Hidayah Baharu',
    ], $channel);

    expect($updated->bank_code)->toBe('MB2U0227')
        ->and($updated->bank_name)->toBe('Malayan Banking Berhad');
});

it('accepts a foreign catalogue bank and stores its routing code', function () {
    $institution = bankCatalogForeignInstitution('ID', 'Indonesia');

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Internasional',
        'method' => 'bank_account',
        'bank_code' => 'cenaidja',
        'routing_code' => '014',
        'account_number' => '123456789',
    ]);

    expect($channel->bank_code)->toBe('CENAIDJA')
        ->and($channel->bank_name)->toBe('Bank Central Asia')
        ->and($channel->routing_code)->toBe('014')
        ->and($channel->payment_details)->toBe('Bank Central Asia - 123456789 (Routing: 014)')
        ->and($channel->currency)->toBeNull();
});

it('rejects a bank code from another country catalogue', function () {
    $institution = bankCatalogForeignInstitution('ID', 'Indonesia');

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Internasional',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '123456789',
    ]))->toThrow(ValidationException::class);

    expect($institution->fresh()->donationChannels()->count())->toBe(0);
});

it('rejects routing codes for malaysian banks', function () {
    $institution = Institution::factory()->create();

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'routing_code' => '014',
        'account_number' => '123456789',
    ]))->toThrow(ValidationException::class);

    expect($institution->fresh()->donationChannels()->count())->toBe(0);
});

it('removes a retired bank from new selection while keeping the stored channel payout', function () {
    $institution = Institution::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '123456789',
    ]);

    $channel->verify();

    $bank = DonationBank::query()->where('code', 'MB2U0227')->sole();
    $bank->status = DonationBankStatus::Retired;
    $bank->save();

    $catalog = app(DonationBankCatalogs::class)->forCountryCode('MY');

    expect($catalog->has('MB2U0227'))->toBeFalse()
        ->and($catalog->retains('MB2U0227'))->toBeTrue()
        ->and($catalog->options())->not->toHaveKey('MB2U0227');

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Baharu',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '987654321',
    ]))->toThrow(ValidationException::class);

    $updated = app(SaveDonationChannelAction::class)->handle([
        'recipient' => 'Masjid Al-Hidayah Baharu',
    ], $channel);

    expect($updated->bank_code)->toBe('MB2U0227')
        ->and($updated->bank_name)->toBe('Malayan Banking Berhad')
        ->and($updated->status)->toBe(DonationChannelStatus::Verified)
        ->and($institution->fresh()->donationChannels()->count())->toBe(1);
});

it('does not resurrect a deleted bank through the catalog', function () {
    $bank = DonationBank::query()->where('code', 'BCBB0235')->sole();
    $bank->delete();

    $catalog = app(DonationBankCatalogs::class)->forCountryCode('MY');

    expect($catalog->has('BCBB0235'))->toBeFalse()
        ->and($catalog->retains('BCBB0235'))->toBeFalse()
        ->and($catalog->name('BCBB0235'))->toBeNull();

    $institution = Institution::factory()->create();

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Masjid Al-Hidayah',
        'method' => 'bank_account',
        'bank_code' => 'BCBB0235',
        'account_number' => '123456789',
    ]))->toThrow(ValidationException::class);

    expect($institution->fresh()->donationChannels()->count())->toBe(0);
});
