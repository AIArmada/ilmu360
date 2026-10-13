<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaAssignment;
use App\Support\Location\AddressAssignments;
use App\Support\Location\AddressHierarchyFormatter;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<array{string, string, string|null}>  $areas  role, name, type
 */
function hierarchyTestAddress(array $attributes, array $areas = []): Address
{
    $address = new Address;
    $address->forceFill($attributes);

    $assignments = new Collection;

    foreach ($areas as [$role, $name, $type]) {
        $area = new AddressArea;
        $area->forceFill(['name' => $name, 'type' => $type]);

        $assignment = new AddressAreaAssignment;
        $assignment->forceFill([
            'role' => $role,
            'is_primary' => true,
            'address_area_id' => 'area-'.$role,
        ]);
        $assignment->setRelation('area', $area);

        $assignments->push($assignment);
    }

    $address->setRelation('areaAssignments', $assignments);

    return $address;
}

function baganTestAddress(array $overrides = []): Address
{
    return hierarchyTestAddress(array_merge([
        'line1' => '226, Parit Moin 1, Bagan',
        'city' => 'Bandar Penggaram',
        'state' => 'Johor',
        'postcode' => '83000',
        'country_code' => 'MY',
    ], $overrides), [
        [AddressAssignments::ADMINISTRATIVE_SUBDIVISION, 'Mukim Bagan', 'mukim'],
        [AddressAssignments::ADMINISTRATIVE_DISTRICT, 'Batu Pahat', 'daerah'],
        [AddressAssignments::POSTAL_LOCALITY, 'Bagan', 'lokaliti'],
    ]);
}

it('derives MY one-liners from the package renderer, never the postal town', function (): void {
    expect(AddressHierarchyFormatter::parts(baganTestAddress()))->toBe(['Bagan', 'Batu Pahat', 'Johor'])
        ->and(AddressHierarchyFormatter::format(baganTestAddress()))->toBe('Bagan, Batu Pahat, Johor')
        ->and(AddressHierarchyFormatter::formatShort(baganTestAddress()))->toBe('Batu Pahat, Johor');
});

it('leads with the mukim when no locality assignment exists', function (): void {
    // Live 4511 shape: district + mukim subdivision, no postal_locality.
    $address = hierarchyTestAddress([
        'line1' => 'Pt Hj Ibrahim, Bagan Mukim 2',
        'city' => 'Bandar Penggaram',
        'state' => 'Johor',
        'postcode' => '83000',
        'country_code' => 'MY',
    ], [
        [AddressAssignments::ADMINISTRATIVE_SUBDIVISION, 'Bagan', 'mukim'],
        [AddressAssignments::ADMINISTRATIVE_DISTRICT, 'Batu Pahat', 'daerah'],
    ]);

    expect(AddressHierarchyFormatter::parts($address))->toBe(['Bagan', 'Batu Pahat', 'Johor']);
});

it('collapses urban summaries to town and state', function (): void {
    $address = hierarchyTestAddress([
        'line1' => '2, Jalan Gunung Nuang U11/9D',
        'line2' => 'Taman Bukit Bandaraya, Seksyen U11',
        'city' => 'Shah Alam',
        'state' => 'Selangor',
        'postcode' => '40170',
        'country_code' => 'MY',
    ], [
        [AddressAssignments::ADMINISTRATIVE_SUBDIVISION, 'Shah Alam', 'bandar'],
        [AddressAssignments::ADMINISTRATIVE_DISTRICT, 'Petaling', 'daerah'],
        [AddressAssignments::POSTAL_LOCALITY, 'Seksyen U11', 'lokaliti'],
    ]);

    expect(AddressHierarchyFormatter::parts($address))->toBe(['Shah Alam', 'Selangor']);
});

it('prefers live assignments over stale stored components', function (): void {
    $address = baganTestAddress(['components' => ['locality' => null, 'postcode_partner' => 'Bandar Penggaram']]);

    expect(AddressHierarchyFormatter::parts($address))->toBe(['Bagan', 'Batu Pahat', 'Johor']);
});

it('renders display lines from the ruled package block', function (): void {
    expect(AddressHierarchyFormatter::displayLines(baganTestAddress()))->toBe([
        'street' => '226, Parit Moin 1, Bagan',
        'locality' => '83000, Batu Pahat',
        'regional' => 'Batu Pahat, Johor',
    ]);
});

it('leads display lines with the finest part when no postcode exists', function (): void {
    $address = baganTestAddress(['postcode' => null]);

    expect(AddressHierarchyFormatter::displayLines($address))->toBe([
        'street' => '226, Parit Moin 1, Bagan',
        'locality' => 'Bagan',
        'regional' => 'Batu Pahat, Johor',
    ]);
});

it('summarizes other countries through the same package fallback', function (): void {
    $address = hierarchyTestAddress([
        'line1' => '100 Main St',
        'city' => 'Springfield',
        'state' => 'Illinois',
        'postcode' => '62701',
        'country_code' => 'US',
    ]);

    expect(AddressHierarchyFormatter::parts($address))->toBe(['Springfield', 'IL']);
});

it('keeps the street lines when no country code resolves', function (): void {
    $address = hierarchyTestAddress(['line1' => '12, Jalan Kenanga', 'line2' => 'Taman Kenanga']);

    expect(AddressHierarchyFormatter::displayLines($address))->toBe([
        'street' => '12, Jalan Kenanga, Taman Kenanga',
        'locality' => null,
        'regional' => null,
    ]);
});

it('keeps the street lines when the country definition is unknown', function (): void {
    $address = hierarchyTestAddress(['line1' => '1 Nowhere Road', 'country' => 'Nowhere', 'country_code' => 'XX']);

    expect(AddressHierarchyFormatter::displayLines($address))->toBe([
        'street' => '1 Nowhere Road',
        'locality' => null,
        'regional' => null,
    ]);
});

it('renders the full package block as one line', function (): void {
    expect(AddressHierarchyFormatter::formatBlock(baganTestAddress()))
        ->toBe('226, Parit Moin 1, Bagan, 83000, Batu Pahat, Johor, Malaysia');
});

it('renders the block empty when no definition applies', function (): void {
    $address = hierarchyTestAddress(['country' => 'Nowhere', 'country_code' => 'XX']);

    expect(AddressHierarchyFormatter::formatBlock($address))->toBe('')
        ->and(AddressHierarchyFormatter::formatBlock(null))->toBe('');
});

it('falls back to the country when no definition applies', function (): void {
    $address = hierarchyTestAddress(['country' => 'Nowhere', 'country_code' => 'XX']);

    expect(AddressHierarchyFormatter::parts($address))->toBe(['Nowhere'])
        ->and(AddressHierarchyFormatter::parts(null))->toBe([])
        ->and(AddressHierarchyFormatter::format(null))->toBe('');
});
