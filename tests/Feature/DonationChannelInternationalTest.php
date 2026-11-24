<?php

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Support\AddressingTableResolver;
use AIArmada\CommerceSupport\Models\Currency;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\DonationChannels\Actions\SaveDonationChannelAction;
use App\Features\DonationChannels\Api\DonationChannelCountryProfiles;
use App\Features\DonationChannels\Api\DonationQrWorkflow;
use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Institutions\Models\Institution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @return array{country: AddressCountry, institution: Institution}
 */
function internationalDonationInstitution(string $iso2 = 'ID', string $name = 'Indonesia', string $currencyCode = 'IDR'): array
{
    $country = ensureTestAddressCountry($iso2, $name);

    // Install the bootstrap country catalog so bank validation resolves.
    seedDonationBankCatalogForTests();

    if ($currencyCode !== '') {
        $currency = Currency::query()->firstOrCreate(
            ['code' => $currencyCode],
            ['name' => $currencyCode.' currency'],
        );

        DB::table(AddressingTableResolver::resolve('country_currency_links'))->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'country_id' => (string) $country->getKey(),
            'currency_id' => (string) $currency->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $institution = Institution::factory()->create();

    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) $country->getKey(),
        'country_code' => $iso2,
        'line1' => 'Jalan Internasional 1',
        'postcode' => '10110',
    ]);

    return ['country' => $country, 'institution' => $institution->fresh()];
}

it('applies country-aware donation method and currency profiles', function () {
    $malaysia = ensureTestAddressCountry('MY', 'Malaysia');
    $indonesia = internationalDonationInstitution('ID', 'Indonesia', 'IDR')['country'];
    $singapore = internationalDonationInstitution('SG', 'Singapore', 'SGD')['country'];

    $profiles = app(DonationChannelCountryProfiles::class);

    $malaysianProfile = $profiles->forCountryId((string) $malaysia->getKey());

    expect($malaysianProfile->country_code)->toBe('MY')
        ->and($malaysianProfile->default_currency)->toBe('MYR')
        ->and($malaysianProfile->isMalaysia())->toBeTrue()
        ->and($malaysianProfile->methods)->toBe(['bank_account', 'duitnow', 'ewallet'])
        ->and($malaysianProfile->banks->covers())->toBeTrue()
        ->and($malaysianProfile->supports_routing_codes)->toBeFalse();

    $indonesianProfile = $profiles->forCountryId((string) $indonesia->getKey());

    expect($indonesianProfile->country_code)->toBe('ID')
        ->and($indonesianProfile->default_currency)->toBe('IDR')
        ->and($indonesianProfile->isMalaysia())->toBeFalse()
        ->and($indonesianProfile->methods)->toBe(['bank_account'])
        ->and($indonesianProfile->banks->covers())->toBeTrue()
        ->and($indonesianProfile->supports_routing_codes)->toBeTrue();

    $singaporeanProfile = $profiles->forCountryId((string) $singapore->getKey());

    expect($singaporeanProfile->country_code)->toBe('SG')
        ->and($singaporeanProfile->default_currency)->toBe('SGD')
        ->and($singaporeanProfile->methods)->toBe(['bank_account'])
        ->and($singaporeanProfile->banks->covers())->toBeTrue()
        ->and($singaporeanProfile->supports_routing_codes)->toBeTrue();

    $unknownProfile = $profiles->forCountryCode(null);

    expect($unknownProfile->country_code)->toBeNull()
        ->and($unknownProfile->default_currency)->toBeNull()
        ->and($unknownProfile->methods)->toBe([])
        ->and($unknownProfile->supports_routing_codes)->toBeFalse()
        ->and($profiles->currencyOptions())->toHaveKey('MYR');
});

it('resolves the owner profile from the canonical primary address country', function () {
    $profiles = app(DonationChannelCountryProfiles::class);

    ['institution' => $indonesian] = internationalDonationInstitution('ID', 'Indonesia', 'IDR');

    expect($profiles->forOwner($indonesian)->country_code)->toBe('ID')
        ->and($profiles->forOwner($indonesian)->methods)->toBe(['bank_account'])
        ->and($profiles->forOwner($indonesian)->supports_routing_codes)->toBeTrue();

    ['institution' => $uncovered] = internationalDonationInstitution('US', 'United States', '');

    expect($profiles->forOwner($uncovered)->country_code)->toBe('US')
        ->and($profiles->forOwner($uncovered)->methods)->toBe([])
        ->and($profiles->forOwner($uncovered)->supports_routing_codes)->toBeFalse();

    $institution = Institution::factory()->create();

    expect($profiles->forOwner($institution)->country_code)->toBe('MY');

    $institution->addresses()->detach();
    $institution->unsetRelation('addresses');

    expect($profiles->forOwner($institution)->country_code)->toBeNull()
        ->and($profiles->forOwner($institution)->methods)->toBe([]);
});

it('stores normalized bank details and encrypts the iban', function () {
    Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar']);

    $institution = Institution::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Antarabangsa',
        'method' => 'bank_account',
        'currency' => 'usd',
        'bank_code' => 'MB2U0227',
        'iban' => 'gb82 west 1234 5698 7654 32',
        'swift_bic' => 'mbbemykl',
    ]);

    $storedIban = DB::table('donation_channels')->where('id', $channel->getKey())->value('iban');
    $reloaded = DonationChannel::query()->findOrFail($channel->getKey());

    expect($channel->account_number)->toBeNull()
        ->and($channel->currency)->toBe('USD')
        ->and($channel->bank_code)->toBe('MB2U0227')
        ->and($channel->bank_name)->toBe('Maybank2u')
        ->and($channel->iban)->toBe('GB82WEST12345698765432')
        ->and($channel->swift_bic)->toBe('MBBEMYKL')
        ->and($channel->payment_details)->toBe('Maybank2u - GB82WEST12345698765432')
        ->and($reloaded->toArray())->not->toHaveKey('iban')
        ->and($storedIban)->not->toBe('GB82WEST12345698765432');
});

it('rejects invalid bank identifiers and missing accounts', function () {
    $institution = Institution::factory()->create();

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Antarabangsa',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'iban' => 'NOT-AN-IBAN',
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Antarabangsa',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'iban' => 'GB82WEST99999999999999',
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Antarabangsa',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'iban' => 'GB82WEST12345698765432',
        'swift_bic' => 'BAD',
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Antarabangsa',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
    ]))->toThrow(ValidationException::class);
});

it('rejects an explicit currency that is not in the currency catalog', function () {
    $institution = Institution::factory()->create();

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Antarabangsa',
        'method' => 'bank_account',
        'currency' => 'XTS',
        'bank_code' => 'MB2U0227',
        'account_number' => '1234567890',
    ]))->toThrow(ValidationException::class);
});

it('forces malaysian-only methods to myr', function () {
    $institution = Institution::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Jawatankuasa Masjid',
        'method' => 'duitnow',
        'currency' => 'USD',
        'duitnow_type' => 'business',
        'duitnow_value' => '12345678',
    ]);

    expect($channel->currency)->toBe('MYR')
        ->and($channel->iban)->toBeNull()
        ->and($channel->swift_bic)->toBeNull();
});

it('defaults a new institution bank channel to the country currency', function () {
    $institution = Institution::factory()->create();

    withGlobalOwnerContext(fn () => app(InstitutionWorkflow::class)->applyContribution((string) $institution->getKey(), [
        'donation_channels' => [[
            'recipient' => 'Jawatankuasa Masjid',
            'method' => 'bank_account',
            'bank_code' => 'BCBB0235',
            'account_number' => '1234567890',
        ]],
    ]));

    $channel = $institution->fresh()->donationChannels()->firstOrFail();

    expect($channel->currency)->toBe('MYR')
        ->and($channel->bank_name)->toBe('CIMB Bank')
        ->and($channel->iban)->toBeNull()
        ->and($channel->swift_bic)->toBeNull();
});

it('rejects bank and malaysian-only channels for institutions outside catalogue coverage', function () {
    ['institution' => $institution] = internationalDonationInstitution('US', 'United States', '');

    expect(fn () => withGlobalOwnerContext(fn () => app(InstitutionWorkflow::class)->applyContribution((string) $institution->getKey(), [
        'donation_channels' => [[
            'recipient' => 'Yayasan Internasional',
            'method' => 'bank_account',
            'bank_code' => 'MB2U0227',
            'account_number' => '1234567890',
        ]],
    ])))->toThrow(ValidationException::class);

    expect(fn () => withGlobalOwnerContext(fn () => app(InstitutionWorkflow::class)->applyContribution((string) $institution->getKey(), [
        'donation_channels' => [[
            'recipient' => 'Jawatankuasa Surau',
            'method' => 'duitnow',
            'duitnow_type' => 'business',
            'duitnow_value' => '12345678',
        ]],
    ])))->toThrow(ValidationException::class);

    expect($institution->fresh()->donationChannels()->count())->toBe(0);
});

it('rejects a country change that leaves malaysian-only channels behind', function () {
    ['country' => $originalCountry, 'institution' => $institution] = internationalDonationInstitution('MY', 'Malaysia', 'MYR');

    withGlobalOwnerContext(function () use ($institution): void {
        DonationChannel::factory()->duitnow()->create([
            'donatable_type' => $institution->getMorphClass(),
            'donatable_id' => (string) $institution->getKey(),
            'recipient' => 'Jawatankuasa Masjid',
            'duitnow_type' => 'business',
            'duitnow_value' => '12345678',
        ])->verify();
    });

    ['country' => $country] = internationalDonationInstitution('ID', 'Indonesia', 'IDR');

    expect(fn () => withGlobalOwnerContext(fn () => app(InstitutionWorkflow::class)->applyContribution((string) $institution->getKey(), [
        'address' => [
            'country_id' => (string) $country->getKey(),
            'line1' => 'Jalan Internasional 2',
        ],
    ])))->toThrow(ValidationException::class);

    $channel = $institution->fresh()->donationChannels()->firstOrFail();

    expect($channel->method)->toBe('duitnow')
        ->and($institution->fresh()->primaryAddress()?->country_id)->toBe($originalCountry->getKey());
});

it('rejects a country change that leaves a wrong-country bank behind', function () {
    ['country' => $originalCountry, 'institution' => $institution] = internationalDonationInstitution('MY', 'Malaysia', 'MYR');

    withGlobalOwnerContext(function () use ($institution): void {
        DonationChannel::factory()->bankAccount()->create([
            'donatable_type' => $institution->getMorphClass(),
            'donatable_id' => (string) $institution->getKey(),
            'bank_code' => 'MB2U0227',
            'bank_name' => 'Maybank2u',
            'recipient' => 'Jawatankuasa Masjid',
        ])->verify();
    });

    ['country' => $country] = internationalDonationInstitution('ID', 'Indonesia', 'IDR');

    expect(fn () => withGlobalOwnerContext(fn () => app(InstitutionWorkflow::class)->applyContribution((string) $institution->getKey(), [
        'address' => [
            'country_id' => (string) $country->getKey(),
            'line1' => 'Jalan Internasional 2',
        ],
    ])))->toThrow(ValidationException::class);

    $channel = $institution->fresh()->donationChannels()->firstOrFail();

    expect($channel->method)->toBe('bank_account')
        ->and($institution->fresh()->primaryAddress()?->country_id)->toBe($originalCountry->getKey());
});

it('allows removing unsupported channels in the same country change', function () {
    $institution = Institution::factory()->create();

    withGlobalOwnerContext(function () use ($institution): void {
        DonationChannel::factory()->duitnow()->create([
            'donatable_type' => $institution->getMorphClass(),
            'donatable_id' => (string) $institution->getKey(),
            'recipient' => 'Jawatankuasa Masjid',
            'duitnow_type' => 'business',
            'duitnow_value' => '12345678',
        ])->verify();
    });

    ['country' => $country] = internationalDonationInstitution('ID', 'Indonesia', 'IDR');

    withGlobalOwnerContext(fn () => app(InstitutionWorkflow::class)->applyContribution((string) $institution->getKey(), [
        'address' => [
            'country_id' => (string) $country->getKey(),
            'line1' => 'Jalan Internasional 1',
        ],
        'donation_channels' => [],
    ]));

    expect($institution->fresh()->donationChannels()->count())->toBe(0)
        ->and($institution->fresh()->primaryAddress()?->country_id)->toBe((string) $country->getKey());
});

it('keeps verification when only payout formatting changes', function () {
    $institution = Institution::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Antarabangsa',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'iban' => 'GB82WEST12345698765432',
    ]);

    $channel->verify();

    $saved = app(SaveDonationChannelAction::class)->handle([
        'recipient' => 'Yayasan Antarabangsa',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'iban' => 'gb82 west 1234 5698 7654 32',
    ], $channel);

    expect($saved->status)->toBe(DonationChannelStatus::Verified)
        ->and($saved->bank_name)->toBe('Maybank2u')
        ->and($saved->iban)->toBe('GB82WEST12345698765432');
});

it('requires reverification and clears the stale qr image when the SWIFT code changes', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Jawatankuasa Masjid',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '1234567890',
        'swift_bic' => 'MBBEMYKL',
    ]);

    $channel->verify();
    $channel->addMedia(fakeGeneratedImageUpload('channel-qr.png', 300, 300))->toMediaCollection('qr');

    expect($channel->fresh()->hasMedia('qr'))->toBeTrue();

    $saved = app(SaveDonationChannelAction::class)->handle([
        'recipient' => 'Jawatankuasa Masjid',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'swift_bic' => 'CIBBMYKL',
    ], $channel);

    expect($saved->status)->toBe(DonationChannelStatus::Pending)
        ->and($saved->swift_bic)->toBe('CIBBMYKL')
        ->and($saved->verified_at)->toBeNull()
        ->and($saved->getMedia('qr'))->toBeEmpty();
});

it('keeps verification and the qr image for non-destination detail changes', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Jawatankuasa Masjid',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '1234567890',
    ]);

    $channel->verify();
    $channel->addMedia(fakeGeneratedImageUpload('channel-qr.png', 300, 300))->toMediaCollection('qr');

    $saved = app(SaveDonationChannelAction::class)->handle([
        'recipient' => 'Jawatankuasa Masjid Baharu',
        'reference_note' => 'Dana am',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '1234567890',
    ], $channel);

    expect($saved->status)->toBe(DonationChannelStatus::Verified)
        ->and($saved->recipient)->toBe('Jawatankuasa Masjid Baharu')
        ->and($saved->getMedia('qr'))->toHaveCount(1);
});

it('matches formatted iban rows to the stored channel without losing verification', function () {
    $institution = Institution::factory()->create();

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Antarabangsa',
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'iban' => 'GB82WEST12345698765432',
    ]);

    $channel->verify();

    withGlobalOwnerContext(fn () => app(InstitutionWorkflow::class)->applyContribution((string) $institution->getKey(), [
        'donation_channels' => [[
            'recipient' => 'Yayasan Antarabangsa',
            'method' => 'bank_account',
            'bank_code' => 'MB2U0227',
            'iban' => 'gb82 west 1234 5698 7654 32',
        ]],
    ]));

    $fresh = $institution->fresh()->donationChannels()->firstOrFail();

    expect($institution->fresh()->donationChannels()->count())->toBe(1)
        ->and($fresh->getKey())->toBe($channel->getKey())
        ->and($fresh->status)->toBe(DonationChannelStatus::Verified)
        ->and($fresh->bank_name)->toBe('Maybank2u')
        ->and($fresh->iban)->toBe('GB82WEST12345698765432');
});

it('rejects staged donation qr uploads that match no channel instead of discarding them', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create();

    $workflow = app(DonationQrWorkflow::class);
    $fingerprint = $workflow->donationChannelFingerprint([
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '1234567890',
    ]);

    $request = ContributionRequest::factory()->create([
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => (string) $institution->getKey(),
    ]);

    $staged = $request->addMediaFromString((string) fakeGeneratedImageUpload('qr.png')->get())
        ->usingFileName('contribution-request-unmatched.png')
        ->withCustomProperties([
            'contribution_field' => 'donation_qr',
            'donation_fingerprint' => $fingerprint,
            'original_file_name' => 'unmatched-qr.png',
        ])
        ->toMediaCollection('pending_media');

    expect(fn () => $workflow->attachStagedDonationQr(
        $institution->getMorphClass(),
        (string) $institution->getKey(),
        (string) $staged->getKey(),
    ))->toThrow(ValidationException::class)
        ->and(Media::query()->whereKey($staged->getKey())->exists())->toBeTrue()
        ->and($request->fresh()->getMedia('pending_media'))->toHaveCount(1);
});

it('matches staged qr uploads across iban formatting', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create();

    $channel = DonationChannel::factory()->bankAccount()->create([
        'donatable_type' => $institution->getMorphClass(),
        'donatable_id' => (string) $institution->getKey(),
        'label' => 'Dana Antarabangsa',
        'currency' => 'MYR',
        'bank_code' => 'MB2U0227',
        'bank_name' => 'Maybank2u',
        'account_number' => null,
        'iban' => 'GB82WEST12345698765432',
        'swift_bic' => 'WESTGB2L',
    ]);

    $workflow = app(DonationQrWorkflow::class);
    $fingerprint = $workflow->donationChannelFingerprint([
        'method' => 'bank_account',
        'bank_code' => 'mb2u0227',
        'iban' => 'gb82 west 1234 5698 7654 32',
        'swift_bic' => 'west gb2l',
    ]);

    $request = ContributionRequest::factory()->create([
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => (string) $institution->getKey(),
    ]);

    $staged = $request->addMediaFromString((string) fakeGeneratedImageUpload('qr.png')->get())
        ->usingFileName('contribution-request-formatted-iban.png')
        ->withCustomProperties([
            'contribution_field' => 'donation_qr',
            'donation_fingerprint' => $fingerprint,
            'original_file_name' => 'formatted-iban-qr.png',
        ])
        ->toMediaCollection('pending_media');

    $workflow->attachStagedDonationQr(
        $institution->getMorphClass(),
        (string) $institution->getKey(),
        (string) $staged->getKey(),
    );

    expect($channel->fresh()->getFirstMedia('qr'))->not->toBeNull();
});

it('fingerprints wallet and bank payout destinations without the derived bank name', function () {
    $workflow = app(DonationQrWorkflow::class);

    expect($workflow->donationChannelFingerprint([
        'method' => 'ewallet',
        'ewallet_provider' => 'tng',
        'ewallet_handle' => '0912345678',
    ]))->toBe('sha256:'.hash('sha256', '["ewallet","tng","0912345678",""]'));

    $accountOnly = $workflow->donationChannelFingerprint([
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'account_number' => '123456789',
    ]);

    $withCatalogName = $workflow->donationChannelFingerprint([
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'bank_name' => 'Maybank2u',
        'account_number' => '123456789',
    ]);

    $ibanRow = $workflow->donationChannelFingerprint([
        'method' => 'bank_account',
        'bank_code' => 'MB2U0227',
        'iban' => 'GB82WEST12345698765432',
    ]);

    $otherBankRow = $workflow->donationChannelFingerprint([
        'method' => 'bank_account',
        'bank_code' => 'BCBB0235',
        'iban' => 'GB82WEST12345698765432',
    ]);

    expect($accountOnly)->not->toBeNull()
        ->and($accountOnly)->toBe($withCatalogName)
        ->and($ibanRow)->not->toBeNull()
        ->and($ibanRow)->not->toBe($accountOnly)
        ->and($ibanRow)->not->toBe($otherBankRow);
});

it('renders catalogue bank details on the public institution page', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    $channel = $institution->donationChannels()->create([
        'recipient' => 'Yayasan Antarabangsa',
        'method' => 'bank_account',
        'currency' => 'MYR',
        'bank_code' => 'MB2U0227',
        'bank_name' => 'Maybank2u',
        'account_number' => '1234567890',
        'swift_bic' => 'MBBEMYKL',
        'label' => 'Dana Masjid',
    ]);
    $channel->verify();

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('Dana Masjid')
        ->assertSee('MYR')
        ->assertSee('Maybank2u')
        ->assertSee('MBBEMYKL')
        ->assertSee('1234567890')
        ->assertDontSee('MB2U0227');
});

it('stores an indonesian bank channel with its routing code and country currency', function () {
    ['institution' => $institution] = internationalDonationInstitution('ID', 'Indonesia', 'IDR');

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Internasional',
        'method' => 'bank_account',
        'bank_code' => 'CENAIDJA',
        'routing_code' => ' 014 ',
        'account_number' => '123456789',
    ]);

    expect($channel->bank_name)->toBe('Bank Central Asia')
        ->and($channel->routing_code)->toBe('014')
        ->and($channel->currency)->toBe('IDR')
        ->and($channel->payment_details)->toBe('Bank Central Asia - 123456789 (Routing: 014)');
});

it('rejects a wrong-country bank for a covered international owner', function () {
    ['institution' => $institution] = internationalDonationInstitution('SG', 'Singapore', 'SGD');

    expect(fn () => app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Antarabangsa',
        'method' => 'bank_account',
        'bank_code' => 'CENAIDJA',
        'account_number' => '123456789',
    ]))->toThrow(ValidationException::class);

    expect($institution->fresh()->donationChannels()->count())->toBe(0);
});

it('requires reverification when the routing code changes on an international bank', function () {
    ['institution' => $institution] = internationalDonationInstitution('SG', 'Singapore', 'SGD');

    $channel = app(SaveDonationChannelAction::class)->handle([
        'donatable_type' => 'institution',
        'donatable_id' => (string) $institution->getKey(),
        'recipient' => 'Yayasan Antarabangsa',
        'method' => 'bank_account',
        'bank_code' => '7171',
        'routing_code' => '081',
        'account_number' => '123456789',
    ]);

    $channel->verify();

    $reformatted = app(SaveDonationChannelAction::class)->handle([
        'routing_code' => '0 8 1',
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

it('renders routing codes on the public institution page for international banks', function () {
    $country = ensureTestAddressCountry('SG', 'Singapore');
    seedDonationBankCatalogForTests();

    $institution = Institution::factory()->create(['status' => 'verified']);

    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) $country->getKey(),
        'country_code' => 'SG',
        'line1' => '1 Raffles Place',
        'postcode' => '048616',
    ]);

    $institution = $institution->fresh();

    $channel = $institution->donationChannels()->create([
        'recipient' => 'Yayasan Antarabangsa',
        'method' => 'bank_account',
        'currency' => 'SGD',
        'bank_code' => '7171',
        'bank_name' => 'DBS Bank / POSB',
        'routing_code' => '081',
        'account_number' => '123456789',
        'label' => 'Dana Singapura',
    ]);
    $channel->verify();

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('Dana Singapura')
        ->assertSee('DBS Bank / POSB')
        ->assertSee('081')
        ->assertDontSee('7171');
});
