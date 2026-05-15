<?php

use App\Models\Institution;

it('keeps only one default donation channel per owner when creating a new default', function () {
    $institution = Institution::factory()->create();

    $firstDefault = $institution->donationChannels()->create([
        'recipient' => 'Masjid Account A',
        'method' => 'bank_account',
        'bank_name' => 'Maybank',
        'bank_code' => 'MBB',
        'account_number' => '1234567890',
        'status' => 'verified',
        'is_default' => true,
    ]);

    $newDefault = $institution->donationChannels()->create([
        'recipient' => 'Masjid Account B',
        'method' => 'duitnow',
        'duitnow_type' => 'mobile',
        'duitnow_value' => '0123456789',
        'status' => 'verified',
        'is_default' => true,
    ]);

    expect($firstDefault->fresh()->is_default)->toBeFalse();
    expect($newDefault->fresh()->is_default)->toBeTrue();
    expect(
        $institution->donationChannels()->where('is_default', true)->count()
    )->toBe(1);
});

it('keeps only one default donation channel per owner when updating an existing channel to default', function () {
    $institution = Institution::factory()->create();

    $firstDefault = $institution->donationChannels()->create([
        'recipient' => 'Masjid Account A',
        'method' => 'bank_account',
        'bank_name' => 'Maybank',
        'bank_code' => 'MBB',
        'account_number' => '1234567890',
        'status' => 'verified',
        'is_default' => true,
    ]);

    $otherChannel = $institution->donationChannels()->create([
        'recipient' => 'Masjid Account B',
        'method' => 'ewallet',
        'ewallet_provider' => 'tng',
        'ewallet_handle' => '0123456789',
        'status' => 'verified',
        'is_default' => false,
    ]);

    $otherChannel->update(['is_default' => true]);

    expect($firstDefault->fresh()->is_default)->toBeFalse();
    expect($otherChannel->fresh()->is_default)->toBeTrue();
    expect(
        $institution->donationChannels()->where('is_default', true)->count()
    )->toBe(1);
});
