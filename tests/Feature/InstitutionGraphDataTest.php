<?php

use AIArmada\Addressing\Actions\SyncAddressAreaAssignmentsAction;
use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Contacting\Enums\ContactPurpose;
use App\Actions\Institutions\ImportInstitutionGraphAction;
use App\Data\InstitutionData;
use App\Enums\DonationChannelStatus;
use App\Enums\InstitutionNameType;
use App\Enums\InstitutionStatus;
use App\Models\DonationChannel;
use App\Models\Institution;
use App\Models\InstitutionImportExclusion;
use App\Models\InstitutionName;
use App\Models\Language;
use App\Models\Space;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Process-local switch that makes the next institution-name write throw,
 * proving graph-write atomicity without touching the database state.
 */
final class InstitutionGraphWriteFailure
{
    public static bool $fail = false;
}

it('writes a full valid graph without owner membership', function () {
    $geo = seedInstitutionGraphGeography();
    $language = Language::query()->where('code', 'ms')->firstOrFail();
    $space = Space::factory()->create(['venue_id' => null]);

    $data = InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'language_ids' => [(string) $language->getKey()],
        'spaces' => [['id' => (string) $space->getKey(), 'capacity' => 120]],
        'facilities' => ['parking' => true, 'wheelchair_access' => false],
    ]));

    $institution = app(ImportInstitutionGraphAction::class)->handle($data);

    expect($institution->name)->toBe("Masjid Al-'Aliatul")
        ->and($institution->slug)->toBe('masjid-al-aliatul-graph-1')
        ->and($institution->status)->toBe(InstitutionStatus::Verified)
        ->and($institution->getAttribute('source'))->toBe('masjid-csv')
        ->and($institution->getAttribute('external_ref'))->toBe('graph-1')
        ->and($institution->getAttribute('imported_at'))->not()->toBeNull()
        ->and($institution->getAttribute('facilities'))->toBe(['parking' => true, 'wheelchair_access' => false])
        ->and($institution->names)->toHaveCount(2)
        ->and($institution->contactMethods)->toHaveCount(1)
        ->and($institution->socialProfiles)->toHaveCount(1)
        ->and($institution->donationChannels)->toHaveCount(1)
        ->and($institution->languages->pluck('id')->all())->toBe([(string) $language->getKey()])
        ->and($institution->spaces->pluck('id')->all())->toBe([(string) $space->getKey()])
        ->and($institution->spaces->first()->pivot->capacity)->toBe(120)
        ->and($institution->members()->count())->toBe(0);

    $address = $institution->primaryAddress();
    expect($address->line1)->toBe('Jalan Graph 1')
        ->and((string) $address->state_id)->toBe((string) $geo['state']->getKey())
        ->and($address->areaAssignments->pluck('role')->sort()->values()->all())
        ->toBe(['administrative_district', 'administrative_subdivision']);

    expect($institution->donationChannels->first()->method)->toBe('bank_account')
        ->and($institution->donationChannels->first()->status)->toBe(DonationChannelStatus::Verified);
});

it('rejects invalid enum and reference values with validation errors', function (array $overrides) {
    $geo = seedInstitutionGraphGeography();

    expect(fn () => InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, $overrides)))
        ->toThrow(ValidationException::class);
    expect(Institution::query()->count())->toBe(0);
})->with([
    'unknown institution type' => [['type' => 'kuil']],
    'unknown status' => [['status' => 'archived']],
    'unknown name type' => [['names' => [['full_name' => 'X', 'name_type' => 'secret']]]],
    'unknown contact type' => [['contact_methods' => [['type' => 'pigeon', 'value' => 'x']]]],
    'unknown contact purpose' => [['contact_methods' => [['type' => 'email', 'value' => 'a@b.c', 'purpose' => 'gossip']]]],
    'unknown social platform' => [['social_profiles' => [['platform' => 'myspace-2', 'handle' => 'x']]]],
    'unknown donation status' => [['donation_channels' => [[
        'method' => 'duitnow', 'recipient' => 'R', 'duitnow_type' => 'mobile', 'duitnow_value' => 'v', 'status' => 'archived',
    ]]]],
    'unknown donation method' => [['donation_channels' => [[
        'method' => 'cash', 'recipient' => 'R',
    ]]]],
    'bank channel missing bank_name' => [['donation_channels' => [[
        'method' => 'bank_account', 'recipient' => 'R', 'account_number' => '123',
    ]]]],
    'bank channel missing account_number' => [['donation_channels' => [[
        'method' => 'bank_account', 'recipient' => 'R', 'bank_name' => 'B',
    ]]]],
    'bank channel blank bank_name' => [['donation_channels' => [[
        'method' => 'bank_account', 'recipient' => 'R', 'bank_name' => '   ', 'account_number' => '123',
    ]]]],
    'duitnow channel with unknown type' => [['donation_channels' => [[
        'method' => 'duitnow', 'recipient' => 'R', 'duitnow_type' => 'carrier-pigeon', 'duitnow_value' => 'v',
    ]]]],
    'duitnow channel missing value' => [['donation_channels' => [[
        'method' => 'duitnow', 'recipient' => 'R', 'duitnow_type' => 'mobile',
    ]]]],
    'ewallet channel missing provider' => [['donation_channels' => [[
        'method' => 'ewallet', 'recipient' => 'R',
    ]]]],
    'source without external_ref' => [['source' => 'masjid-csv', 'external_ref' => null]],
    'external_ref without source' => [['source' => null, 'external_ref' => 'graph-9']],
    'blank source with external_ref' => [['source' => '   ', 'external_ref' => 'graph-9']],
    'blank provenance pair' => [['source' => '', 'external_ref' => '']],
    'unknown language id' => [['language_ids' => [(string) Str::uuid()]]],
    'unknown space id' => [['spaces' => [['id' => (string) Str::uuid()]]]],
    'non-boolean public flag' => [['contact_methods' => [['type' => 'email', 'value' => 'a@b.c', 'is_public' => 'yes-please']]]],
    'unknown facility key' => [['facilities' => ['teleport' => true]]],
    'non-boolean facility flag' => [['facilities' => ['parking' => 'yes']]],
    'blank name' => [['name' => '   ']],
    'missing slug' => [['slug' => '']],
    'out-of-range latitude' => [['address' => ['latitude' => 91.0]]],
]);

it('rejects incoherent geography and unknown area roles', function () {
    $geo = seedInstitutionGraphGeography();
    $otherCountry = ensureTestAddressCountry('ID', 'Indonesia', 'IDN');
    $otherGeo = createTestPackageGeography(stateName: 'Jakarta', districtName: 'Menteng', cityName: 'Jakarta City', country: $otherCountry);

    $payload = fn (array $address): array => validInstitutionGraphPayload($geo, ['address' => $address]);

    expect(fn () => InstitutionData::validateAndCreate($payload([
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $otherGeo['state']->getKey(),
    ])))->toThrow(ValidationException::class, 'state');

    expect(fn () => InstitutionData::validateAndCreate($payload([
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
        'city_id' => (string) $otherGeo['city']->getKey(),
    ])))->toThrow(ValidationException::class, 'city');

    expect(fn () => InstitutionData::validateAndCreate($payload([
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
        'area_assignments' => ['teleport_zone' => (string) $geo['district']->getKey()],
    ])))->toThrow(ValidationException::class, 'role');

    expect(Institution::query()->count())->toBe(0);
});

it('derives the state for a same-country city without a state but rejects a wrong-country city', function () {
    $geo = seedInstitutionGraphGeography();
    $otherCountry = ensureTestAddressCountry('ID', 'Indonesia', 'IDN');
    $otherGeo = createTestPackageGeography(stateName: 'Jakarta', districtName: 'Menteng', cityName: 'Jakarta City', country: $otherCountry);
    $ownGeo = createTestPackageGeography(cityName: 'Shah Alam');

    try {
        InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, ['address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'state_id' => null,
            'city_id' => (string) $otherGeo['city']->getKey(),
        ]]));

        $this->fail('Expected a wrong-country city without a state to fail validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'address.city_id' => ['The selected city does not belong to the selected country.'],
        ]);
    }

    expect(Institution::query()->count())->toBe(0);

    // A same-country city without an explicit state validates; on save the
    // canonical package normalizer resolves the state from the supplied
    // state name, so the persisted row carries the city's canonical state
    // instead of null.
    $payload = validInstitutionGraphPayload($geo, ['address' => [
        'state_id' => null,
        'city_id' => (string) $ownGeo['city']->getKey(),
    ]]);
    unset($payload['address']['area_assignments']);

    $institution = app(ImportInstitutionGraphAction::class)->handle(InstitutionData::validateAndCreate($payload));
    $address = $institution->primaryAddress();

    expect((string) $address->state_id)->toBe((string) $geo['state']->getKey())
        ->and((string) $address->country_id)->toBe((string) $geo['country']->getKey())
        ->and((string) $address->city_id)->toBe((string) $ownGeo['city']->getKey());
});

it('accepts omitted constructor defaults and preserves opaque name bytes', function () {
    $geo = seedInstitutionGraphGeography();

    $payload = validInstitutionGraphPayload($geo);
    $payload['name'] = '  Masjid Padded  ';
    $payload['names'] = [['full_name' => '  Padded Local Name  ']];
    $payload['contact_methods'] = [['type' => 'email', 'value' => 'hello@example.com']];
    $payload['social_profiles'] = [['platform' => 'facebook', 'handle' => 'masjid.padded']];

    $institution = app(ImportInstitutionGraphAction::class)->handle(InstitutionData::validateAndCreate($payload));

    expect($institution->name)->toBe('  Masjid Padded  ')
        ->and($institution->names)->toHaveCount(1)
        ->and($institution->names->first()->full_name)->toBe('  Padded Local Name  ')
        ->and($institution->names->first()->name_type)->toBe(InstitutionNameType::Nickname)
        ->and($institution->names->first()->language_code)->toBe('ms')
        ->and($institution->contactMethods->first()->purpose)->toBe(ContactPurpose::General->value)
        ->and($institution->socialProfiles->first()->purpose)->toBe(ContactPurpose::General->value);
});

it('preserves omitted graph parts and clears explicit empty ones', function () {
    $geo = seedInstitutionGraphGeography();
    $language = Language::query()->where('code', 'ms')->firstOrFail();
    $space = Space::factory()->create(['venue_id' => null]);
    $action = app(ImportInstitutionGraphAction::class);

    $institution = $action->handle(InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'language_ids' => [(string) $language->getKey()],
        'spaces' => [['id' => (string) $space->getKey()]],
        'facilities' => ['parking' => true],
    ])));

    $addressLine = $institution->primaryAddress()?->line1;

    // Omitted parts preserve; scalar updates still apply.
    $preserved = $action->handle(InstitutionData::validateAndCreate([
        'name' => $institution->name,
        'slug' => $institution->slug,
        'type' => 'masjid',
        'status' => InstitutionStatus::Verified->value,
        'source' => 'masjid-csv',
        'external_ref' => 'graph-1',
        'description' => 'Updated description',
    ]), $institution);

    expect($preserved->description)->toBe('Updated description')
        ->and($preserved->names)->toHaveCount(2)
        ->and($preserved->contactMethods)->toHaveCount(1)
        ->and($preserved->socialProfiles)->toHaveCount(1)
        ->and($preserved->donationChannels)->toHaveCount(1)
        ->and($preserved->languages)->toHaveCount(1)
        ->and($preserved->spaces)->toHaveCount(1)
        ->and($preserved->getAttribute('facilities'))->toBe(['parking' => true])
        ->and($preserved->primaryAddress()?->line1)->toBe($addressLine);

    // Explicit [] clears each part.
    $cleared = $action->handle(InstitutionData::validateAndCreate([
        'name' => $institution->name,
        'slug' => $institution->slug,
        'type' => 'masjid',
        'status' => InstitutionStatus::Verified->value,
        'source' => 'masjid-csv',
        'external_ref' => 'graph-1',
        'facilities' => [],
        'names' => [],
        'contact_methods' => [],
        'social_profiles' => [],
        'donation_channels' => [],
        'language_ids' => [],
        'spaces' => [],
    ]), $preserved);

    expect($cleared->names)->toHaveCount(0)
        ->and($cleared->contactMethods)->toHaveCount(0)
        ->and($cleared->socialProfiles)->toHaveCount(0)
        ->and($cleared->donationChannels)->toHaveCount(0)
        ->and($cleared->languages)->toHaveCount(0)
        ->and($cleared->spaces)->toHaveCount(0)
        ->and($cleared->getAttribute('facilities'))->toBeNull()
        ->and($cleared->primaryAddress()?->line1)->toBe($addressLine);
});

it('clears the primary address on explicit null without deleting the shared row', function () {
    $geo = seedInstitutionGraphGeography();
    $action = app(ImportInstitutionGraphAction::class);

    $institution = $action->handle(InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo)));
    $addressId = (string) $institution->primaryAddress()?->getKey();
    expect($addressId)->not()->toBe('');

    $otherPayload = validInstitutionGraphPayload($geo, [
        'name' => 'Other Hall',
        'slug' => 'other-hall',
        'source' => 'masjid-csv',
        'external_ref' => 'graph-2',
    ]);
    unset($otherPayload['address']);
    $other = $action->handle(InstitutionData::validateAndCreate($otherPayload));
    $other->attachAddress(Address::query()->findOrFail($addressId), 'primary', true);

    $cleared = $action->handle(InstitutionData::validateAndCreate([
        'name' => $institution->name,
        'slug' => $institution->slug,
        'type' => 'masjid',
        'status' => InstitutionStatus::Verified->value,
        'source' => 'masjid-csv',
        'external_ref' => 'graph-1',
        'address' => null,
    ]), $institution);

    expect($cleared->primaryAddress())->toBeNull()
        ->and(Address::query()->whereKey($addressId)->exists())->toBeTrue()
        ->and((string) $other->refresh()->primaryAddress()?->getKey())->toBe($addressId)
        ->and($cleared->names)->toHaveCount(2)
        ->and($cleared->contactMethods)->toHaveCount(1);
});

it('rolls back the whole graph when a nested write fails', function () {
    $geo = seedInstitutionGraphGeography();

    InstitutionName::creating(function (): void {
        if (InstitutionGraphWriteFailure::$fail) {
            throw new RuntimeException('Injected mid-write failure.');
        }
    });

    // The payload is fully valid: the failure lands after the institution
    // and address rows are written, proving transactional rollback.
    $data = InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo));

    InstitutionGraphWriteFailure::$fail = true;

    try {
        expect(fn () => app(ImportInstitutionGraphAction::class)->handle($data))
            ->toThrow(RuntimeException::class, 'Injected mid-write failure.');
    } finally {
        InstitutionGraphWriteFailure::$fail = false;
    }

    expect(Institution::query()->count())->toBe(0)
        ->and(Address::query()->where('line1', 'Jalan Graph 1')->count())->toBe(0)
        ->and(InstitutionName::query()->count())->toBe(0)
        ->and(DonationChannel::query()->count())->toBe(0);
});

it('leaves the existing graph unchanged when an update fails mid-write', function () {
    $geo = seedInstitutionGraphGeography();
    $language = Language::query()->where('code', 'ms')->firstOrFail();
    $space = Space::factory()->create(['venue_id' => null]);
    $action = app(ImportInstitutionGraphAction::class);

    $institution = $action->handle(InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'language_ids' => [(string) $language->getKey()],
        'spaces' => [['id' => (string) $space->getKey(), 'capacity' => 120]],
        'facilities' => ['parking' => true],
    ])));

    InstitutionName::creating(function (): void {
        if (InstitutionGraphWriteFailure::$fail) {
            throw new RuntimeException('Injected mid-write failure.');
        }
    });

    $update = InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'name' => 'Changed Name',
        'slug' => 'changed-slug',
        'description' => 'Changed description',
        'names' => [['full_name' => 'Changed Name', 'name_type' => 'official']],
    ]));

    InstitutionGraphWriteFailure::$fail = true;

    try {
        expect(fn () => $action->handle($update, $institution))
            ->toThrow(RuntimeException::class, 'Injected mid-write failure.');
    } finally {
        InstitutionGraphWriteFailure::$fail = false;
    }

    $fresh = $institution->refresh()->load(['names', 'contactMethods', 'donationChannels', 'languages', 'spaces']);

    expect($fresh->name)->toBe("Masjid Al-'Aliatul")
        ->and($fresh->slug)->toBe('masjid-al-aliatul-graph-1')
        ->and($fresh->description)->toBe('Graph fixture masjid.')
        ->and($fresh->getAttribute('facilities'))->toBe(['parking' => true])
        ->and($fresh->primaryAddress()?->line1)->toBe('Jalan Graph 1')
        ->and($fresh->names)->toHaveCount(2)
        ->and($fresh->contactMethods)->toHaveCount(1)
        ->and($fresh->donationChannels)->toHaveCount(1)
        ->and($fresh->languages)->toHaveCount(1)
        ->and($fresh->spaces->first()->pivot->capacity)->toBe(120);
});

it('keeps provenance immutable and slugs unstealable on update', function () {
    $geo = seedInstitutionGraphGeography();
    $action = app(ImportInstitutionGraphAction::class);

    $institution = $action->handle(InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo)));
    $importedAt = (string) $institution->getAttribute('imported_at');
    $other = $action->handle(InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'name' => 'Other Hall',
        'slug' => 'other-hall',
        'source' => 'masjid-csv',
        'external_ref' => 'graph-2',
    ])));

    // Conflicting provenance fails loudly instead of rewriting identity.
    $conflict = InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, ['source' => 'osm']));
    expect(fn () => $action->handle($conflict, $institution))->toThrow(ValidationException::class, 'immutable');

    // Adopting another identity's slug fails as well.
    $steal = InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, ['slug' => 'other-hall']));
    expect(fn () => $action->handle($steal, $institution))->toThrow(ValidationException::class, 'another institution');

    $fresh = $institution->fresh();

    expect($fresh)->toBeInstanceOf(Institution::class);

    expect($fresh->slug)->toBe('masjid-al-aliatul-graph-1')
        ->and($fresh->getAttribute('source'))->toBe('masjid-csv')
        ->and((string) $fresh->getAttribute('imported_at'))->toBe($importedAt)
        ->and((string) $other->refresh()->slug)->toBe('other-hall');
});

it('rejects attaching provenance to a manual institution without writing', function () {
    $geo = seedInstitutionGraphGeography();
    $action = app(ImportInstitutionGraphAction::class);

    $create = validInstitutionGraphPayload($geo);
    unset($create['source'], $create['external_ref']);

    $institution = $action->handle(InstitutionData::validateAndCreate($create));

    expect($institution->getAttribute('source'))->toBeNull()
        ->and($institution->getAttribute('external_ref'))->toBeNull();

    try {
        $action->handle(InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
            'name' => 'Hijacked Name',
            'description' => 'Hijacked description.',
        ])), $institution);

        $this->fail('Expected attaching provenance to a manual institution to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'source' => ['Institution provenance is immutable and cannot be changed.'],
        ]);
    }

    $fresh = $institution->refresh()->load(['names', 'contactMethods', 'socialProfiles', 'donationChannels']);

    expect($fresh->name)->toBe("Masjid Al-'Aliatul")
        ->and($fresh->description)->toBe('Graph fixture masjid.')
        ->and($fresh->getAttribute('source'))->toBeNull()
        ->and($fresh->getAttribute('external_ref'))->toBeNull()
        ->and($fresh->names)->toHaveCount(2)
        ->and($fresh->contactMethods)->toHaveCount(1)
        ->and($fresh->socialProfiles)->toHaveCount(1)
        ->and($fresh->donationChannels)->toHaveCount(1)
        ->and($fresh->primaryAddress()?->line1)->toBe('Jalan Graph 1');

    // Omitted provenance stays permitted for reviewed normal edits.
    $edit = validInstitutionGraphPayload($geo, ['name' => 'Reviewed Name']);
    unset($edit['source'], $edit['external_ref']);

    $edited = $action->handle(InstitutionData::validateAndCreate($edit), $fresh);

    expect($edited->name)->toBe('Reviewed Name')
        ->and($edited->getAttribute('source'))->toBeNull()
        ->and($edited->getAttribute('external_ref'))->toBeNull();
});

it('rejects non-boolean facility flags at the DTO boundary', function (mixed $flag) {
    $geo = seedInstitutionGraphGeography();

    expect(fn () => InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'facilities' => ['parking' => $flag],
    ])))->toThrow(ValidationException::class, 'must be true or false.');
    expect(Institution::query()->count())->toBe(0);
})->with([
    'integer 1' => [1],
    "string '1'" => ['1'],
    'integer 0' => [0],
    "string '0'" => ['0'],
]);

it('rejects facility code lists at the DTO boundary', function () {
    $geo = seedInstitutionGraphGeography();

    expect(fn () => InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'facilities' => ['parking'],
    ])))->toThrow(ValidationException::class, 'flag map');
    expect(Institution::query()->count())->toBe(0);
});

it('rejects multiple primaries within one names, contact, or social group', function () {
    $geo = seedInstitutionGraphGeography();

    expect(fn () => InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'names' => [
            ['full_name' => 'Alpha', 'is_primary' => true],
            ['full_name' => 'Beta', 'is_primary' => true],
        ],
    ])))->toThrow(ValidationException::class, 'Only one institution name may be marked primary.');

    // Same type with both purposes omitted (defaulted general): same group.
    expect(fn () => InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'contact_methods' => [
            ['type' => 'email', 'value' => 'a@example.com', 'is_primary' => true],
            ['type' => 'email', 'value' => 'b@example.com', 'is_primary' => true],
        ],
    ])))->toThrow(ValidationException::class, 'Only one primary contact method is allowed per type and purpose');

    // Same platform with both purposes omitted (defaulted general): same group.
    expect(fn () => InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'social_profiles' => [
            ['platform' => 'facebook', 'handle' => 'one', 'is_primary' => true],
            ['platform' => 'facebook', 'handle' => 'two', 'is_primary' => true],
        ],
    ])))->toThrow(ValidationException::class, 'Only one primary social profile is allowed per platform and purpose');

    expect(Institution::query()->count())->toBe(0);
});

it('allows primaries across different contact and social groups', function () {
    $geo = seedInstitutionGraphGeography();

    $institution = app(ImportInstitutionGraphAction::class)->handle(InstitutionData::validateAndCreate(
        validInstitutionGraphPayload($geo, [
            'contact_methods' => [
                ['type' => 'email', 'value' => 'a@example.com', 'purpose' => 'general', 'is_primary' => true],
                ['type' => 'email', 'value' => 'b@example.com', 'purpose' => 'support', 'is_primary' => true],
                ['type' => 'phone', 'value' => '+6031234567', 'is_primary' => true],
            ],
            'social_profiles' => [
                ['platform' => 'facebook', 'handle' => 'one', 'is_primary' => true],
                ['platform' => 'instagram', 'handle' => 'two', 'is_primary' => true],
            ],
        ])
    ));

    expect($institution->contactMethods->where('is_primary', true))->toHaveCount(3)
        ->and($institution->socialProfiles->where('is_primary', true))->toHaveCount(2);
});

it('accepts url-only and handle-only social profiles together but rejects rows with neither', function () {
    $geo = seedInstitutionGraphGeography();

    $institution = app(ImportInstitutionGraphAction::class)->handle(InstitutionData::validateAndCreate(
        validInstitutionGraphPayload($geo, [
            'social_profiles' => [
                ['platform' => 'facebook', 'url' => 'https://facebook.example/masjid'],
                ['platform' => 'instagram', 'handle' => 'masjid.subang'],
            ],
        ])
    ));

    expect($institution->socialProfiles)->toHaveCount(2)
        ->and($institution->socialProfiles->firstWhere('platform', 'facebook')->url)->toBe('https://facebook.example/masjid')
        ->and($institution->socialProfiles->firstWhere('platform', 'instagram')->handle)->toBe('masjid.subang');

    expect(fn () => InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'social_profiles' => [
            ['platform' => 'facebook'],
        ],
    ])))->toThrow(ValidationException::class);

    expect(Institution::query()->count())->toBe(1);
});

it('respects a later explicit primary name and defaults to first only when none selected', function () {
    $geo = seedInstitutionGraphGeography();
    $action = app(ImportInstitutionGraphAction::class);

    $explicit = $action->handle(InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'names' => [
            ['full_name' => 'First Name', 'name_type' => 'official'],
            ['full_name' => '  Second Padded  ', 'name_type' => 'nickname', 'is_primary' => true],
        ],
    ])));

    expect($explicit->names)->toHaveCount(2)
        ->and($explicit->names->where('is_primary', true))->toHaveCount(1);

    expect($explicit->names->firstWhere('is_primary', true)->full_name)->toBe('  Second Padded  ')
        ->and($explicit->names->firstWhere('full_name', 'First Name')->is_primary)->toBeFalse();

    $defaulted = $action->handle(InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'slug' => 'masjid-al-aliatul-graph-2',
        'source' => 'masjid-csv',
        'external_ref' => 'graph-2',
        'names' => [
            ['full_name' => 'Alpha'],
            ['full_name' => 'Beta'],
        ],
    ])));

    expect($defaulted->names->firstWhere('full_name', 'Alpha')->is_primary)->toBeTrue()
        ->and($defaulted->names->firstWhere('full_name', 'Beta')->is_primary)->toBeFalse();
});

it('rejects duplicate space and language ids instead of dropping pivot data', function () {
    $geo = seedInstitutionGraphGeography();
    $language = Language::query()->where('code', 'ms')->firstOrFail();
    $space = Space::factory()->create(['venue_id' => null]);

    expect(fn () => InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'spaces' => [
            ['id' => (string) $space->getKey(), 'capacity' => 10],
            ['id' => (string) $space->getKey(), 'capacity' => 20],
        ],
    ])))->toThrow(ValidationException::class, 'Duplicate space');

    expect(fn () => InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'language_ids' => [(string) $language->getKey(), (string) $language->getKey()],
    ])))->toThrow(ValidationException::class, 'Duplicate language');

    expect(Institution::query()->count())->toBe(0);
});

it('rejects venue-owned spaces at the graph boundary', function () {
    $geo = seedInstitutionGraphGeography();
    $venueSpace = Space::factory()->create(['venue_id' => Venue::factory()->create()->getKey()]);

    expect(fn () => InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'spaces' => [['id' => (string) $venueSpace->getKey(), 'capacity' => 50]],
    ])))->toThrow(ValidationException::class);
    expect(Institution::query()->count())->toBe(0);
});

it('rejects zero and negative space capacities at the graph boundary', function (int $capacity) {
    $geo = seedInstitutionGraphGeography();
    $space = Space::factory()->create(['venue_id' => null]);

    expect(fn () => InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'spaces' => [['id' => (string) $space->getKey(), 'capacity' => $capacity]],
    ])))->toThrow(ValidationException::class);
    expect(Institution::query()->count())->toBe(0);
})->with([
    'zero capacity' => [0],
    'negative capacity' => [-5],
]);

it('rejects spaces that became venue-owned after the DTO was built without partial writes', function () {
    $geo = seedInstitutionGraphGeography();
    $space = Space::factory()->create(['venue_id' => null]);

    $data = InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'spaces' => [['id' => (string) $space->getKey(), 'capacity' => 50]],
    ]));

    $space->forceFill(['venue_id' => Venue::factory()->create()->getKey()])->save();

    expect(fn () => app(ImportInstitutionGraphAction::class)->handle($data))
        ->toThrow(ValidationException::class, 'Venue-owned spaces cannot be linked to institutions.');
    expect(Institution::query()->count())->toBe(0)
        ->and(Address::query()->where('line1', 'Jalan Graph 1')->count())->toBe(0)
        ->and(InstitutionName::query()->count())->toBe(0);
});

it('preserves omitted descriptions, clears explicit nulls, and defaults new rows to null', function () {
    $geo = seedInstitutionGraphGeography();
    $action = app(ImportInstitutionGraphAction::class);

    $create = validInstitutionGraphPayload($geo);
    unset($create['description']);
    $created = $action->handle(InstitutionData::validateAndCreate($create));
    expect($created->description)->toBeNull();

    $created->forceFill(['description' => 'Manually written.'])->save();

    $refresh = validInstitutionGraphPayload($geo, ['name' => 'Refreshed Name']);
    unset($refresh['description']);
    $preserved = $action->handle(InstitutionData::validateAndCreate($refresh), $created);
    expect($preserved->name)->toBe('Refreshed Name')
        ->and($preserved->description)->toBe('Manually written.');

    $cleared = $action->handle(InstitutionData::validateAndCreate(
        validInstitutionGraphPayload($geo, ['description' => null])
    ), $preserved);
    expect($cleared->description)->toBeNull();
});

it('refuses to create an excluded source identity but allows explicit edits of the live row', function () {
    $geo = seedInstitutionGraphGeography();
    $action = app(ImportInstitutionGraphAction::class);

    InstitutionImportExclusion::query()->create([
        'source' => 'masjid-csv',
        'external_ref' => 'graph-9',
        'deleted_at' => now(),
    ]);

    $blocked = InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo, [
        'slug' => 'masjid-blocked-graph-9',
        'source' => 'masjid-csv',
        'external_ref' => 'graph-9',
    ]));

    expect(fn () => $action->handle($blocked))->toThrow(ValidationException::class, 'excluded');
    expect(Institution::query()->count())->toBe(0)
        ->and(Address::query()->where('line1', 'Jalan Graph 1')->count())->toBe(0)
        ->and(DonationChannel::query()->count())->toBe(0);

    $live = $action->handle(InstitutionData::validateAndCreate(validInstitutionGraphPayload($geo)));

    InstitutionImportExclusion::query()->create([
        'source' => 'masjid-csv',
        'external_ref' => 'graph-1',
        'deleted_at' => now(),
    ]);

    $edited = $action->handle(InstitutionData::validateAndCreate(
        validInstitutionGraphPayload($geo, ['name' => 'Reviewed Edit'])
    ), $live);

    expect($edited->name)->toBe('Reviewed Edit');
});

it('preserves a supplied opaque slug when creating a source-null graph row', function () {
    $geo = seedInstitutionGraphGeography();
    $action = app(ImportInstitutionGraphAction::class);

    $create = validInstitutionGraphPayload($geo, ['slug' => 'curated-opaque-slug-7x']);
    unset($create['source'], $create['external_ref']);

    $institution = $action->handle(InstitutionData::validateAndCreate($create));

    // The returned model is fresh inside the transaction; deferred observer
    // regenerations run after commit, so only a refreshed read proves the
    // curated bytes survived them.
    $fresh = $institution->refresh();

    expect($fresh->slug)->toBe('curated-opaque-slug-7x')
        ->and($fresh->name)->toBe("Masjid Al-'Aliatul")
        ->and($fresh->getAttribute('source'))->toBeNull()
        ->and($fresh->getAttribute('external_ref'))->toBeNull()
        ->and($fresh->primaryAddress()?->line1)->toBe('Jalan Graph 1');
});

it('preserves the supplied slug when updating a source-null graph name and address', function () {
    $geo = seedInstitutionGraphGeography();
    $action = app(ImportInstitutionGraphAction::class);

    $create = validInstitutionGraphPayload($geo, ['slug' => 'curated-opaque-slug-7x']);
    unset($create['source'], $create['external_ref']);

    $institution = $action->handle(InstitutionData::validateAndCreate($create));

    $update = validInstitutionGraphPayload($geo, [
        'name' => 'Masjid Curated Renamed',
        'slug' => 'curated-opaque-slug-7x',
        'address' => ['line1' => 'Jalan Graph 2', 'city' => 'Subang Jaya'],
    ]);
    unset($update['source'], $update['external_ref']);

    $updated = $action->handle(InstitutionData::validateAndCreate($update), $institution);
    $fresh = $updated->refresh();

    expect($fresh->slug)->toBe('curated-opaque-slug-7x')
        ->and($fresh->name)->toBe('Masjid Curated Renamed')
        ->and($fresh->getAttribute('source'))->toBeNull()
        ->and($fresh->getAttribute('external_ref'))->toBeNull()
        ->and($fresh->primaryAddress()?->line1)->toBe('Jalan Graph 2');
});

it('keeps a source-null graph slug under a real outer transaction', function () {
    $geo = seedInstitutionGraphGeography();

    $create = validInstitutionGraphPayload($geo, ['slug' => 'curated-opaque-slug-7x']);
    unset($create['source'], $create['external_ref']);

    $data = InstitutionData::validateAndCreate($create);

    $result = DB::transaction(fn () => app(ImportInstitutionGraphAction::class)->handle($data));

    // The returned model is what API responses serialize: deferred observer
    // regenerations run on the outer commit, but the graph transaction
    // protects its institution ID in the current transaction record, so
    // the shared generator skips the row and the requested curated bytes
    // are never regenerated in the result or the persisted row.
    expect($result)->toBeInstanceOf(Institution::class)
        ->and($result->slug)->toBe('curated-opaque-slug-7x')
        ->and($result->refresh()->slug)->toBe('curated-opaque-slug-7x')
        ->and($result->name)->toBe("Masjid Al-'Aliatul")
        ->and($result->getAttribute('source'))->toBeNull()
        ->and($result->getAttribute('external_ref'))->toBeNull();
});

it('preserves two same-name curated slugs written in one outer transaction', function () {
    $geo = seedInstitutionGraphGeography();

    $first = validInstitutionGraphPayload($geo, ['slug' => 'curated-first-graph-aa']);
    unset($first['source'], $first['external_ref']);

    $second = validInstitutionGraphPayload($geo, ['slug' => 'curated-second-graph-bb']);
    unset($second['source'], $second['external_ref']);

    [$firstResult, $secondResult] = DB::transaction(fn () => [
        app(ImportInstitutionGraphAction::class)->handle(InstitutionData::validateAndCreate($first)),
        app(ImportInstitutionGraphAction::class)->handle(InstitutionData::validateAndCreate($second)),
    ]);

    // Both rows share the fixture name and locality, so unprotected
    // same-name regeneration would rewrite both at the outer commit; each
    // graph write protects its own ID until every observer has run.
    expect($firstResult->refresh()->slug)->toBe('curated-first-graph-aa')
        ->and($secondResult->refresh()->slug)->toBe('curated-second-graph-bb')
        ->and($firstResult->name)->toBe($secondResult->name)
        ->and($firstResult->getAttribute('source'))->toBeNull()
        ->and($secondResult->getAttribute('source'))->toBeNull();
});

it('resumes ordinary slug generation for explicit updates after the graph transaction commits', function () {
    $geo = seedInstitutionGraphGeography();
    $action = app(ImportInstitutionGraphAction::class);

    $create = validInstitutionGraphPayload($geo, ['slug' => 'curated-opaque-slug-7x']);
    unset($create['source'], $create['external_ref']);

    $institution = $action->handle(InstitutionData::validateAndCreate($create));

    expect($institution->refresh()->slug)->toBe('curated-opaque-slug-7x');

    // Outside the completed graph transaction the intent is gone: an
    // ordinary name save regenerates through the real observers.
    $institution->update(['name' => 'Masjid Curated Renamed']);

    expect($institution->refresh()->slug)->toBe('masjid-curated-renamed-petaling-jaya-petaling-selangor-my');

    // Locality edits regenerate too.
    $institution->primaryAddress()?->update(['city' => 'Subang Jaya']);

    expect($institution->refresh()->slug)->toBe('masjid-curated-renamed-subang-jaya-petaling-selangor-my');
});

it('releases slug intent when the outer transaction rolls back', function () {
    $geo = seedInstitutionGraphGeography();
    $manual = createManualSourceNullInstitution($geo, 'Masjid Kariah Manual');

    expect($manual->slug)->toBe('masjid-kariah-manual-petaling-jaya-petaling-selangor-my')
        ->and($manual->getAttribute('source'))->toBeNull();

    $update = validInstitutionGraphPayload($geo, [
        'name' => 'Masjid Kariah Rolled Back',
        'slug' => 'curated-rolled-back-x1',
        'address' => ['line1' => 'Jalan Rollback 9'],
    ]);
    unset($update['source'], $update['external_ref']);

    DB::beginTransaction();
    app(ImportInstitutionGraphAction::class)->handle(InstitutionData::validateAndCreate($update), $manual);
    DB::rollBack();

    // The rolled-back graph write leaves no partial state behind.
    $fresh = $manual->refresh();

    expect($fresh->name)->toBe('Masjid Kariah Manual')
        ->and($fresh->slug)->toBe('masjid-kariah-manual-petaling-jaya-petaling-selangor-my')
        ->and($fresh->primaryAddress()?->line1)->toBe('Jalan Manual 1')
        ->and($fresh->names()->count())->toBe(0)
        ->and($fresh->donationChannels()->count())->toBe(0);

    // With the intent released, an ordinary save regenerates normally.
    $fresh->update(['name' => 'Masjid Kariah Pulih']);

    expect($fresh->refresh()->slug)->toBe('masjid-kariah-pulih-petaling-jaya-petaling-selangor-my');
});

it('releases inner savepoint intent while the outer transaction commits', function () {
    $geo = seedInstitutionGraphGeography();
    $manual = createManualSourceNullInstitution($geo, 'Masjid Kariah Savepoint');

    expect($manual->slug)->toBe('masjid-kariah-savepoint-petaling-jaya-petaling-selangor-my');

    $update = validInstitutionGraphPayload($geo, [
        'name' => 'Masjid Kariah Savepoint Draft',
        'slug' => 'curated-savepoint-draft-x1',
        'address' => ['line1' => 'Jalan Savepoint 3'],
    ]);
    unset($update['source'], $update['external_ref']);

    $data = InstitutionData::validateAndCreate($update);
    $rolledBack = false;

    DB::transaction(function () use ($data, $manual, &$rolledBack): void {
        try {
            DB::transaction(function () use ($data, $manual): void {
                app(ImportInstitutionGraphAction::class)->handle($data, $manual);

                throw new RuntimeException('savepoint probe');
            });
        } catch (RuntimeException $exception) {
            $rolledBack = $exception->getMessage() === 'savepoint probe';
        }
    });

    // The savepoint write is undone but the outer transaction commits, so
    // the row keeps its original bytes and only the inner intent is gone.
    expect($rolledBack)->toBeTrue();

    $fresh = $manual->refresh();

    expect($fresh->name)->toBe('Masjid Kariah Savepoint')
        ->and($fresh->slug)->toBe('masjid-kariah-savepoint-petaling-jaya-petaling-selangor-my')
        ->and($fresh->primaryAddress()?->line1)->toBe('Jalan Manual 1');

    $fresh->update(['name' => 'Masjid Kariah Pulih']);

    expect($fresh->refresh()->slug)->toBe('masjid-kariah-pulih-petaling-jaya-petaling-selangor-my');
});

/**
 * Ordinary manual row with a deterministic generated slug.
 *
 * Direct model creation, not the factory: the factory attaches a random
 * faker address, which would make the generated slug non-deterministic.
 *
 * @param  array<string, mixed>  $geo
 */
function createManualSourceNullInstitution(array $geo, string $name): Institution
{
    $institution = Institution::query()->create([
        'type' => 'masjid',
        'name' => $name,
        'slug' => Str::slug($name),
        'status' => InstitutionStatus::Verified,
    ]);

    $address = Address::query()->create([
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
        'country_code' => (string) $geo['country']->iso2,
        'country' => (string) $geo['country']->name,
        'state' => (string) $geo['state']->name,
        'city' => 'Petaling Jaya',
        'line1' => 'Jalan Manual 1',
        'postcode' => '47800',
    ]);

    app(SyncAddressAreaAssignmentsAction::class)->execute($address, [
        'administrative_district' => (string) $geo['district']->getKey(),
        'administrative_subdivision' => (string) $geo['subdistrict']->getKey(),
    ], (string) $geo['state']->getKey());

    $institution->attachAddress($address, type: 'primary', isPrimary: true);

    return $institution->refresh();
}

/**
 * @return array{country: mixed, state: mixed, district: AddressArea, subdistrict: AddressArea}
 */
function seedInstitutionGraphGeography(): array
{
    $country = ensureTestMalaysiaCountry();

    $geo = createTestPackageGeography(
        stateName: 'Selangor',
        districtName: 'Petaling',
        subdistrictName: 'Damansara',
        country: $country,
    );

    return [
        'country' => $country,
        'state' => $geo['state'],
        'district' => $geo['district'],
        'subdistrict' => $geo['subdistrict'],
    ];
}

/**
 * Graph-list overrides replace wholesale so an override can narrow or drop
 * donor rows; only the address merges field-by-field.
 *
 * @param  array<string, mixed>  $geo
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validInstitutionGraphPayload(array $geo, array $overrides = []): array
{
    $payload = [
        'name' => "Masjid Al-'Aliatul",
        'slug' => 'masjid-al-aliatul-graph-1',
        'type' => 'masjid',
        'status' => InstitutionStatus::Verified->value,
        'source' => 'masjid-csv',
        'external_ref' => 'graph-1',
        'description' => 'Graph fixture masjid.',
        'names' => [
            ['full_name' => 'Masjid Al-Aliatul Jamek', 'name_type' => 'official', 'is_primary' => true],
            ['full_name' => 'MAA', 'name_type' => 'abbreviation'],
        ],
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'state_id' => (string) $geo['state']->getKey(),
            'line1' => 'Jalan Graph 1',
            'city' => 'Petaling Jaya',
            'state' => 'Selangor',
            'postcode' => '47800',
            'latitude' => 3.1147,
            'longitude' => 101.6118,
            'area_assignments' => [
                'administrative_district' => (string) $geo['district']->getKey(),
                'administrative_subdivision' => (string) $geo['subdistrict']->getKey(),
            ],
        ],
        'contact_methods' => [
            ['type' => 'phone', 'value' => '+6031234567', 'purpose' => 'general', 'is_public' => true],
        ],
        'social_profiles' => [
            ['platform' => 'facebook', 'url' => 'https://facebook.example/masjid', 'is_public' => true],
        ],
        'donation_channels' => [
            [
                'method' => 'bank_account',
                'recipient' => 'Tabung Masjid',
                'bank_name' => 'Bank Islam',
                'account_number' => '1234567890',
                'is_default' => true,
                'status' => DonationChannelStatus::Verified->value,
            ],
        ],
    ];

    foreach ($overrides as $key => $value) {
        if ($key === 'address' && is_array($value) && isset($payload[$key]) && is_array($payload[$key])) {
            $payload[$key] = array_replace_recursive($payload[$key], $value);

            continue;
        }

        $payload[$key] = $value;
    }

    return $payload;
}
